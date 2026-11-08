<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncController extends Controller
{
    /**
     * Pull master data untuk tablet offline (Produk & Pelanggan)
     */
    public function pull(Request $request): JsonResponse
    {
        $since = $request->query('since');

        $productsQuery = Product::with('units');
        $customersQuery = Customer::query();

        if ($since) {
            $productsQuery->where('updated_at', '>=', $since);
            $customersQuery->where('updated_at', '>=', $since);
        }

        return response()->json([
            'status' => 'success',
            'server_time' => now()->toIso8601String(),
            'products' => $productsQuery->get(),
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
            foreach ($transactions as $data) {
                if (Transaction::where('invoice_no', $data['invoice_no'])->exists()) {
                    continue; // Skip duplikat jika sudah pernah di-sync
                }

                $trx = Transaction::create([
                    'invoice_no' => $data['invoice_no'],
                    'cashier_name' => $data['cashier_name'] ?? 'Kasir Toko',
                    'customer_id' => $data['customer_id'] ?? null,
                    'customer_name' => $data['customer_name'] ?? 'Pelanggan Umum',
                    'subtotal' => $data['subtotal'],
                    'item_discount' => $data['item_discount'] ?? 0,
                    'global_discount' => $data['global_discount'] ?? 0,
                    'global_discount_note' => $data['global_discount_note'] ?? null,
                    'grand_total' => $data['grand_total'],
                    'payment_method' => $data['payment_method'],
                    'paid_amount' => $data['paid_amount'],
                    'change_amount' => $data['change_amount'],
                    'status' => 'PAID',
                    'created_at' => $data['created_at'] ?? now(),
                ]);

                if (!empty($data['items'])) {
                    foreach ($data['items'] as $it) {
                        $trx->items()->create([
                            'product_id' => $it['product_id'] ?? null,
                            'product_name' => $it['product_name'] ?? $it['name'],
                            'unit_name' => $it['unit_name'] ?? $it['unit'],
                            'unit_price' => $it['unit_price'] ?? $it['price'],
                            'qty' => $it['qty'],
                            'discount' => $it['discount'] ?? 0,
                            'total' => $it['total'] ?? (($it['price'] * $it['qty']) - ($it['discount'] ?? 0)),
                        ]);
                    }
                }

                // Tambahkan kasbon jika metode bayar KASBON
                if ($data['payment_method'] === 'KASBON' && !empty($data['customer_id'])) {
                    Customer::where('id', $data['customer_id'])->increment('kasbon_balance', $data['grand_total']);
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
                if (Product::where('barcode', $pData['barcode'])->exists()) {
                    continue;
                }

                $product = Product::create([
                    'barcode' => $pData['barcode'],
                    'name' => $pData['name'],
                    'category' => $pData['category'] ?? 'Lainnya',
                    'icon' => $pData['icon'] ?? '📦',
                ]);

                if (!empty($pData['units'])) {
                    foreach ($pData['units'] as $u) {
                        $product->units()->create([
                            'name' => $u['name'],
                            'price' => $u['price'],
                            'ratio' => $u['ratio'] ?? 1,
                        ]);
                    }
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

