<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\DebtPayment;
use App\Models\HoldOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    /**
     * Tampilan Utama POS Kasir Tablet Android (Inertia + Vue 3)
     */
    public function index(): Response
    {
        $products = Product::with(['units', 'category'])->latest()->get();
        $customers = Customer::orderByRaw("CASE WHEN name LIKE '%Umum%' OR id = 1 THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();
        $categories = Category::all();
        $settings = Setting::pluck('value', 'key');
        $holdOrders = HoldOrder::latest()->get();

        $today = now()->startOfDay();
        $recentTransactions = Transaction::with('items')->latest()->take(60)->get();
        $todayTransactions = Transaction::where('created_at', '>=', $today)->where('status', 'PAID')->get();

        $financialSummary = [
            'total_sales' => (int) $todayTransactions->sum('grand_total'),
            'cash_sales' => (int) $todayTransactions->where('payment_method', 'TUNAI')->sum('grand_total'),
            'qris_sales' => (int) $todayTransactions->where('payment_method', 'QRIS')->sum('grand_total'),
            'kasbon_sales' => (int) $todayTransactions->where('payment_method', 'KASBON')->sum('grand_total'),
            'transaction_count' => $todayTransactions->count(),
            'total_profit' => (int) ($todayTransactions->sum('grand_total') - $todayTransactions->sum('total_cost')),
            'total_receivable' => (int) Customer::sum('kasbon_balance'),
        ];

        return Inertia::render('POS/Index', [
            'products' => $products,
            'customers' => $customers,
            'categories' => $categories,
            'settings' => $settings,
            'holdOrders' => $holdOrders,
            'initialTransactions' => $recentTransactions,
            'initialSummary' => $financialSummary,
            'authCashier' => [
                'name' => 'Siska Anggraeni',
                'shift' => 'Shift Pagi · Kasir Aktif',
            ],
        ]);
    }

    /**
     * Proses Checkout / Transaksi Penjualan
     */
    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'uuid' => 'nullable|string',
            'invoice_no' => 'required|string',
            'cashier_name' => 'required|string',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'required|string',
            'subtotal' => 'required|numeric',
            'item_discount' => 'nullable|numeric',
            'global_discount' => 'nullable|numeric',
            'global_discount_note' => 'nullable|string',
            'grand_total' => 'required|numeric',
            'payment_method' => 'required|string', // TUNAI, QRIS, KASBON
            'paid_amount' => 'required|numeric',
            'change_amount' => 'required|numeric',
            'due_date' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable',
            'items.*.product_name' => 'required|string',
            'items.*.unit_name' => 'required|string',
            'items.*.unit_price' => 'required|numeric',
            'items.*.cost_price' => 'nullable|numeric',
            'items.*.ratio' => 'nullable|integer',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.discount' => 'nullable|numeric',
            'items.*.total' => 'required|numeric',
        ]);

        return DB::transaction(function () use ($validated) {
            $isStockTrackingEnabled = Setting::get('enable_stock_tracking') === 'true';
            $totalCost = 0;

            $transaction = Transaction::create([
                'uuid' => $validated['uuid'] ?? Str::uuid()->toString(),
                'invoice_no' => $validated['invoice_no'],
                'cashier_name' => $validated['cashier_name'],
                'customer_id' => $validated['customer_id'] ?? null,
                'customer_name' => $validated['customer_name'],
                'subtotal' => $validated['subtotal'],
                'item_discount' => $validated['item_discount'] ?? 0,
                'global_discount' => $validated['global_discount'] ?? 0,
                'global_discount_note' => $validated['global_discount_note'] ?? null,
                'grand_total' => $validated['grand_total'],
                'total_cost' => 0, // Akan di-update setelah kalkulasi items
                'payment_method' => $validated['payment_method'],
                'paid_amount' => $validated['paid_amount'],
                'change_amount' => $validated['change_amount'],
                'due_date' => $validated['due_date'] ?? null,
                'status' => 'PAID',
            ]);

            foreach ($validated['items'] as $item) {
                $cost = (int) ($item['cost_price'] ?? 0);
                $ratio = (int) ($item['ratio'] ?? 1);
                $qty = (int) $item['qty'];
                $lineCost = $cost * $qty;
                $totalCost += $lineCost;

                $transaction->items()->create([
                    'product_id' => $item['product_id'] ?? null,
                    'product_name' => $item['product_name'],
                    'unit_name' => $item['unit_name'],
                    'unit_price' => $item['unit_price'],
                    'cost_price' => $cost,
                    'ratio' => $ratio,
                    'qty' => $qty,
                    'discount' => $item['discount'] ?? 0,
                    'total' => $item['total'],
                ]);

                // Potong stok HANYA jika saklar stok aktif
                if ($isStockTrackingEnabled && !empty($item['product_id'])) {
                    $prod = Product::find($item['product_id']);
                    if ($prod) {
                        $prod->decrement('stock', $qty * $ratio);
                    }
                }
            }

            $transaction->update(['total_cost' => $totalCost]);

            // Jika metode bayar KASBON, catat ke saldo piutang pelanggan
            if ($validated['payment_method'] === 'KASBON' && !empty($validated['customer_id'])) {
                $customer = Customer::find($validated['customer_id']);
                if ($customer) {
                    $customer->increment('kasbon_balance', $validated['grand_total']);
                }
            }

            return redirect()->back()->with([
                'success' => 'Transaksi berhasil disimpan.',
                'last_transaction' => $transaction->load('items'),
            ]);
        });
    }

    /**
     * Tambah Produk Baru Langsung dari Tablet
     */
    public function storeProduct(Request $request)
    {
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

        return redirect()->back()->with('success', 'Produk berhasil ditambahkan.');
    }

    /**
     * Tambah Kategori Baru Secara Cepat
     */
    public function storeCategory(Request $request)
    {
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
            'name' => 'required|string',
            'data' => 'required|array',
        ]);

        HoldOrder::create($validated);

        return redirect()->back()->with('success', 'Antrean pesanan berhasil di-hold.');
    }

    /**
     * Hapus Order Hold setelah di-restore
     */
    public function deleteHoldOrder($id)
    {
        HoldOrder::destroy($id);

        return redirect()->back()->with('success', 'Antrean berhasil dipulihkan.');
    }

    /**
     * Update Data Produk & Satuan
     */
    public function updateProduct(Request $request, $id)
    {
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

        return redirect()->back()->with('success', 'Data produk berhasil diperbarui.');
    }

    /**
     * Hapus Produk
     */
    public function deleteProduct($id)
    {
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

        $serverKey = config('services.midtrans.server_key', env('MIDTRANS_SERVER_KEY'));
        $isProduction = config('services.midtrans.is_production', env('MIDTRANS_IS_PRODUCTION', false));

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
        // Cek jika kasir/pembeli memicu simulasi lunas
        if ($request->query('simulate') === 'true') {
            return response()->json([
                'status' => 'settlement',
                'order_id' => $orderId,
                'message' => 'Pembayaran terverifikasi lunas (Simulasi).',
            ]);
        }

        $serverKey = config('services.midtrans.server_key', env('MIDTRANS_SERVER_KEY'));
        $isProduction = config('services.midtrans.is_production', env('MIDTRANS_IS_PRODUCTION', false));

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

        foreach ($validated['settings'] as $key => $val) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => is_bool($val) ? ($val ? 'true' : 'false') : (string) $val]
            );
        }

        return redirect()->back()->with('success', 'Pengaturan berhasil disimpan.');
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

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_sales' => (int) $transactions->sum('grand_total'),
                'cash_sales' => (int) $transactions->where('payment_method', 'TUNAI')->sum('grand_total'),
                'qris_sales' => (int) $transactions->where('payment_method', 'QRIS')->sum('grand_total'),
                'kasbon_sales' => (int) $transactions->where('payment_method', 'KASBON')->sum('grand_total'),
                'transaction_count' => $transactions->count(),
                'total_profit' => (int) ($transactions->sum('grand_total') - $transactions->sum('total_cost')),
                'total_receivable' => (int) Customer::sum('kasbon_balance'),
            ]
        ]);
    }

    /**
     * Catat Pembayaran / Pelunasan Kasbon Pelanggan
     */
    public function payDebt(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string', // TUNAI, TRANSFER, QRIS
            'notes' => 'nullable|string',
        ]);

        $amount = (int) $validated['amount'];
        $newBalance = max(0, $customer->kasbon_balance - $amount);
        $customer->update(['kasbon_balance' => $newBalance]);

        DebtPayment::create([
            'customer_id' => $customer->id,
            'amount' => $amount,
            'payment_method' => $validated['payment_method'],
            'notes' => $validated['notes'] ?? 'Cicilan / Pelunasan Kasbon',
        ]);

        return redirect()->back()->with('success', "Pembayaran kasbon {$customer->name} sebesar Rp " . number_format($amount, 0, ',', '.') . " berhasil dicatat.");
    }

    /**
     * Halaman Customer Facing Display (Layar Kedua Pembeli / Dual Display)
     */
    public function customerDisplay(Request $request): Response
    {
        $settings = Setting::pluck('value', 'key');
        $liveDisplay = Cache::get('pos_live_display', [
            'state' => 'IDLE',
            'cart' => [],
            'grandTotal' => 0,
            'totalQty' => 0,
        ]);

        return Inertia::render('POS/CustomerDisplay', [
            'settings' => $settings,
            'initialLiveState' => $liveDisplay,
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
     */
    public function syncDisplay(Request $request)
    {
        $data = $request->all();
        $data['timestamp'] = now()->timestamp;
        Cache::put('pos_live_display', $data, 3600);

        return response()->json([
            'success' => true,
            'message' => 'State dual display berhasil diperbarui'
        ]);
    }

    /**
     * Endpoint Polling Realtime State untuk Layar Kedua Nirkabel (Wireless Secondary Tablet/Phone)
     */
    public function getLiveDisplay()
    {
        $liveDisplay = Cache::get('pos_live_display', [
            'state' => 'IDLE',
            'cart' => [],
            'grandTotal' => 0,
            'totalQty' => 0,
        ]);

        return response()->json($liveDisplay);
    }
}
