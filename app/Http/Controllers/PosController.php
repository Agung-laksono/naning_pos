<?php

namespace App\Http\Controllers;

use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DebtPayment;
use App\Models\HoldOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    /**
     * Hitung Rekapitulasi Keuangan Kasir yang Komprehensif
     * Menghitung Omzet Belanja, Kas Fisik Laci, Top-Up/PPOB, Uang Digital QRIS, Kembalian Kas QRIS, dan Jasa Tarik Tunai
     */
    protected function buildFinancialSummary($transactions, bool $isAdmin): array
    {
        $salesTransactions = $transactions->whereNotIn('transaction_type', ['CASH_WITHDRAWAL', 'TOP_UP']);
        $topUpTransactions = $transactions->where('transaction_type', 'TOP_UP');
        $qrisTransactions = $transactions->where('payment_method', 'QRIS');

        $cashSales = (int) $salesTransactions->where('payment_method', 'TUNAI')->sum('grand_total');
        $qrisSales = (int) $salesTransactions->where('payment_method', 'QRIS')->sum('grand_total');
        $kasbonSales = (int) $salesTransactions->where('payment_method', 'KASBON')->sum('grand_total')
            + (int) $topUpTransactions->where('payment_method', 'KASBON')->sum('grand_total');
        $totalSales = (int) $salesTransactions->sum('grand_total');

        $topUpCashIn = (int) $topUpTransactions->where('payment_method', 'TUNAI')->sum('grand_total');
        $topUpTotal = (int) $topUpTransactions->sum('grand_total');
        $topUpCapitalOut = (int) $topUpTransactions->sum('total_cost');
        $topUpCount = $topUpTransactions->count();

        $qrisSettlement = (int) $qrisTransactions->sum('paid_amount');
        $qrisCashOut = (int) $qrisTransactions->sum('change_amount');
        $qrisWithdrawalCount = $transactions->where('transaction_type', 'CASH_WITHDRAWAL')->count();
        $totalAdminFee = (int) $transactions->sum('admin_fee');

        // Kas Fisik di Laci = Penjualan Tunai + Tunai Masuk Top-Up - Kas Keluar untuk Kembalian/Tarik Tunai QRIS
        $estimatedCashInDrawer = $cashSales + $topUpCashIn - $qrisCashOut;

        $salesProfit = $totalSales - (int) $salesTransactions->sum('total_cost');
        $topUpProfit = $topUpTotal - $topUpCapitalOut;
        $nonTopUpAdminFee = (int) $transactions->where('transaction_type', '!=', 'TOP_UP')->sum('admin_fee');
        $totalProfit = $isAdmin ? ($salesProfit + $topUpProfit + $nonTopUpAdminFee) : 0;

        return [
            'total_sales' => $totalSales,
            'cash_sales' => $cashSales,
            'qris_sales' => $qrisSales,
            'kasbon_sales' => $kasbonSales,
            'topup_cash_in' => $topUpCashIn,
            'topup_total' => $topUpTotal,
            'topup_capital_out' => $topUpCapitalOut,
            'topup_count' => $topUpCount,
            'transaction_count' => $transactions->count(),
            'qris_settlement' => $qrisSettlement,
            'qris_cash_out' => $qrisCashOut,
            'qris_withdrawal_count' => $qrisWithdrawalCount,
            'total_admin_fee' => $totalAdminFee,
            'estimated_cash_drawer' => $estimatedCashInDrawer,
            'total_profit' => $totalProfit,
            'total_receivable' => (int) Customer::sum('kasbon_balance'),
        ];
    }

    /**
     * Tampilan Utama POS Kasir Tablet Android (Inertia + Vue 3)
     */
    public function index(): Response
    {
        $user = auth()->user();
        $isAdmin = $user?->isAdmin() ?? false;
        $canViewSummary = $isAdmin || Setting::where('key', 'cashier_can_view_financial_summary')->value('value') === 'true';

        $products = Product::with(['units', 'category'])->latest()->get();
        if (!$isAdmin) {
            $products->each->makeHidden(['cost_price']);
        }

        $customers = Customer::orderByRaw("CASE WHEN name LIKE '%Umum%' OR id = 1 THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();
        $categories = Category::all();
        $settings = Setting::pluck('value', 'key');
        if (!$isAdmin) {
            $settings->forget(['midtrans_server_key']);
        }
        $holdOrders = HoldOrder::latest()->get();

        $today = now()->startOfDay();
        $recentTransactions = Transaction::with('items')->latest()->take(60)->get();
        $todayTransactions = Transaction::where('created_at', '>=', $today)->where('status', 'PAID')->get();
        $activeShift = CashierShift::with('movements')->where('status', 'OPEN')->latest()->first();

        $financialSummary = $canViewSummary
            ? $this->buildFinancialSummary($todayTransactions, $isAdmin)
            : [
                'total_sales' => 0,
                'cash_sales' => 0,
                'qris_sales' => 0,
                'kasbon_sales' => 0,
                'topup_cash_in' => 0,
                'topup_total' => 0,
                'topup_capital_out' => 0,
                'topup_count' => 0,
                'transaction_count' => $todayTransactions->count(),
                'qris_settlement' => 0,
                'qris_cash_out' => 0,
                'qris_withdrawal_count' => 0,
                'total_admin_fee' => 0,
                'estimated_cash_drawer' => 0,
                'total_profit' => 0,
                'total_receivable' => 0,
            ];

        return Inertia::render('POS/Index', [
            'products' => $products,
            'customers' => $customers,
            'categories' => $categories,
            'settings' => $settings,
            'holdOrders' => $holdOrders,
            'activeShift' => $activeShift,
            'initialTransactions' => $recentTransactions,
            'initialSummary' => $financialSummary,
            'cashiers' => $isAdmin
                ? User::select('id', 'name', 'email', 'role', 'image', 'created_at')->orderBy('name')->get()
                : [],
            'authCashier' => [
                'id' => $user?->id,
                'name' => $user?->name ?? 'Kasir Toko',
                'email' => $user?->email,
                'role' => $user?->role ?? 'cashier',
                'is_admin' => $isAdmin,
                'can_view_summary' => $canViewSummary,
                'image' => $user?->image,
                'shift' => $isAdmin ? 'Owner Toko · Akses Penuh' : 'Kasir Bertugas',
            ],
        ]);
    }

    /**
     * Proses Checkout / Transaksi Penjualan, Tarik Tunai QRIS & Top-Up
     */
    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'nullable|string|max:100',
            'invoice_no' => 'required|string|max:100',
            'transaction_type' => 'nullable|string|in:SALE,CASH_WITHDRAWAL,TOP_UP',
            'cashier_name' => 'required|string|max:100',
            'customer_id' => 'nullable|integer',
            'customer_name' => 'required|string|max:150',
            'subtotal' => 'required|numeric|min:0',
            'item_discount' => 'nullable|numeric|min:0',
            'global_discount' => 'nullable|numeric|min:0',
            'global_discount_note' => 'nullable|string|max:255',
            'grand_total' => 'required|numeric|min:0',
            'admin_fee' => 'nullable|numeric|min:0',
            'payment_method' => 'required|string|in:TUNAI,QRIS,KASBON',
            'paid_amount' => 'required|numeric|min:0',
            'change_amount' => 'required|numeric|min:0',
            'due_date' => 'nullable|date',
            'items' => 'nullable|array',
            'items.*.product_id' => 'nullable',
            'items.*.barcode' => 'nullable|string|max:100',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.unit_name' => 'required|string|max:100',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.cost_price' => 'nullable|numeric|min:0',
            'items.*.ratio' => 'nullable|integer|min:1',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.total' => 'required|numeric|min:0',
        ]);

        // Idempotensi: cegah duplikasi transaksi akibat double-tap atau sync ulang
        $uuid = $validated['uuid'] ?? Str::uuid()->toString();
        $existingTrx = Transaction::where('invoice_no', $validated['invoice_no'])
            ->orWhere('uuid', $uuid)
            ->first();
        if ($existingTrx) {
            return redirect()->back()->with([
                'success' => 'Transaksi sudah tercatat.',
                'last_transaction' => $existingTrx->load('items'),
            ]);
        }

        return DB::transaction(function () use ($validated, $uuid) {
            $isStockTrackingEnabled = Setting::get('enable_stock_tracking') === 'true';
            $transactionType = $validated['transaction_type'] ?? 'SALE';
            $paymentMethod = $validated['payment_method'];
            $adminFee = (int) round($validated['admin_fee'] ?? 0);
            $globalDiscount = (int) round($validated['global_discount'] ?? 0);
            $paidAmount = (int) round($validated['paid_amount']);
            $changeAmount = (int) round($validated['change_amount']);

            // Validasi pelanggan yang sah (cegah FK error jika ID pelanggan sudah dihapus)
            $customer = null;
            if (!empty($validated['customer_id'])) {
                $customer = Customer::lockForUpdate()->find($validated['customer_id']);
            }
            $validCustomerId = $customer?->id;

            // Hitung ulang subtotal, diskon, dan validasi item di sisi server (Anti-Tampering)
            $calculatedSubtotal = 0;
            $calculatedItemDiscount = 0;
            $totalCost = 0;
            $preparedItems = [];

            if (!empty($validated['items']) && is_array($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    $qty = max(1, (int) $item['qty']);
                    $ratio = max(1, (int) ($item['ratio'] ?? 1));
                    $unitPrice = max(0, (int) round($item['unit_price']));
                    $itemDiscount = max(0, (int) round($item['discount'] ?? 0));
                    $lineTotal = max(0, ($unitPrice * $qty) - $itemDiscount);

                    // Pastikan product_id benar-benar ada di tabel products agar tidak gagal Foreign Key
                    $prod = null;
                    if ($transactionType === 'SALE') {
                        if (!empty($item['product_id']) && is_numeric($item['product_id'])) {
                            $prod = Product::lockForUpdate()->find((int) $item['product_id']);
                        }
                        if (!$prod && !empty($item['barcode'])) {
                            $prod = Product::lockForUpdate()->where('barcode', $item['barcode'])->first();
                        }
                    }

                    $cost = (int) round($item['cost_price'] ?? ($prod?->cost_price ?? 0));
                    $totalCost += ($cost * $qty);
                    $calculatedSubtotal += ($unitPrice * $qty);
                    $calculatedItemDiscount += $itemDiscount;

                    $preparedItems[] = [
                        'product_id' => $prod?->id,
                        'product_name' => $item['product_name'],
                        'unit_name' => $item['unit_name'],
                        'unit_price' => $unitPrice,
                        'cost_price' => $cost,
                        'ratio' => $ratio,
                        'qty' => $qty,
                        'discount' => $itemDiscount,
                        'total' => $lineTotal,
                        'product_model' => $prod,
                    ];
                }
            }

            // Re-kalkulasi & verifikasi nominal akhir berdasarkan jenis transaksi
            if ($transactionType === 'CASH_WITHDRAWAL') {
                if ($changeAmount <= 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'change_amount' => 'Nominal penarikan tunai harus lebih besar dari Rp 0.',
                    ]);
                }
                $serverSubtotal = $changeAmount;
                $serverItemDiscount = 0;
                $serverGlobalDiscount = 0;
                $serverGrandTotal = 0;
                $paidAmount = $changeAmount + $adminFee;
            } elseif ($transactionType === 'TOP_UP') {
                $serverSubtotal = (int) round($validated['subtotal']);
                $serverItemDiscount = 0;
                $serverGlobalDiscount = 0;
                $serverGrandTotal = $serverSubtotal + $adminFee;
                $totalCost = $serverSubtotal;
                if ($paymentMethod === 'TUNAI') {
                    if ($paidAmount < $serverGrandTotal) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'paid_amount' => 'Uang tunai diterima kurang dari total tagihan Top-Up.',
                        ]);
                    }
                    $changeAmount = $paidAmount - $serverGrandTotal;
                } elseif ($paymentMethod === 'QRIS') {
                    $paidAmount = max($paidAmount, $serverGrandTotal);
                    $changeAmount = $paidAmount - $serverGrandTotal;
                } else {
                    $paidAmount = 0;
                    $changeAmount = 0;
                }
            } else {
                // SALE (Transaksi Belanja Normal)
                if (empty($preparedItems)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => 'Keranjang belanja tidak boleh kosong.',
                    ]);
                }
                $serverSubtotal = $calculatedSubtotal;
                $serverItemDiscount = $calculatedItemDiscount;
                $serverGlobalDiscount = min($globalDiscount, max(0, $serverSubtotal - $serverItemDiscount));
                $serverGrandTotal = max(0, $serverSubtotal - $serverItemDiscount - $serverGlobalDiscount);

                if ($paymentMethod === 'TUNAI') {
                    if ($paidAmount < $serverGrandTotal) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'paid_amount' => 'Uang tunai diterima kurang dari total belanja.',
                        ]);
                    }
                    $changeAmount = $paidAmount - $serverGrandTotal;
                } elseif ($paymentMethod === 'QRIS') {
                    $totalQrisDue = $serverGrandTotal + $adminFee;
                    if ($paidAmount < $totalQrisDue) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'paid_amount' => 'Nominal QRIS diterima kurang dari total tagihan.',
                        ]);
                    }
                    $changeAmount = $paidAmount - $totalQrisDue;
                } else {
                    // KASBON
                    $paidAmount = 0;
                    $changeAmount = 0;
                }
            }

            // Validasi aturan KASBON (wajib member non-Umum & cek credit_limit)
            if ($paymentMethod === 'KASBON') {
                $amountToDebt = $serverGrandTotal;
                if (!$customer || $customer->id === 1 || str_contains(strtolower($customer->name), 'umum')) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'customer_id' => 'Transaksi Kasbon hanya diperbolehkan untuk pelanggan/member terdaftar (bukan Pelanggan Umum).',
                    ]);
                }
                $limit = (int) ($customer->credit_limit ?: 500000);
                if ($limit > 0 && ($customer->kasbon_balance + $amountToDebt) > $limit) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'customer_id' => "Plafon limit kasbon {$customer->name} (Rp " . number_format($limit, 0, ',', '.') . ") terlampaui!",
                    ]);
                }
            }

            $activeShift = CashierShift::where('status', 'OPEN')->latest()->first();

            $transaction = Transaction::create([
                'shift_id' => $activeShift?->id,
                'uuid' => $uuid,
                'invoice_no' => $validated['invoice_no'],
                'transaction_type' => $transactionType,
                'cashier_name' => auth()->user()?->name ?? $validated['cashier_name'] ?? 'Kasir Toko',
                'customer_id' => $validCustomerId,
                'customer_name' => $customer?->name ?? $validated['customer_name'],
                'subtotal' => $serverSubtotal,
                'item_discount' => $serverItemDiscount,
                'global_discount' => $serverGlobalDiscount,
                'global_discount_note' => $validated['global_discount_note'] ?? null,
                'grand_total' => $serverGrandTotal,
                'admin_fee' => $adminFee,
                'total_cost' => $totalCost,
                'payment_method' => $paymentMethod,
                'paid_amount' => $paidAmount,
                'change_amount' => $changeAmount,
                'due_date' => $validated['due_date'] ?? null,
                'status' => 'PAID',
            ]);

            if (!empty($preparedItems)) {
                foreach ($preparedItems as $pItem) {
                    $prod = $pItem['product_model'];
                    unset($pItem['product_model']);
                    $transaction->items()->create($pItem);

                    // Potong stok aman (anti-negatif) jika saklar stok aktif dan transaksi penjualan barang fisik
                    if ($isStockTrackingEnabled && $transactionType === 'SALE' && $prod) {
                        $newStock = max(0, $prod->stock - ($pItem['qty'] * $pItem['ratio']));
                        $prod->update(['stock' => $newStock]);
                    }
                }
            } elseif ($transactionType === 'CASH_WITHDRAWAL') {
                $transaction->items()->create([
                    'product_id' => null,
                    'product_name' => 'Tarik Tunai QRIS (Cash Out)',
                    'unit_name' => 'Transaksi',
                    'unit_price' => $changeAmount,
                    'cost_price' => 0,
                    'ratio' => 1,
                    'qty' => 1,
                    'discount' => 0,
                    'total' => $changeAmount,
                ]);
            }

            if ($paymentMethod === 'KASBON' && $customer) {
                $customer->increment('kasbon_balance', $serverGrandTotal);
            }

            return redirect()->back()->with([
                'success' => $transactionType === 'CASH_WITHDRAWAL'
                    ? 'Penarikan tunai QRIS berhasil diproses.'
                    : ($transactionType === 'TOP_UP'
                        ? 'Transaksi Top-Up / Jasa Saldo berhasil dicatat.'
                        : 'Transaksi berhasil disimpan.'),
                'last_transaction' => $transaction->load('items'),
            ]);
        });
    }

    /**
     * Cek apakah request memiliki akses HP yang sah (via Login Akun Toko ATAU Barcode Token Sementara)
     */
    protected function checkMobileAccess(Request $request): array
    {
        // 1. Jika sudah login sebagai Kasir / Owner toko
        if (auth()->check()) {
            $user = auth()->user();
            $canManage = $user->isAdmin() || Setting::where('key', 'cashier_can_manage_products')->value('value') === 'true';
            return [
                'authorized' => true,
                'can_manage' => $canManage,
                'user_name' => $user->name,
                'is_admin' => $user->isAdmin(),
                'expires_at' => null,
                'expires_human' => null,
                'auth_type' => 'user',
                'token' => null,
            ];
        }

        // 2. Cek token dari query URL, header HTTP, input form, atau session
        $token = $request->query('token') 
            ?? $request->header('X-Mobile-Token') 
            ?? $request->input('_mobile_token') 
            ?? session('mobile_access_token');

        if (!$token) {
            return [
                'authorized' => false,
                'message' => 'Token akses tidak ditemukan. Silakan scan QR Barcode "Input Barang HP" di layar kasir utama.',
            ];
        }

        $storedToken = Setting::get('mobile_access_token');
        $isEnabled = Setting::get('mobile_access_enabled', 'true') === 'true';
        $expiresAtStr = Setting::get('mobile_access_expires_at');
        $expiresAt = $expiresAtStr ? \Carbon\Carbon::parse($expiresAtStr) : null;

        if (!$isEnabled || !$storedToken || $token !== $storedToken) {
            return [
                'authorized' => false,
                'message' => 'Akses input HP dinonaktifkan atau barcode telah diperbarui oleh kasir. Silakan minta kasir scan ulang QR Barcode terbaru.',
            ];
        }

        if ($expiresAt && now()->greaterThan($expiresAt)) {
            return [
                'authorized' => false,
                'message' => 'Masa berlaku barcode akses HP telah habis (' . $expiresAt->format('H:i') . '). Silakan scan ulang barcode di tablet kasir.',
            ];
        }

        // Simpan token ke session HP agar navigasi berikutnya tetap sah
        session([
            'mobile_access_token' => $token,
            'mobile_authorized' => true
        ]);

        return [
            'authorized' => true,
            'can_manage' => true,
            'user_name' => 'Karyawan (HP Pribadi)',
            'is_admin' => false,
            'expires_at' => $expiresAt ? $expiresAt->toIso8601String() : null,
            'expires_human' => $expiresAt ? $expiresAt->format('H:i') : null,
            'auth_type' => 'token',
            'token' => $token,
        ];
    }

    /**
     * Dapatkan status atau buat token akses HP jika belum ada / kadaluarsa
     */
    public function getMobileTokenStatus()
    {
        $token = Setting::get('mobile_access_token');
        $isEnabled = Setting::get('mobile_access_enabled', 'true') === 'true';
        $expiresAtStr = Setting::get('mobile_access_expires_at');
        $expiresAt = $expiresAtStr ? \Carbon\Carbon::parse($expiresAtStr) : null;

        $isExpired = !$expiresAt || now()->greaterThan($expiresAt);

        // Jika belum ada token atau sudah kadaluarsa, buat otomatis yang baru (berlaku 2 jam)
        if (!$token || $isExpired) {
            $token = bin2hex(random_bytes(16));
            $expiresAt = now()->addHours(2);
            $isEnabled = true;
            Setting::set('mobile_access_token', $token);
            Setting::set('mobile_access_expires_at', $expiresAt->toDateTimeString());
            Setting::set('mobile_access_enabled', 'true');
        }

        return response()->json([
            'token' => $token,
            'is_enabled' => $isEnabled && !$isExpired,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_human' => $expiresAt->format('H:i'),
            'remaining_minutes' => max(0, (int) now()->diffInMinutes($expiresAt, false)),
            'url' => route('pos.products.mobile', ['token' => $token]),
        ]);
    }

    /**
     * Buat Token Baru (Perpanjang Masa Aktif)
     */
    public function generateMobileToken(Request $request)
    {
        $token = bin2hex(random_bytes(16));
        $hours = (int) $request->input('duration_hours', 2);
        if ($hours < 1 || $hours > 12) $hours = 2;

        $expiresAt = now()->addHours($hours);

        Setting::set('mobile_access_token', $token);
        Setting::set('mobile_access_expires_at', $expiresAt->toDateTimeString());
        Setting::set('mobile_access_enabled', 'true');

        return response()->json([
            'success' => true,
            'message' => 'Token akses HP berhasil dibuat (berlaku ' . $hours . ' jam).',
            'token' => $token,
            'is_enabled' => true,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_human' => $expiresAt->format('H:i'),
            'remaining_minutes' => $hours * 60,
            'url' => route('pos.products.mobile', ['token' => $token]),
        ]);
    }

    /**
     * Kunci / Cabut Akses HP Karyawan Seketika
     */
    public function revokeMobileToken()
    {
        Setting::set('mobile_access_enabled', 'false');

        return response()->json([
            'success' => true,
            'message' => 'Akses input HP pribadi karyawan telah dikunci.',
            'is_enabled' => false,
        ]);
    }

    /**
     * Tampilan Input & Manajemen Barang Khusus Layar HP (Mobile Stock & Product Entry)
     */
    public function mobileProductInput(Request $request): Response
    {
        $access = $this->checkMobileAccess($request);

        if (!$access['authorized']) {
            return Inertia::render('POS/MobileProductInput', [
                'isAccessDenied' => true,
                'deniedMessage' => $access['message'] ?? 'Akses input HP ditutup atau kadaluarsa. Silakan minta kasir scan ulang QR Barcode dari tablet.',
                'products' => [],
                'categories' => [],
                'canManageProducts' => false,
                'authCashier' => [
                    'id' => 0,
                    'name' => 'Akses Terkunci',
                    'is_admin' => false,
                ],
            ]);
        }

        $products = Product::with(['units', 'category'])->latest()->get();
        $categories = Category::all();

        return Inertia::render('POS/MobileProductInput', [
            'isAccessDenied' => false,
            'products' => $products,
            'categories' => $categories,
            'canManageProducts' => (bool) $access['can_manage'],
            'mobileToken' => $access['token'] ?? null,
            'expiresHuman' => $access['expires_human'] ?? null,
            'authCashier' => [
                'id' => auth()->id() ?? 0,
                'name' => $access['user_name'],
                'is_admin' => $access['is_admin'],
                'auth_type' => $access['auth_type'],
            ],
        ]);
    }

    /**
     * Cek apakah Barcode sudah terdaftar di sistem (untuk pemindai cepat HP)
     */
    public function checkBarcode(string $barcode, Request $request)
    {
        $access = $this->checkMobileAccess($request);
        if (!$access['authorized']) {
            return response()->json(['message' => $access['message']], 403);
        }

        $product = Product::with(['units', 'category'])
            ->where('barcode', $barcode)
            ->first();

        if ($product) {
            return response()->json([
                'exists' => true,
                'product' => $product,
            ]);
        }

        return response()->json([
            'exists' => false,
            'product' => null,
        ]);
    }

    /**
     * Tambah Produk Baru Langsung dari HP / Tablet
     */
    public function storeProduct(Request $request)
    {
        $access = $this->checkMobileAccess($request);
        if (!$access['authorized']) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => $access['message'] ?? 'Akses ditolak.'], 403);
            }
            return redirect()->route('pos.products.mobile')->with('error', $access['message']);
        }

        if (!$access['can_manage']) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => 'Akses ditolak: Fitur tambah produk dinonaktifkan oleh Owner toko.'], 403);
            }
            return redirect()->back()->with('error', 'Akses ditolak: Fitur tambah produk dinonaktifkan oleh Owner toko.');
        }

        $validated = $request->validate([
            'barcode' => 'required|string|unique:products,barcode',
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'category' => 'nullable|string',
            'icon' => 'nullable|string',
            'image' => 'nullable|string',
            'unit_name' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'units' => 'nullable|array',
            'units.*.name' => 'required_with:units|string',
            'units.*.price' => 'required_with:units|numeric',
            'units.*.ratio' => 'nullable|integer',
            'units.*.is_base' => 'nullable|boolean',
        ]);

        $categoryId = $validated['category_id'] ?? null;
        if (!$categoryId && !empty($validated['category'])) {
            $cat = Category::firstOrCreate(
                ['name' => $validated['category']],
                ['icon' => $validated['icon'] ?? '📦']
            );
            $categoryId = $cat->id;
        }

        $baseUnit = $validated['unit_name'] ?? 'Pcs';
        if (!empty($validated['units']) && is_array($validated['units'])) {
            $baseUnit = $validated['units'][0]['name'] ?? $baseUnit;
        }

        $product = Product::create([
            'barcode' => $validated['barcode'],
            'name' => $validated['name'],
            'category_id' => $categoryId,
            'icon' => $validated['icon'] ?? '📦',
            'image' => $validated['image'] ?? null,
            'base_unit' => $baseUnit,
            'stock' => $validated['stock'] ?? 0,
            'cost_price' => $validated['cost_price'] ?? 0,
        ]);

        if (!empty($validated['units']) && is_array($validated['units'])) {
            foreach ($validated['units'] as $idx => $u) {
                $product->units()->create([
                    'name' => $u['name'],
                    'price' => $u['price'],
                    'ratio' => $u['ratio'] ?? 1,
                    'is_base' => $u['is_base'] ?? ($idx === 0),
                ]);
            }
        } else {
            $product->units()->create([
                'name' => $validated['unit_name'] ?? 'Pcs',
                'price' => $validated['price'] ?? 0,
                'ratio' => 1,
                'is_base' => true,
            ]);
        }

        $savedProduct = $product->load(['units', 'category']);

        if (($request->wantsJson() || $request->ajax()) && !$request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'message' => 'Produk berhasil ditambahkan.',
                'product' => $savedProduct,
            ]);
        }

        return redirect()->back()->with([
            'success' => 'Produk berhasil ditambahkan.',
            'product' => $savedProduct,
        ]);
    }

    /**
     * Tambah Kategori Baru Secara Cepat
     */
    public function storeCategory(Request $request)
    {
        $access = $this->checkMobileAccess($request);
        if (!$access['authorized']) {
            if (($request->wantsJson() || $request->ajax()) && !$request->header('X-Inertia')) {
                return response()->json(['message' => $access['message'] ?? 'Akses ditolak.'], 403);
            }
            return redirect()->route('pos.products.mobile')->with('error', $access['message']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'icon' => 'nullable|string|max:10',
            'image' => 'nullable|string',
        ]);

        $category = Category::firstOrCreate(
            ['name' => $validated['name']],
            [
                'icon' => $validated['icon'] ?? '📦',
                'image' => $validated['image'] ?? null,
            ]
        );

        if (($request->wantsJson() || $request->ajax()) && !$request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'message' => 'Kategori berhasil ditambahkan.',
                'category' => $category,
            ]);
        }

        return redirect()->back()->with([
            'success' => 'Kategori berhasil ditambahkan.',
            'category' => $category,
        ]);
    }

    /**
     * Simpan Order Hold (Antrean)
     */
    public function storeHoldOrder(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'data' => 'required|array',
        ]);

        $hold = HoldOrder::create($validated);

        if (($request->wantsJson() || $request->ajax()) && !$request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'hold_order' => $hold,
            ]);
        }

        return redirect()->back()->with('success', 'Antrean pesanan berhasil di-hold.');
    }

    /**
     * Hapus Order Hold setelah di-restore
     */
    public function deleteHoldOrder(Request $request, $id)
    {
        HoldOrder::destroy($id);

        if (($request->wantsJson() || $request->ajax()) && !$request->header('X-Inertia')) {
            return response()->json(['success' => true]);
        }

        return redirect()->back()->with('success', 'Antrean berhasil dipulihkan.');
    }

    /**
     * Update Data Produk & Satuan
     */
    public function updateProduct(Request $request, $id)
    {
        $access = $this->checkMobileAccess($request);
        if (!$access['authorized']) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => $access['message'] ?? 'Akses ditolak.'], 403);
            }
            return redirect()->route('pos.products.mobile')->with('error', $access['message']);
        }

        if (($access['auth_type'] === 'user' && !$access['is_admin']) || !$access['can_manage']) {
            $msg = 'Akses ditolak: Hanya Owner toko yang dapat mengubah data produk.';
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => $msg], 403);
            }
            return redirect()->back()->with('error', $msg);
        }

        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'barcode' => 'required|string|unique:products,barcode,' . $product->id,
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'category' => 'nullable|string',
            'icon' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'units' => 'nullable|array',
            'units.*.name' => 'required_with:units|string',
            'units.*.price' => 'required_with:units|numeric',
            'units.*.ratio' => 'nullable|integer',
            'units.*.is_base' => 'nullable|boolean',
        ]);

        $categoryId = $validated['category_id'] ?? null;
        if (!$categoryId && !empty($validated['category'])) {
            $cat = Category::firstOrCreate(
                ['name' => $validated['category']],
                ['icon' => $validated['icon'] ?? '📦']
            );
            $categoryId = $cat->id;
        }

        $baseUnit = $product->base_unit;
        if (!empty($validated['units']) && is_array($validated['units'])) {
            $baseUnit = $validated['units'][0]['name'] ?? $baseUnit;
        }

        $product->update([
            'barcode' => $validated['barcode'],
            'name' => $validated['name'],
            'category_id' => $categoryId ?? $product->category_id,
            'icon' => $validated['icon'] ?? $product->icon,
            'base_unit' => $baseUnit,
            'cost_price' => $validated['cost_price'] ?? $product->cost_price,
            'stock' => $validated['stock'] ?? $product->stock,
        ]);

        // Sinkronisasi Multi-Satuan
        if (!empty($validated['units']) && is_array($validated['units'])) {
            $product->units()->delete();
            foreach ($validated['units'] as $idx => $u) {
                $product->units()->create([
                    'name' => $u['name'],
                    'price' => $u['price'],
                    'ratio' => $u['ratio'] ?? 1,
                    'is_base' => $u['is_base'] ?? ($idx === 0),
                ]);
            }
        } elseif (isset($validated['price'])) {
            $baseUnitObj = $product->units()->where('is_base', true)->first();
            if ($baseUnitObj) {
                $baseUnitObj->update(['price' => $validated['price']]);
            } else {
                $product->units()->create([
                    'name' => $product->base_unit ?? 'Pcs',
                    'price' => $validated['price'],
                    'ratio' => 1,
                    'is_base' => true,
                ]);
            }
        }

        $updatedProduct = $product->load(['units', 'category']);

        if (($request->wantsJson() || $request->ajax()) && !$request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'message' => 'Data produk berhasil diperbarui.',
                'product' => $updatedProduct,
            ]);
        }

        return redirect()->back()->with([
            'success' => 'Data produk berhasil diperbarui.',
            'product' => $updatedProduct,
        ]);
    }

    /**
     * Hapus Produk
     */
    public function deleteProduct($id)
    {
        if (!auth()->user()?->isAdmin()) {
            return redirect()->back()->with('error', 'Akses ditolak: Hanya Owner / Super Admin yang dapat menghapus produk.');
        }

        $product = Product::findOrFail($id);
        $product->units()->delete();
        $product->delete();

        return redirect()->back()->with('success', 'Produk berhasil dihapus dari katalog.');
    }

    /**
     * Tambah Pelanggan / Member Baru
     */
    public function storeCustomer(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'credit_limit' => 'nullable|numeric|min:0',
        ]);

        $customer = Customer::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? '-',
            'address' => $validated['address'] ?? '-',
            'credit_limit' => $validated['credit_limit'] ?? 500000,
            'kasbon_balance' => 0,
        ]);

        return redirect()->back()->with([
            'success' => 'Pelanggan baru berhasil didaftarkan.',
            'customer' => $customer,
        ]);
    }

    /**
     * Update Data Pelanggan
     */
    public function updateCustomer(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'credit_limit' => 'nullable|numeric|min:0',
        ]);

        $customer->update($validated);

        return redirect()->back()->with('success', 'Data pelanggan berhasil diperbarui.');
    }

    /**
     * Hapus Pelanggan
     */
    public function deleteCustomer($id)
    {
        if ($id == 1) {
            return redirect()->back()->with('error', 'Pelanggan Umum tidak boleh dihapus.');
        }

        $customer = Customer::findOrFail($id);
        if ($customer->kasbon_balance > 0) {
            return redirect()->back()->with('error', 'Pelanggan masih memiliki catatan kasbon. Lunasi kasbon sebelum menghapus.');
        }

        $customer->delete();

        return redirect()->back()->with('success', 'Pelanggan berhasil dihapus.');
    }

    /**
     * Ambil konfigurasi Midtrans dari Setting DB dengan fallback config/.env
     */
    private function getMidtransConfig(): array
    {
        $dbKey = trim((string) Setting::get('midtrans_server_key', ''));
        $dbProd = Setting::get('midtrans_is_production');

        $serverKey = $dbKey !== '' ? $dbKey : config('services.midtrans.server_key', env('MIDTRANS_SERVER_KEY'));
        $isProduction = $dbProd !== null
            ? ($dbProd === 'true' || $dbProd === '1' || $dbProd === true)
            : (bool) config('services.midtrans.is_production', env('MIDTRANS_IS_PRODUCTION', false));

        return [$serverKey, $isProduction];
    }

    /**
     * Generate Dynamic QRIS (Midtrans Gateway atau Standalone Mock)
     */
    public function generateQris(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|string',
            'amount' => 'required|numeric|min:1',
        ]);

        $orderId = $validated['order_id'];
        $amount = (int) $validated['amount'];

        [$serverKey, $isProduction] = $this->getMidtransConfig();

        if (!empty($serverKey)) {
            $baseUrl = $isProduction ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
            
            try {
                $response = Http::withBasicAuth($serverKey, '')
                    ->acceptJson()
                    ->post("{$baseUrl}/v2/charge", [
                        'payment_type' => 'qris',
                        'transaction_details' => [
                            'order_id' => $orderId,
                            'gross_amount' => $amount,
                        ],
                        'qris' => [
                            'acquirer' => 'gopay',
                        ],
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $qrString = $data['qr_string'] ?? '';
                    $qrUrl = $data['actions'][0]['url'] ?? ('https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($qrString));

                    return response()->json([
                        'status' => 'success',
                        'provider' => 'midtrans',
                        'order_id' => $orderId,
                        'amount' => $amount,
                        'qr_string' => $qrString,
                        'qr_url' => $qrUrl,
                        'message' => 'QRIS Midtrans berhasil di-generate.',
                    ]);
                }
            } catch (\Exception $e) {
                // Fallback jika API gagal
            }
        }

        // Mock / Sandbox Simulator Mode
        return response()->json([
            'status' => 'success',
            'provider' => 'simulator',
            'order_id' => $orderId,
            'amount' => $amount,
            'qr_string' => "ROZAK-MART-GATEWAY-QRIS-{$orderId}-{$amount}",
            'qr_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode("ROZAK-MART-POS-GATEWAY-{$orderId}-RP-{$amount}"),
            'message' => 'Simulasi Gateway Aktif (Siap connect Midtrans / TriPay).',
        ]);
    }

    /**
     * Cek Status Transaksi QRIS (Polling dari POS)
     */
    public function checkQrisStatus(Request $request, $orderId)
    {
        [$serverKey, $isProduction] = $this->getMidtransConfig();

        // Simulasi lunas hanya diizinkan jika gateway belum diset / mode sandbox (bukan produksi)
        if ($request->query('simulate') === 'true') {
            if (empty($serverKey) || !$isProduction) {
                return response()->json([
                    'status' => 'settlement',
                    'order_id' => $orderId,
                    'message' => 'Pembayaran terverifikasi lunas (Simulasi).',
                ]);
            }

            return response()->json([
                'status' => 'error',
                'order_id' => $orderId,
                'message' => 'Simulasi lunas dinonaktifkan pada mode Produksi.',
            ], 403);
        }

        if (!empty($serverKey)) {
            $baseUrl = $isProduction ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
            try {
                $response = Http::withBasicAuth($serverKey, '')
                    ->acceptJson()
                    ->get("{$baseUrl}/v2/{$orderId}/status");

                if ($response->successful()) {
                    $data = $response->json();
                    $transactionStatus = $data['transaction_status'] ?? 'pending';
                    
                    return response()->json([
                        'status' => $transactionStatus,
                        'order_id' => $orderId,
                        'data' => $data,
                    ]);
                }
            } catch (\Exception $e) {
                // Return pending on error
            }
        }

        return response()->json([
            'status' => 'pending',
            'order_id' => $orderId,
        ]);
    }

    /**
     * Simpan Pengaturan POS (QRIS & Info Toko)
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'settings' => 'required|array',
        ]);

        $isAdmin = auth()->user()?->isAdmin() ?? false;
        $ownerOnlyKeys = [
            'store_name',
            'store_description',
            'store_address',
            'store_phone',
            'receipt_footer',
            'qris_mode',
            'qris_static_payload',
            'qris_withdrawal_admin_fee',
            'midtrans_server_key',
            'midtrans_is_production',
            'cashier_can_manage_products',
            'cashier_can_view_financial_summary',
            'enable_stock_tracking',
            'enable_cashier_shifts',
            'enable_hpp_profit',
        ];

        foreach ($validated['settings'] as $key => $val) {
            if (!$isAdmin && in_array($key, $ownerOnlyKeys, true)) {
                continue;
            }

            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => is_bool($val) ? ($val ? 'true' : 'false') : (string) $val]
            );
        }

        return redirect()->back()->with('success', 'Pengaturan berhasil disimpan.');
    }

    /**
     * Tambah Akun Kasir Baru
     */
    public function storeCashier(Request $request)
    {
        if (!auth()->user()?->isAdmin()) {
            return redirect()->back()->with('error', 'Akses ditolak: Hanya Owner / Super Admin yang dapat menambah akun kasir.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:users,name',
            'password' => 'required|string|min:4',
            'role' => 'nullable|string|in:admin,cashier',
            'email' => 'nullable|email|unique:users,email',
        ]);

        $email = $validated['email'] ?? (Str::slug($validated['name']) . '@tpkmart.local');
        if (User::where('email', $email)->exists()) {
            $email = Str::slug($validated['name']) . '-' . rand(100, 999) . '@tpkmart.local';
        }

        User::create([
            'name' => trim($validated['name']),
            'email' => $email,
            'role' => $validated['role'] ?? 'cashier',
            'password' => \Illuminate\Support\Facades\Hash::make($validated['password']),
        ]);

        return redirect()->back()->with('success', 'Akun ' . $validated['name'] . ' berhasil ditambahkan.');
    }

    /**
     * Update Data / Sandi Akun Kasir
     */
    public function updateCashier(Request $request, $id)
    {
        if (!auth()->user()?->isAdmin()) {
            return redirect()->back()->with('error', 'Akses ditolak: Hanya Owner / Super Admin yang dapat mengubah akun kasir.');
        }

        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:users,name,' . $id,
            'password' => 'nullable|string|min:4',
            'role' => 'nullable|string|in:admin,cashier',
        ]);

        $user->name = trim($validated['name']);
        if (!empty($validated['password'])) {
            $user->password = \Illuminate\Support\Facades\Hash::make($validated['password']);
        }
        if (!empty($validated['role'])) {
            $user->role = $validated['role'];
        }
        $user->save();

        return redirect()->back()->with('success', 'Akun ' . $user->name . ' berhasil diperbarui.');
    }

    /**
     * Hapus Akun Kasir
     */
    public function deleteCashier($id)
    {
        if (!auth()->user()?->isAdmin()) {
            return redirect()->back()->with('error', 'Akses ditolak: Hanya Owner / Super Admin yang dapat menghapus akun kasir.');
        }

        if (User::count() <= 1) {
            return redirect()->back()->with('error', 'Tidak dapat menghapus kasir terakhir dalam sistem.');
        }

        if ($id == auth()->id()) {
            return redirect()->back()->with('error', 'Tidak dapat menghapus akun kasir yang sedang aktif digunakan.');
        }

        $user = User::findOrFail($id);
        $name = $user->name;
        $user->delete();

        return redirect()->back()->with('success', 'Akun kasir ' . $name . ' berhasil dihapus.');
    }

    /**
     * Ambil Riwayat Transaksi (Filter Search, Tanggal & Metode Bayar)
     */
    public function getTransactions(Request $request)
    {
        $query = Transaction::with('items')->latest();

        if ($request->filled('date')) {
            if ($request->date === 'today') {
                $query->whereDate('created_at', now()->today());
            } elseif ($request->date === 'yesterday') {
                $query->whereDate('created_at', now()->yesterday());
            } elseif ($request->date === 'this_week') {
                $query->where('created_at', '>=', now()->startOfWeek());
            } elseif ($request->date === 'this_month') {
                $query->where('created_at', '>=', now()->startOfMonth());
            }
        }

        if ($request->filled('payment_method') && $request->payment_method !== 'ALL') {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('invoice_no', 'like', "%{$s}%")
                  ->orWhere('customer_name', 'like', "%{$s}%")
                  ->orWhere('cashier_name', 'like', "%{$s}%");
            });
        }

        $transactions = $query->take(100)->get();

        return response()->json([
            'status' => 'success',
            'transactions' => $transactions,
        ]);
    }

    /**
     * Ambil Ringkasan Keuangan Kasir
     */
    public function getFinancialSummary(Request $request)
    {
        $isAdmin = auth()->user()?->isAdmin() ?? false;
        $canViewSummary = $isAdmin || Setting::where('key', 'cashier_can_view_financial_summary')->value('value') === 'true';

        if (!$canViewSummary) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak: Rekapitulasi keuangan dinonaktifkan untuk kasir oleh Owner.'
            ], 403);
        }

        $period = $request->query('period', 'today');
        $query = Transaction::where('status', 'PAID');

        if ($period === 'today') {
            $query->whereDate('created_at', now()->today());
        } elseif ($period === 'this_week') {
            $query->where('created_at', '>=', now()->startOfWeek());
        } elseif ($period === 'this_month') {
            $query->where('created_at', '>=', now()->startOfMonth());
        }

        $transactions = $query->get();
        $isAdmin = auth()->user()?->isAdmin() ?? false;

        return response()->json([
            'status' => 'success',
            'summary' => $this->buildFinancialSummary($transactions, $isAdmin)
        ]);
    }

    /**
     * Catat Pembayaran / Pelunasan Kasbon Pelanggan
     */
    public function payDebt(Request $request, $id)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|in:TUNAI,TRANSFER,QRIS',
            'notes' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($id, $validated) {
            $customer = Customer::lockForUpdate()->findOrFail($id);
            if ($customer->kasbon_balance <= 0) {
                return redirect()->back()->with('error', "Pelanggan {$customer->name} tidak memiliki tunggakan kasbon.");
            }

            $amount = min((int) round($validated['amount']), (int) $customer->kasbon_balance);
            $newBalance = max(0, $customer->kasbon_balance - $amount);
            $customer->update(['kasbon_balance' => $newBalance]);

            DebtPayment::create([
                'customer_id' => $customer->id,
                'amount' => $amount,
                'payment_method' => $validated['payment_method'],
                'notes' => $validated['notes'] ?? 'Cicilan / Pelunasan Kasbon',
            ]);

            return redirect()->back()->with('success', "Pembayaran kasbon {$customer->name} sebesar Rp " . number_format($amount, 0, ',', '.') . " berhasil dicatat.");
        });
    }

    /**
     * Halaman Customer Facing Display (Layar Kedua Pembeli / Dual Display)
     */
    public function customerDisplay(Request $request): Response
    {
        $settings = Setting::pluck('value', 'key');
        $code = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($request->query('code') ?: ''));

        $cacheKey = !empty($code) ? "pos_live_display_{$code}" : 'pos_live_display';
        $liveDisplay = Cache::get($cacheKey, [
            'state' => 'IDLE',
            'cart' => [],
            'grandTotal' => 0,
            'totalQty' => 0,
        ]);

        return Inertia::render('POS/CustomerDisplay', [
            'settings' => $settings,
            'initialLiveState' => $liveDisplay,
            'pairingCode' => $code,
            'store' => [
                'name' => $settings['store_name'] ?? 'Rozak Mart',
                'address' => $settings['store_address'] ?? 'Jl. KH Wahid Hasyim No. 42, Jakarta Barat',
                'phone' => $settings['store_phone'] ?? '0812-3456-7890',
                'footer' => $settings['receipt_footer'] ?? 'Terima Kasih Atas Kunjungan Anda!',
            ]
        ]);
    }

    /**
     * Endpoint Sinkronisasi Live State dari Kasir ke Layar Kedua Pembeli
     * Menggunakan file cache atomic super cepat (<1ms) tanpa beban database
     * Mendukung multi-kasir dengan isolasi per pairing code
     */
    public function syncDisplay(Request $request)
    {
        $data = $request->all();
        $code = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($request->input('code') ?: 'default'));
        $data['code'] = $code;
        $data['timestamp'] = (int) round(microtime(true) * 1000);

        // Hindari membengkakkan file realtime dengan gambar base64
        if (isset($data['banners'])) {
            unset($data['banners']);
        }

        try {
            // Simpan cache terisolasi per kode kasir
            $filePath = storage_path("framework/cache/pos_display_live_{$code}.json");
            file_put_contents($filePath, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);

            // Jika kode default atau meja pertama (100001), update juga file utama untuk fallback kompatibilitas
            if ($code === 'default' || $code === '100001') {
                file_put_contents(storage_path('framework/cache/pos_display_live.json'), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        } catch (\Throwable $e) {}

        Cache::put("pos_live_display_{$code}", $data, 3600);
        if ($code === 'default' || $code === '100001') {
            Cache::put('pos_live_display', $data, 3600);
        }

        return response()->json([
            'success' => true,
            'code' => $code,
            'message' => 'State dual display berhasil diperbarui',
            'timestamp' => $data['timestamp']
        ]);
    }

    /**
     * Endpoint upload media banner promosi (Mendukung Gambar & Video MP4/WebM)
     */
    public function uploadBannerMedia(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpeg,png,jpg,webp,gif,mp4,webm,ogg,mov|max:102400',
            'placement' => 'nullable|string',
            'title' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $placement = $request->input('placement', 'standby');
        $title = $request->input('title') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $mime = $file->getMimeType() ?: '';
        $extension = strtolower($file->getClientOriginalExtension());
        
        $isVideo = str_starts_with($mime, 'video/') || in_array($extension, ['mp4', 'webm', 'ogg', 'mov']);
        $type = $isVideo ? 'video' : 'image';

        $filename = time() . '_' . Str::random(8) . '.' . $extension;
        $destinationPath = public_path('uploads/banners');
        if (!file_exists($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }

        $file->move($destinationPath, $filename);
        $url = '/uploads/banners/' . $filename;

        $banner = [
            'id' => (int) round(microtime(true) * 1000),
            'title' => $title,
            'type' => $type,
            'url' => $url,
            'image' => $url, // Fallback properti untuk kompatibilitas
            'placement' => $placement,
            'created_at' => round(microtime(true) * 1000),
        ];

        return response()->json([
            'success' => true,
            'banner' => $banner,
        ]);
    }

    /**
     * Endpoint khusus penyimpanan banner promosi agar tidak membebani transmisi live cart
     */
    public function saveBanners(Request $request)
    {
        $banners = $request->input('banners', []);
        $filePath = storage_path('framework/cache/pos_banners.json');
        try {
            file_put_contents($filePath, json_encode($banners, JSON_UNESCAPED_UNICODE), LOCK_EX);
        } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'count' => count($banners),
            'timestamp' => (int) round(microtime(true) * 1000)
        ]);
    }

    /**
     * Endpoint pengambilan banner promosi toko
     */
    public function getBanners()
    {
        $filePath = storage_path('framework/cache/pos_banners.json');
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            if ($content) {
                return response($content, 200, [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-cache'
                ]);
            }
        }
        return response()->json([]);
    }

    /**
     * Endpoint Polling Realtime State untuk Layar Kedua Nirkabel (Wireless Secondary Tablet/Phone)
     * Mengembalikan file JSON mentah langsung dengan header no-cache (Responsif <5ms)
     * Mendukung multi-kasir berdasarkan parameter query ?code=XXXXXX
     */
    public function getLiveDisplay(Request $request)
    {
        $code = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($request->query('code') ?: ''));

        if (!empty($code)) {
            $filePath = storage_path("framework/cache/pos_display_live_{$code}.json");
            if (file_exists($filePath)) {
                $content = file_get_contents($filePath);
                if ($content) {
                    return response($content, 200, [
                        'Content-Type' => 'application/json',
                        'Cache-Control' => 'no-cache, no-store, must-revalidate',
                        'Pragma' => 'no-cache'
                    ]);
                }
            }

            $cached = Cache::get("pos_live_display_{$code}");
            if ($cached) {
                return response()->json($cached, 200, [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache'
                ]);
            }
        }

        // Fallback ke single-display default
        $defaultFilePath = storage_path('framework/cache/pos_display_live.json');
        if (file_exists($defaultFilePath)) {
            $content = file_get_contents($defaultFilePath);
            if ($content) {
                return response($content, 200, [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache'
                ]);
            }
        }

        $liveDisplay = Cache::get('pos_live_display', [
            'state' => 'IDLE',
            'cart' => [],
            'grandTotal' => 0,
            'totalQty' => 0,
        ]);

        return response()->json($liveDisplay, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache'
        ]);
    }

    /**
     * WebRTC Direct P2P Signaling (Untuk koneksi browser-ke-browser nol latensi)
     */
    public function postSignal(Request $request)
    {
        $role = $request->input('role'); // 'host' (Tablet 1) atau 'client' (Tablet 2)
        $signal = $request->input('signal');
        $code = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($request->input('code') ?: 'default'));
        if ($role && $signal) {
            $filePath = storage_path("framework/cache/webrtc_signal_{$code}_{$role}.json");
            file_put_contents($filePath, json_encode([
                'signal' => $signal,
                'timestamp' => (int) round(microtime(true) * 1000)
            ]), LOCK_EX);
        }
        return response()->json(['success' => true]);
    }

    public function getSignal(Request $request)
    {
        $role = $request->query('role');
        $code = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($request->query('code') ?: 'default'));
        $filePath = storage_path("framework/cache/webrtc_signal_{$code}_{$role}.json");
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            return response($content, 200, ['Content-Type' => 'application/json']);
        }
        return response()->json(['signal' => null]);
    }

    /**
     * Buka Shift Kasir (Catat Modal Awal Laci)
     */
    public function openShift(Request $request)
    {
        $validated = $request->validate([
            'starting_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $existing = CashierShift::where('status', 'OPEN')->first();
        if ($existing) {
            return redirect()->back()->with('error', "Shift masih aktif oleh {$existing->cashier_name}. Tutup shift tersebut terlebih dahulu.");
        }

        $shift = CashierShift::create([
            'user_id' => auth()->id(),
            'cashier_name' => auth()->user()?->name ?? 'Kasir Toko',
            'start_time' => now(),
            'starting_cash' => (int) round($validated['starting_cash']),
            'expected_cash' => (int) round($validated['starting_cash']),
            'status' => 'OPEN',
            'notes' => $validated['notes'] ?? null,
        ]);

        if (($request->wantsJson() || $request->ajax()) && !$request->header('X-Inertia')) {
            return response()->json(['success' => true, 'shift' => $shift->load('movements')]);
        }

        return redirect()->back()->with([
            'success' => 'Shift kasir berhasil dibuka.',
            'active_shift' => $shift->load('movements'),
        ]);
    }

    /**
     * Catat Arus Kas Laci Operasional (Kas Masuk / Kas Keluar)
     */
    public function storeCashMovement(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|string|in:CASH_IN,CASH_OUT',
            'amount' => 'required|numeric|min:1',
            'reason' => 'required|string|max:255',
        ]);

        $shift = CashierShift::where('status', 'OPEN')->latest()->first();
        if (!$shift) {
            return redirect()->back()->with('error', 'Tidak ada shift aktif. Silakan buka shift terlebih dahulu.');
        }

        $movement = $shift->movements()->create([
            'type' => $validated['type'],
            'amount' => (int) round($validated['amount']),
            'reason' => trim($validated['reason']),
        ]);

        if (($request->wantsJson() || $request->ajax()) && !$request->header('X-Inertia')) {
            return response()->json(['success' => true, 'movement' => $movement, 'shift' => $shift->load('movements')]);
        }

        return redirect()->back()->with([
            'success' => 'Arus kas laci berhasil dicatat.',
            'active_shift' => $shift->load('movements'),
        ]);
    }

    /**
     * Tutup Shift Kasir & Hitung Selisih Laci
     */
    public function closeShift(Request $request)
    {
        $validated = $request->validate([
            'actual_cash' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $shift = CashierShift::with(['movements', 'transactions' => function ($q) {
            $q->where('status', 'PAID');
        }])->where('status', 'OPEN')->latest()->first();

        if (!$shift) {
            return redirect()->back()->with('error', 'Tidak ada shift kasir yang sedang aktif.');
        }

        $shiftTrxs = $shift->transactions;
        $cashSales = (int) $shiftTrxs->filter(fn($t) => $t->payment_method === 'TUNAI' && ($t->transaction_type ?? 'SALE') !== 'TOP_UP')->sum('grand_total');
        $topupCashIn = (int) $shiftTrxs->filter(fn($t) => ($t->transaction_type ?? 'SALE') === 'TOP_UP' && $t->payment_method === 'TUNAI')->sum('grand_total');
        $topupCapitalOut = (int) $shiftTrxs->filter(fn($t) => ($t->transaction_type ?? 'SALE') === 'TOP_UP')->sum('subtotal');
        $qrisCashOut = (int) $shiftTrxs->where('payment_method', 'QRIS')->sum('change_amount');
        $debtPaidCash = (int) DebtPayment::where('created_at', '>=', $shift->start_time)->where('payment_method', 'TUNAI')->sum('amount');

        $cashMovementsIn = (int) $shift->movements->where('type', 'CASH_IN')->sum('amount');
        $cashMovementsOut = (int) $shift->movements->where('type', 'CASH_OUT')->sum('amount');

        $expectedCash = $shift->starting_cash
            + $cashSales
            + $topupCashIn
            + $debtPaidCash
            + $cashMovementsIn
            - $qrisCashOut
            - $topupCapitalOut
            - $cashMovementsOut;

        $actualCash = (int) round($validated['actual_cash']);
        $difference = $actualCash - $expectedCash;

        $shift->update([
            'end_time' => now(),
            'expected_cash' => $expectedCash,
            'actual_cash' => $actualCash,
            'difference' => $difference,
            'status' => 'CLOSED',
            'notes' => $validated['notes'] ?? $shift->notes,
        ]);

        if (($request->wantsJson() || $request->ajax()) && !$request->header('X-Inertia')) {
            return response()->json(['success' => true, 'closed_shift' => $shift]);
        }

        return redirect()->back()->with([
            'success' => 'Shift kasir ditutup. Selisih kas: Rp ' . number_format($difference, 0, ',', '.'),
            'active_shift' => null,
        ]);
    }

    /**
     * Terapkan Update Aplikasi di Hosting (Owner Only):
     * - mode 'git_pull': jalankan git pull origin main (jika exec aktif) + migrate + optimize:clear
     * - mode 'apply': jalankan migrate --force + optimize:clear via Artisan::call
     */
    public function applySystemUpdate(Request $request)
    {
        if (!(auth()->user()?->isAdmin() ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak: Hanya Owner yang dapat menjalankan update sistem.',
            ], 403);
        }

        $mode = $request->input('mode', 'apply');
        $gitOutput = '';

        if ($mode === 'git_pull') {
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
            if (!function_exists('exec') || in_array('exec', $disabled, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fungsi exec() dinonaktifkan oleh server hosting. Silakan klik Pull di menu Git cPanel terlebih dahulu, lalu gunakan tombol "Terapkan Update (Migrate & Clear Cache)".',
                ], 422);
            }

            if (!is_dir(base_path('.git'))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Folder .git tidak ditemukan di server hosting. Gunakan Pull dari panel hosting lalu klik "Terapkan Update".',
                ], 422);
            }

            $outputLines = [];
            $exitCode = 0;
            $cmd = 'cd ' . escapeshellarg(base_path()) . ' && git pull origin main 2>&1';
            exec($cmd, $outputLines, $exitCode);
            $gitOutput = trim(implode("\n", $outputLines));

            if ($exitCode !== 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Git pull gagal dijalankan di server.',
                    'git_output' => $gitOutput,
                ], 500);
            }
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            $migrateOutput = trim(Artisan::output());

            Artisan::call('optimize:clear');
            $cacheOutput = trim(Artisan::output());

            return response()->json([
                'success' => true,
                'message' => $mode === 'git_pull'
                    ? 'Update dari Git (main), migrasi database, dan pembersihan cache berhasil!'
                    : 'Migrasi database dan pembersihan cache server berhasil diterapkan!',
                'git_output' => $gitOutput,
                'migrate_output' => $migrateOutput,
                'cache_output' => $cacheOutput,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menerapkan update server: ' . $e->getMessage(),
                'git_output' => $gitOutput,
            ], 500);
        }
    }

    private function ensureOwnerJson()
    {
        if (!(auth()->user()?->isAdmin() ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak: Hanya Owner yang dapat mengelola backup & restore database.',
            ], 403);
        }

        return null;
    }

    public function listBackups(DatabaseBackupService $service)
    {
        if ($denied = $this->ensureOwnerJson()) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'driver' => DB::getDriverName(),
            'backups' => $service->listBackups(),
        ]);
    }

    public function createBackup(DatabaseBackupService $service)
    {
        if ($denied = $this->ensureOwnerJson()) {
            return $denied;
        }

        try {
            $result = $service->createJsonBackup();

            return response()->json([
                'success' => true,
                'message' => "Backup \"{$result['filename']}\" berhasil dibuat ({$result['total_rows']} baris data).",
                'backup' => $result,
                'backups' => $service->listBackups(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat backup: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function downloadBackup(string $filename, DatabaseBackupService $service)
    {
        if (!(auth()->user()?->isAdmin() ?? false)) {
            abort(403, 'Akses ditolak.');
        }

        try {
            $path = $service->getBackupPath($filename);

            return response()->download($path, basename($path));
        } catch (\Throwable $e) {
            abort(404, $e->getMessage());
        }
    }

    public function uploadBackup(Request $request, DatabaseBackupService $service)
    {
        if ($denied = $this->ensureOwnerJson()) {
            return $denied;
        }

        $request->validate([
            'file' => 'required|file|max:51200',
            'restore_now' => 'nullable|boolean',
        ]);

        try {
            $stored = $service->storeUploadedBackup($request->file('file'));
            $restoreInfo = null;

            if ($request->boolean('restore_now')) {
                $restoreInfo = $service->restoreFromFilename($stored['filename']);
            }

            return response()->json([
                'success' => true,
                'message' => $restoreInfo
                    ? 'File backup berhasil diunggah dan langsung di-restore ke database!'
                    : "File backup \"{$stored['filename']}\" berhasil diunggah ke server.",
                'uploaded' => $stored['filename'],
                'restore' => $restoreInfo,
                'backups' => $service->listBackups(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function restoreBackup(Request $request, DatabaseBackupService $service)
    {
        if ($denied = $this->ensureOwnerJson()) {
            return $denied;
        }

        $validated = $request->validate([
            'filename' => 'required|string',
        ]);

        try {
            $result = $service->restoreFromFilename($validated['filename']);

            return response()->json([
                'success' => true,
                'message' => 'Database berhasil di-restore! Snapshot pengaman sebelumnya sudah disimpan otomatis.',
                'restore' => $result,
                'backups' => $service->listBackups(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Restore gagal: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function deleteBackup(string $filename, DatabaseBackupService $service)
    {
        if ($denied = $this->ensureOwnerJson()) {
            return $denied;
        }

        try {
            $service->deleteBackup($filename);

            return response()->json([
                'success' => true,
                'message' => "Backup \"{$filename}\" dihapus.",
                'backups' => $service->listBackups(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }
}
