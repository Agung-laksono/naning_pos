<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Master Seeder untuk Aplikasi Riil POS Toko Kelontong TPKmart.
     * Hanya menginisialisasi konfigurasi dasar, kategori standar, kasir, dan Pelanggan Umum.
     */
    public function run(): void
    {
        // 1. Inisialisasi Saklar Fitur (Settings)
        $settings = [
            ['key' => 'enable_stock_tracking', 'value' => 'false'], // Saklar Stok (Default OFF)
            ['key' => 'enable_hpp_profit', 'value' => 'false'],     // Saklar HPP & Laba (Default OFF)
            ['key' => 'enable_cashier_shifts', 'value' => 'false'], // Saklar Shift Laci (Default OFF)
            ['key' => 'store_name', 'value' => 'TPKmart'],
            ['key' => 'store_address', 'value' => 'Jl. Melati No. 12, Kel. Sukamaju'],
            ['key' => 'store_phone', 'value' => '0812-3456-7890'],
        ];

        foreach ($settings as $s) {
            Setting::updateOrCreate(['key' => $s['key']], ['value' => $s['value']]);
        }

        // 2. Kategori Produk Standar Toko Kelontong
        $categoriesData = [
            ['name' => 'Sembako', 'icon' => '🌾', 'image' => null],
            ['name' => 'Mie & Instan', 'icon' => '🍜', 'image' => null],
            ['name' => 'Minuman', 'icon' => '🥤', 'image' => null],
            ['name' => 'Rokok', 'icon' => '🚬', 'image' => null],
            ['name' => 'Sabun & Kebersihan', 'icon' => '🧼', 'image' => null],
            ['name' => 'Lainnya', 'icon' => '📦', 'image' => null],
        ];

        foreach ($categoriesData as $c) {
            Category::firstOrCreate(['name' => $c['name']], $c);
        }

        // 3. User Kasir Default
        User::firstOrCreate(
            ['email' => 'kasir@tpkmart.local'],
            [
                'name' => 'Siska Anggraeni',
                'password' => Hash::make('password123'),
                'image' => null,
            ]
        );

        // 4. Default Pelanggan Umum (ID = 1, Wajib Ada untuk Transaksi Non-Member/Cash)
        Customer::firstOrCreate(
            ['name' => 'Pelanggan Umum'],
            [
                'phone' => '-',
                'address' => 'Non-Member / Cash',
                'image' => null,
                'kasbon_balance' => 0,
            ]
        );
    }
}
