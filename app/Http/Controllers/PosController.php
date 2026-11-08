<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\HoldOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return Inertia::render('POS/Index', [
            'products' => $products,
            'customers' => $customers,
            'categories' => $categories,
            'settings' => $settings,
            'holdOrders' => $holdOrders,
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
}
