<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncController extends Controller
{
    /**
     * Pull master data untuk tablet offline (Produk & Pelanggan)
     */
    public function pull(Request $request): JsonResponse
    {
        $since = $request->query('since');
        $isAdmin = auth()->user()?->isAdmin() ?? false;

        $productsQuery = Product::with(['units', 'category']);
        $customersQuery = Customer::query();

        if ($since) {
            $productsQuery->where('updated_at', '>=', $since);
            $customersQuery->where('updated_at', '>=', $since);
        }

        $products = $productsQuery->get();
        if (!$isAdmin) {
            $products->each->makeHidden(['cost_price']);
        }

        return response()->json([
            'status' => 'success',
            'server_time' => now()->toIso8601String(),
            'products' => $products,
            'customers' => $customersQuery->get(),
        ]);
    }

    /**
     * Push transaksi offline dari tablet ke server Laravel
     */
    public function pushTransactions(Request $request): JsonResponse
    {
        $transactions = $request->input('transactions', []);
        $saved = [];

        DB::transaction(function () use ($transactions, &$saved) {
            $isStockTrackingEnabled = Setting::get('enable_stock_tracking') === 'true';

            foreach ($transactions as $data) {
                $invoiceNo = $data['invoice_no'] ?? null;
                $uuid = $data['uuid'] ?? Str::uuid()->toString();
                if (!$invoiceNo) {
                    continue;
                }

                if (Transaction::where('invoice_no', $invoiceNo)->orWhere('uuid', $uuid)->exists()) {
                    $saved[] = $invoiceNo;
                    continue; // Idempoten: anggap sudah tersinkron jika duplikat
                }

                $transactionType = $data['transaction_type'] ?? 'SALE';
                $customerId = null;
                if (!empty($data['customer_id'])) {
                    $cust = Customer::find($data['customer_id']);
                    $customerId = $cust?->id;
                }

                $trx = Transaction::create([
                    'uuid' => $uuid,
                    'invoice_no' => $invoiceNo,
                    'transaction_type' => $transactionType,
                    'cashier_name' => $data['cashier_name'] ?? auth()->user()?->name ?? 'Kasir Toko',
                    'customer_id' => $customerId,
                    'customer_name' => $data['customer_name'] ?? 'Pelanggan Umum',
                    'subtotal' => max(0, (int) ($data['subtotal'] ?? 0)),
                    'item_discount' => max(0, (int) ($data['item_discount'] ?? 0)),
                    'global_discount' => max(0, (int) ($data['global_discount'] ?? 0)),
                    'global_discount_note' => $data['global_discount_note'] ?? null,
                    'grand_total' => max(0, (int) ($data['grand_total'] ?? 0)),
                    'admin_fee' => max(0, (int) ($data['admin_fee'] ?? 0)),
                    'total_cost' => 0,
                    'payment_method' => $data['payment_method'] ?? 'TUNAI',
                    'paid_amount' => max(0, (int) ($data['paid_amount'] ?? 0)),
                    'change_amount' => max(0, (int) ($data['change_amount'] ?? 0)),
                    'status' => 'PAID',
                    'synced_at' => now(),
                    'created_at' => $data['created_at'] ?? now(),
                ]);

                $totalCost = 0;
                if (!empty($data['items']) && is_array($data['items'])) {
                    foreach ($data['items'] as $it) {
                        $prod = null;
                        if ($transactionType === 'SALE') {
                            if (!empty($it['product_id']) && is_numeric($it['product_id'])) {
                                $prod = Product::find((int) $it['product_id']);
                            }
                            if (!$prod && !empty($it['barcode'])) {
                                $prod = Product::where('barcode', $it['barcode'])->first();
                            }
                        }

                        $qty = max(1, (int) ($it['qty'] ?? 1));
                        $ratio = max(1, (int) ($it['ratio'] ?? 1));
                        $unitPrice = max(0, (int) ($it['unit_price'] ?? $it['price'] ?? 0));
                        $cost = max(0, (int) ($it['cost_price'] ?? $prod?->cost_price ?? 0));
                        $discount = max(0, (int) ($it['discount'] ?? 0));
                        $total = max(0, (int) ($it['total'] ?? (($unitPrice * $qty) - $discount)));

                        $totalCost += ($cost * $qty);

                        $trx->items()->create([
                            'product_id' => $prod?->id,
                            'product_name' => $it['product_name'] ?? $it['name'] ?? 'Produk',
                            'unit_name' => $it['unit_name'] ?? $it['unit'] ?? 'Pcs',
                            'unit_price' => $unitPrice,
                            'cost_price' => $cost,
                            'ratio' => $ratio,
                            'qty' => $qty,
                            'discount' => $discount,
                            'total' => $total,
                        ]);

                        if ($isStockTrackingEnabled && $transactionType === 'SALE' && $prod) {
                            $prod->update(['stock' => max(0, $prod->stock - ($qty * $ratio))]);
                        }
                    }
                }

                if ($transactionType === 'TOP_UP') {
                    $totalCost = $trx->subtotal;
                }
                $trx->update(['total_cost' => $totalCost]);

                // Tambahkan kasbon jika metode bayar KASBON pada member valid
                if (($data['payment_method'] ?? '') === 'KASBON' && $customerId && $customerId !== 1) {
                    Customer::where('id', $customerId)->increment('kasbon_balance', $trx->grand_total);
                }

                $saved[] = $trx->invoice_no;
            }
        });

        return response()->json([
            'status' => 'success',
            'synced_count' => count($saved),
            'synced_invoices' => $saved,
        ]);
    }

    /**
     * Push produk baru yang ditambahkan kasir saat offline
     */
    public function pushProducts(Request $request): JsonResponse
    {
        $products = $request->input('products', []);
        $saved = [];

        DB::transaction(function () use ($products, &$saved) {
            foreach ($products as $pData) {
                $barcode = trim((string) ($pData['barcode'] ?? ''));
                if (!$barcode) {
                    continue;
                }

                $existing = Product::where('barcode', $barcode)->first();
                if ($existing) {
                    $saved[] = $existing->barcode;
                    continue;
                }

                $categoryId = $pData['category_id'] ?? null;
                if (!$categoryId && !empty($pData['category'])) {
                    $catName = is_array($pData['category']) ? ($pData['category']['name'] ?? 'Lainnya') : (string) $pData['category'];
                    $cat = Category::firstOrCreate(
                        ['name' => $catName],
                        ['icon' => $pData['icon'] ?? '📦']
                    );
                    $categoryId = $cat->id;
                }

                $units = $pData['units'] ?? [];
                $baseUnit = $pData['base_unit'] ?? $pData['unit_name'] ?? ($units[0]['name'] ?? 'Pcs');

                $product = Product::create([
                    'barcode' => $barcode,
                    'name' => $pData['name'] ?? 'Produk Baru',
                    'category_id' => $categoryId,
                    'icon' => $pData['icon'] ?? '📦',
                    'base_unit' => $baseUnit,
                    'stock' => max(0, (int) ($pData['stock'] ?? 0)),
                    'cost_price' => max(0, (int) ($pData['cost_price'] ?? 0)),
                ]);

                if (!empty($units) && is_array($units)) {
                    foreach ($units as $idx => $u) {
                        $product->units()->create([
                            'name' => $u['name'] ?? 'Pcs',
                            'price' => max(0, (int) ($u['price'] ?? 0)),
                            'ratio' => max(1, (int) ($u['ratio'] ?? 1)),
                            'is_base' => $u['is_base'] ?? ($idx === 0),
                        ]);
                    }
                } else {
                    $product->units()->create([
                        'name' => $baseUnit,
                        'price' => max(0, (int) ($pData['price'] ?? 0)),
                        'ratio' => 1,
                        'is_base' => true,
                    ]);
                }

                $saved[] = $product->barcode;
            }
        });

        return response()->json([
            'status' => 'success',
            'synced_count' => count($saved),
            'synced_barcodes' => $saved,
        ]);
    }
}
