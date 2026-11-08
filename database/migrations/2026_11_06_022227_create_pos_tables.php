<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Kolom Gambar di Tabel Users (Kasir / Karyawan)
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'image')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('image')->nullable()->after('email');
            });
        }

        // 2. Tabel Settings (Pengaturan Toko & Saklar Fitur On/Off)
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // 3. Tabel Kategori Barang
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon')->default('📦');
            $table->string('image')->nullable(); // Gambar Kategori
            $table->timestamps();
        });

        // 4. Tabel Master Produk
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('barcode')->unique();
            $table->string('name');
            $table->string('icon')->default('📦');
            $table->string('image')->nullable(); // Kolom Gambar Produk (siap dipakai kapan saja)
            $table->string('base_unit')->default('Pcs'); // Satuan dasar fisik stok
            $table->integer('stock')->default(0); // Stok fisik (aktif bila saklar stok ON)
            $table->integer('min_stock')->default(0); // Batas peringatan stok tipis
            $table->integer('cost_price')->default(0); // HPP / Harga modal per satuan dasar
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 5. Tabel Pilihan Multi-Satuan & Harga Jual
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Pcs, Bungkus, Sachet, Renceng, Dus, Slop, Karung
            $table->integer('price')->default(0); // Harga jual satuan ini
            $table->integer('ratio')->default(1); // Konversi ke satuan dasar (misal 1 Dus = 40)
            $table->boolean('is_base')->default(false); // Apakah satuan dasar
            $table->timestamps();
        });

        // 6. Tabel Master Pelanggan / Kasbon
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('image')->nullable(); // Kolom Gambar / Foto Pelanggan
            $table->integer('credit_limit')->default(0); // Limit maksimal kasbon
            $table->integer('kasbon_balance')->default(0); // Saldo kasbon yang belum dibayar
            $table->timestamps();
        });

        // 7. Tabel Shift Kasir (Buku Kas Karyawan - Aktif saat Saklar Shift ON)
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cashier_name');
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();
            $table->integer('starting_cash')->default(0); // Uang modal awal di laci
            $table->integer('expected_cash')->default(0); // Modal awal + Penjualan tunai - Kas keluar
            $table->integer('actual_cash')->default(0); // Uang fisik dihitung saat tutup
            $table->integer('difference')->default(0); // Selisih uang kas
            $table->string('status')->default('OPEN'); // OPEN, CLOSED
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 8. Tabel Arus Uang Kas Laci (Kas Masuk / Kas Keluar Operasional Kasir)
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->nullable()->constrained('cashier_shifts')->nullOnDelete();
            $table->string('type'); // CASH_IN, CASH_OUT
            $table->integer('amount')->default(0);
            $table->string('reason'); // Keterangan (misal: Beli Es Batu, Bayar Galon, Setoran)
            $table->timestamps();
        });

        // 9. Tabel Transaksi Penjualan (Header Nota)
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->nullable()->constrained('cashier_shifts')->nullOnDelete();
            $table->string('uuid')->unique(); // UUID client tablet untuk sinkronisasi offline-first
            $table->string('invoice_no')->unique(); // Nomor struk (TRX-0012)
            $table->string('cashier_name')->default('Kasir Toko');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name')->default('Pelanggan Umum');
            $table->integer('subtotal')->default(0);
            $table->integer('item_discount')->default(0);
            $table->integer('global_discount')->default(0);
            $table->string('global_discount_note')->nullable();
            $table->integer('grand_total')->default(0);
            $table->integer('total_cost')->default(0); // Total HPP saat transaksi (hitung laba kotor)
            $table->string('payment_method')->default('TUNAI'); // TUNAI, QRIS, KASBON, DEBIT, TRANSFER
            $table->integer('paid_amount')->default(0);
            $table->integer('change_amount')->default(0);
            $table->date('due_date')->nullable(); // Jatuh tempo kasbon (opsional)
            $table->string('status')->default('PAID'); // PAID, CANCELLED
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        // 10. Tabel Detail Item Belanjaan Transaksi
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name');
            $table->string('unit_name'); // Satuan yang dibeli saat itu (Pcs, Dus, dll.)
            $table->integer('unit_price'); // Harga per satuan saat itu
            $table->integer('cost_price')->default(0); // HPP per satuan saat transaksi
            $table->integer('ratio')->default(1); // Pengali satuan dasar untuk potong stok
            $table->integer('qty')->default(1);
            $table->integer('discount')->default(0);
            $table->integer('total');
            $table->timestamps();
        });

        // 11. Tabel Riwayat Pembayaran / Cicilan Kasbon
        Schema::create('debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->integer('amount');
            $table->string('payment_method')->default('TUNAI'); // TUNAI, TRANSFER
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        // 12. Tabel Antrean Belanja Sementara (Hold Orders)
        Schema::create('hold_orders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hold_orders');
        Schema::dropIfExists('debt_payments');
        Schema::dropIfExists('transaction_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cashier_shifts');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('settings');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'image')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('image');
            });
        }
    }
};
