<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;

class DatabaseBackupService
{
    /**
     * Urutan tabel aplikasi agar relasi foreign key tetap rapi saat export/restore.
     */
    private const TABLES = [
        'users',
        'settings',
        'categories',
        'products',
        'product_units',
        'customers',
        'cashier_shifts',
        'cash_movements',
        'transactions',
        'transaction_items',
        'debt_payments',
        'hold_orders',
    ];

    public function backupDir(): string
    {
        $dir = storage_path('app/backups');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public function sanitizeFilename(string $filename): string
    {
        $base = basename($filename);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $base)) {
            throw new RuntimeException('Nama file backup tidak valid.');
        }

        return $base;
    }

    public function listBackups(): array
    {
        $dir = $this->backupDir();
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.{json,sqlite,db}', GLOB_BRACE) ?: [];

        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return array_map(function (string $path) {
            $name = basename($path);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $sizeBytes = filesize($path) ?: 0;

            return [
                'filename' => $name,
                'type' => $ext === 'json' ? 'JSON Portable' : 'SQLite DB',
                'size_bytes' => $sizeBytes,
                'size_human' => $this->formatBytes($sizeBytes),
                'created_at' => date('d M Y H:i:s', filemtime($path) ?: time()),
            ];
        }, $files);
    }

    public function createJsonBackup(string $prefix = 'backup-pos'): array
    {
        $tablesData = [];
        $totalRows = 0;

        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            $tablesData[$table] = $rows;
            $totalRows += count($rows);
        }

        $payload = [
            'app' => 'tpkmart-pos',
            'version' => 1,
            'driver' => DB::getDriverName(),
            'created_at' => now()->toIso8601String(),
            'tables' => $tablesData,
        ];

        $filename = sprintf('%s-%s.json', $prefix, now()->format('Y-m-d_H-i-s'));
        $fullPath = $this->backupDir() . DIRECTORY_SEPARATOR . $filename;

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Gagal mengonversi data database ke format JSON.');
        }

        file_put_contents($fullPath, $json);

        return [
            'filename' => $filename,
            'path' => $fullPath,
            'tables_count' => count($tablesData),
            'total_rows' => $totalRows,
            'size_human' => $this->formatBytes(filesize($fullPath) ?: 0),
        ];
    }

    public function storeUploadedBackup(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['json', 'sqlite', 'db'], true)) {
            throw new RuntimeException('Format file harus .json, .sqlite, atau .db.');
        }

        $tmpPath = $file->getRealPath();
        if (!$tmpPath || !is_file($tmpPath)) {
            throw new RuntimeException('File unggahan tidak terbaca oleh server.');
        }

        if ($ext === 'json') {
            $this->parseAndValidateJson($tmpPath);
        } else {
            $this->validateSqliteFile($tmpPath);
        }

        $rawBase = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '-', $rawBase) ?: 'uploaded-backup';
        $safeBase = trim($safeBase, '-');
        $filename = sprintf('upload-%s-%s.%s', substr($safeBase, 0, 40), now()->format('Y-m-d_H-i-s'), $ext);
        $targetPath = $this->backupDir() . DIRECTORY_SEPARATOR . $filename;

        if (!copy($tmpPath, $targetPath)) {
            throw new RuntimeException('Gagal menyimpan file backup ke server.');
        }

        return [
            'filename' => $filename,
            'path' => $targetPath,
            'ext' => $ext,
        ];
    }

    public function restoreFromFilename(string $filename): array
    {
        $safeName = $this->sanitizeFilename($filename);
        $path = $this->backupDir() . DIRECTORY_SEPARATOR . $safeName;
        if (!is_file($path)) {
            throw new RuntimeException('File backup tidak ditemukan di server.');
        }

        // Buat snapshot pengaman otomatis sebelum menimpa database aktif
        $this->createJsonBackup('auto-before-restore');

        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if ($ext === 'json') {
            return $this->restoreFromJsonPath($path);
        }

        if (in_array($ext, ['sqlite', 'db'], true)) {
            return $this->restoreFromSqlitePath($path);
        }

        throw new RuntimeException('Format file backup tidak didukung untuk restore.');
    }

    public function deleteBackup(string $filename): void
    {
        $safeName = $this->sanitizeFilename($filename);
        $path = $this->backupDir() . DIRECTORY_SEPARATOR . $safeName;
        if (!is_file($path)) {
            throw new RuntimeException('File backup tidak ditemukan.');
        }
        unlink($path);
    }

    public function getBackupPath(string $filename): string
    {
        $safeName = $this->sanitizeFilename($filename);
        $path = $this->backupDir() . DIRECTORY_SEPARATOR . $safeName;
        if (!is_file($path)) {
            throw new RuntimeException('File backup tidak ditemukan.');
        }

        return $path;
    }

    private function parseAndValidateJson(string $path): array
    {
        $raw = file_get_contents($path);
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || ($data['app'] ?? null) !== 'tpkmart-pos' || !is_array($data['tables'] ?? null)) {
            throw new RuntimeException('File JSON bukan format backup POS yang valid.');
        }

        return $data;
    }

    private function validateSqliteFile(string $path): void
    {
        $fh = fopen($path, 'rb');
        $header = $fh ? fread($fh, 16) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($header !== "SQLite format 3\0") {
            throw new RuntimeException('File bukan database SQLite yang valid.');
        }

        $pdo = new PDO('sqlite:' . $path);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['users', 'products', 'settings'] as $required) {
            if (!in_array($required, $tables, true)) {
                throw new RuntimeException("File SQLite tidak memiliki tabel wajib '{$required}'.");
            }
        }
    }

    private function restoreFromJsonPath(string $path): array
    {
        $data = $this->parseAndValidateJson($path);
        $tables = $data['tables'];
        $driver = DB::getDriverName();
        $restoredTables = 0;
        $restoredRows = 0;

        $this->setForeignKeys($driver, false);

        try {
            DB::transaction(function () use ($tables, &$restoredTables, &$restoredRows) {
                // Hapus dari tabel anak ke induk
                foreach (array_reverse(self::TABLES) as $table) {
                    if (Schema::hasTable($table) && array_key_exists($table, $tables)) {
                        DB::table($table)->delete();
                    }
                }

                // Masukkan kembali dari tabel induk ke anak dalam chunk aman
                foreach (self::TABLES as $table) {
                    if (!Schema::hasTable($table) || empty($tables[$table]) || !is_array($tables[$table])) {
                        continue;
                    }

                    $columns = Schema::getColumnListing($table);
                    $allowed = array_flip($columns);
                    $cleanRows = [];

                    foreach ($tables[$table] as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $filtered = array_intersect_key($row, $allowed);
                        if (!empty($filtered)) {
                            $cleanRows[] = $filtered;
                        }
                    }

                    foreach (array_chunk($cleanRows, 100) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }

                    $restoredTables++;
                    $restoredRows += count($cleanRows);
                }
            });
        } finally {
            $this->setForeignKeys($driver, true);
        }

        Artisan::call('optimize:clear');

        return [
            'mode' => 'json',
            'restored_tables' => $restoredTables,
            'restored_rows' => $restoredRows,
        ];
    }

    private function restoreFromSqlitePath(string $path): array
    {
        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Server menggunakan MySQL. Untuk restore ke MySQL, gunakan file backup format .json.');
        }

        $this->validateSqliteFile($path);

        $targetDb = config('database.connections.sqlite.database');
        if (!$targetDb || $targetDb === ':memory:') {
            throw new RuntimeException('Lokasi file database SQLite aktif tidak valid.');
        }

        DB::disconnect();

        if (!copy($path, $targetDb)) {
            DB::reconnect();
            throw new RuntimeException('Gagal mengganti file database SQLite aktif.');
        }

        // Hapus file WAL/SHM lama jika ada agar tidak bentrok dengan file database baru
        foreach ([$targetDb . '-wal', $targetDb . '-shm'] as $sidecar) {
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }

        DB::reconnect();
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('optimize:clear');

        return [
            'mode' => 'sqlite',
            'restored_tables' => count(self::TABLES),
            'restored_rows' => null,
        ];
    }

    private function setForeignKeys(string $driver, bool $enabled): void
    {
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF'));
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('SET FOREIGN_KEY_CHECKS=' . ($enabled ? '1' : '0'));
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / 1048576, 2) . ' MB';
    }
}

