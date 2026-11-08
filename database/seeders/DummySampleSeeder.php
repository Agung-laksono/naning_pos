<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Database\Seeder;

class DummySampleSeeder extends Seeder
{
    /**
     * Seeder Khusus Data Dummy / Simulasi untuk Testing
     * Jalankan jika butuh: php artisan db:seed --class=DummySampleSeeder
     */
    public function run(): void
    {
        // 1. Pelanggan Dummy
        $customers = [
            ['name' => 'Pak RT Bambang', 'phone' => '0812-3456-7890', 'address' => 'Rumah No. 01 Blok A', 'image' => null, 'kasbon_balance' => 45000],
            ['name' => 'Bu Warsito (Warung Nasi)', 'phone' => '0857-1234-5678', 'address' => 'Depan Pos Kamling', 'image' => null, 'kasbon_balance' => 120000],
            ['name' => 'Mas Doni (Bengkel)', 'phone' => '0878-9876-5432', 'address' => 'Bengkel Sebelah Kiri Toko', 'image' => null, 'kasbon_balance' => 0],
            ['name' => 'Mbak Yuni (Katering)', 'phone' => '0813-7766-5544', 'address' => 'Gang Melati 2', 'image' => null, 'kasbon_balance' => 250000],
        ];

        foreach ($customers as $cust) {
            Customer::firstOrCreate(['name' => $cust['name']], $cust);
        }

        // 2. Kategori Map
        $categoryMap = Category::pluck('id', 'name')->toArray();

        // 3. Produk Dummy
        $products = [
            [
                'barcode' => '899123456701',
                'name' => 'Beras Rojolele Super 5kg',
                'category_id' => $categoryMap['Sembako'] ?? null,
                'icon' => '🌾',
                'image' => null,
                'base_unit' => 'Karung',
                'stock' => 50,
                'cost_price' => 62000,
                'units' => [
                    ['name' => 'Karung', 'price' => 68000, 'ratio' => 1, 'is_base' => true],
                ],
            ],
            [
                'barcode' => '899123456702',
                'name' => 'Minyak Goreng Bimoli 2L',
                'category_id' => $categoryMap['Sembako'] ?? null,
                'icon' => '🍳',
                'image' => null,
                'base_unit' => 'Pcs',
                'stock' => 120,
                'cost_price' => 17500,
                'units' => [
                    ['name' => 'Pcs', 'price' => 19500, 'ratio' => 1, 'is_base' => true],
                    ['name' => 'Dus (6 pcs)', 'price' => 114000, 'ratio' => 6, 'is_base' => false],
                ],
            ],
            [
                'barcode' => '899123456703',
                'name' => 'Telur Ayam Ras 1kg',
                'category_id' => $categoryMap['Sembako'] ?? null,
                'icon' => '🥚',
                'image' => null,
                'base_unit' => 'Kg',
                'stock' => 40,
                'cost_price' => 25500,
                'units' => [
                    ['name' => 'Kg', 'price' => 28500, 'ratio' => 1, 'is_base' => true],
                ],
            ],
            [
                'barcode' => '899123456704',
                'name' => 'Gula Pasir Gulaku 1kg',
                'category_id' => $categoryMap['Sembako'] ?? null,
                'icon' => '🍬',
                'image' => null,
                'base_unit' => 'Pcs',
                'stock' => 80,
                'cost_price' => 15500,
                'units' => [
                    ['name' => 'Pcs', 'price' => 17500, 'ratio' => 1, 'is_base' => true],
                    ['name' => 'Dus (20 pcs)', 'price' => 340000, 'ratio' => 20, 'is_base' => false],
                ],
            ],
            [
                'barcode' => '899123456705',
                'name' => 'Indomie Goreng Spesial',
                'category_id' => $categoryMap['Mie & Instan'] ?? null,
                'icon' => '🍜',
                'image' => null,
                'base_unit' => 'Bungkus',
                'stock' => 400,
                'cost_price' => 2700,
                'units' => [
                    ['name' => 'Bungkus', 'price' => 3100, 'ratio' => 1, 'is_base' => true],
                    ['name' => 'Dus (40 bks)', 'price' => 118000, 'ratio' => 40, 'is_base' => false],
                ],
            ],
            [
                'barcode' => '899123456706',
                'name' => 'Aqua Galon 19L (Isi Ulang)',
                'category_id' => $categoryMap['Minuman'] ?? null,
                'icon' => '💧',
                'image' => null,
                'base_unit' => 'Galon',
                'stock' => 30,
                'cost_price' => 16000,
                'units' => [
                    ['name' => 'Galon', 'price' => 20000, 'ratio' => 1, 'is_base' => true],
                ],
            ],
            [
                'barcode' => '899123456707',
                'name' => 'Kopi Kapal Api Special Mix',
                'category_id' => $categoryMap['Minuman'] ?? null,
                'icon' => '☕',
                'image' => null,
                'base_unit' => 'Sachet',
                'stock' => 200,
                'cost_price' => 1650,
                'units' => [
                    ['name' => 'Sachet', 'price' => 2000, 'ratio' => 1, 'is_base' => true],
                    ['name' => 'Renceng (10 sch)', 'price' => 18500, 'ratio' => 10, 'is_base' => false],
                ],
            ],
            [
                'barcode' => '899123456708',
                'name' => 'Rokok Sampoerna Mild 16',
                'category_id' => $categoryMap['Rokok'] ?? null,
                'icon' => '🚬',
                'image' => null,
                'base_unit' => 'Bungkus',
                'stock' => 50,
                'cost_price' => 32000,
                'units' => [
                    ['name' => 'Bungkus', 'price' => 34500, 'ratio' => 1, 'is_base' => true],
                    ['name' => 'Slop (10 bks)', 'price' => 340000, 'ratio' => 10, 'is_base' => false],
                ],
            ],
            [
                'barcode' => '899123456709',
                'name' => 'Teh Celup Sosro Kotak 30s',
                'category_id' => $categoryMap['Minuman'] ?? null,
                'icon' => '🍵',
                'image' => null,
                'base_unit' => 'Kotak',
                'stock' => 60,
                'cost_price' => 7200,
                'units' => [
                    ['name' => 'Kotak', 'price' => 8500, 'ratio' => 1, 'is_base' => true],
                ],
            ],
            [
                'barcode' => '899123456710',
                'name' => 'Susu Kental Manis Frisian Flag',
                'category_id' => $categoryMap['Minuman'] ?? null,
                'icon' => '🥛',
                'image' => null,
                'base_unit' => 'Kaleng',
                'stock' => 45,
                'cost_price' => 11800,
                'units' => [
                    ['name' => 'Kaleng', 'price' => 13500, 'ratio' => 1, 'is_base' => true],
                ],
            ],
            [
                'barcode' => '899123456711',
                'name' => 'Sabun Mandi Lifebuoy 85g',
                'category_id' => $categoryMap['Sabun & Kebersihan'] ?? null,
                'icon' => '🧼',
                'image' => null,
                'base_unit' => 'Batang',
                'stock' => 90,
                'cost_price' => 3800,
                'units' => [
                    ['name' => 'Batang', 'price' => 4500, 'ratio' => 1, 'is_base' => true],
                ],
            ],
            [
                'barcode' => '899123456712',
                'name' => 'Sunlight Pencuci Piring 700ml',
                'category_id' => $categoryMap['Sabun & Kebersihan'] ?? null,
                'icon' => '🧽',
                'image' => null,
                'base_unit' => 'Pouch',
                'stock' => 70,
                'cost_price' => 12200,
                'units' => [
                    ['name' => 'Pouch', 'price' => 14000, 'ratio' => 1, 'is_base' => true],
                ],
            ],
        ];

        foreach ($products as $pData) {
            $units = $pData['units'];
            unset($pData['units']);
            $product = Product::firstOrCreate(['barcode' => $pData['barcode']], $pData);
            if ($product->units()->count() === 0) {
                foreach ($units as $u) {
                    $product->units()->create($u);
                }
            }
        }
    }
}

