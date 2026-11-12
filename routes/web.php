<?php

use App\Http\Controllers\PosController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Customer Facing Display (Dual Display / Layar Kedua Pembeli - Akses Baca untuk Monitor Pelanggan)
Route::get('/pos/display', [PosController::class, 'customerDisplay'])->name('pos.display');
Route::get('/pos/display/live', [PosController::class, 'getLiveDisplay'])->name('pos.display.live');
Route::get('/pos/display/banners', [PosController::class, 'getBanners'])->name('pos.display.banners');

// Input & Manajemen Barang Khusus Layar HP Pribadi Karyawan (Autentikasi Ganda: Login Akun Toko ATAU Barcode Token Sementara)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/pos/products/mobile', [PosController::class, 'mobileProductInput'])->name('pos.products.mobile');
    Route::get('/pos/products/check-barcode/{barcode}', [PosController::class, 'checkBarcode'])->name('pos.products.checkBarcode');
    Route::post('/pos/products', [PosController::class, 'storeProduct'])->name('pos.products.store');
    Route::put('/pos/products/{id}', [PosController::class, 'updateProduct'])->name('pos.products.update');
    Route::post('/pos/categories', [PosController::class, 'storeCategory'])->name('pos.categories.store');
});

// Halaman POS Utama & Seluruh Aksi Kasir (Wajib Login Kasir / Petugas Toko)
Route::middleware('auth')->group(function () {
    Route::get('/', [PosController::class, 'index'])->name('pos.home');
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('/pos/checkout', [PosController::class, 'checkout'])->name('pos.checkout');

    // Kontrol Tulis Dual Display & Upload Media Promosi (Hanya Petugas Terautentikasi)
    Route::post('/pos/display/sync', [PosController::class, 'syncDisplay'])->name('pos.display.sync');
    Route::post('/pos/display/banners/upload', [PosController::class, 'uploadBannerMedia'])->name('pos.display.banners.upload');
    Route::post('/pos/display/banners', [PosController::class, 'saveBanners'])->name('pos.display.banners.save');

    // Manajemen Token Barcode Akses HP dari Layar Tablet Kasir
    Route::get('/pos/mobile-token/status', [PosController::class, 'getMobileTokenStatus'])->name('pos.mobile.token.status');
    Route::post('/pos/mobile-token/generate', [PosController::class, 'generateMobileToken'])->name('pos.mobile.token.generate');
    Route::post('/pos/mobile-token/revoke', [PosController::class, 'revokeMobileToken'])->name('pos.mobile.token.revoke');

    Route::delete('/pos/products/{id}', [PosController::class, 'deleteProduct'])->name('pos.products.delete');

    Route::post('/pos/customers', [PosController::class, 'storeCustomer'])->name('pos.customers.store');
    Route::put('/pos/customers/{id}', [PosController::class, 'updateCustomer'])->name('pos.customers.update');
    Route::delete('/pos/customers/{id}', [PosController::class, 'deleteCustomer'])->name('pos.customers.delete');
    Route::post('/pos/hold-orders', [PosController::class, 'storeHoldOrder'])->name('pos.hold.store');
    Route::delete('/pos/hold-orders/{id}', [PosController::class, 'deleteHoldOrder'])->name('pos.hold.delete');

    Route::post('/pos/qris/generate', [PosController::class, 'generateQris'])->name('pos.qris.generate');
    Route::get('/pos/qris/check/{orderId}', [PosController::class, 'checkQrisStatus'])->name('pos.qris.check');
    Route::post('/pos/settings', [PosController::class, 'updateSettings'])->name('pos.settings.update');
    Route::post('/pos/system/update', [PosController::class, 'applySystemUpdate'])->name('pos.system.update');
    Route::post('/pos/cashiers', [PosController::class, 'storeCashier'])->name('pos.cashiers.store');
    Route::put('/pos/cashiers/{id}', [PosController::class, 'updateCashier'])->name('pos.cashiers.update');
    Route::delete('/pos/cashiers/{id}', [PosController::class, 'deleteCashier'])->name('pos.cashiers.delete');

    Route::get('/pos/transactions', [PosController::class, 'getTransactions'])->name('pos.transactions.list');
    Route::get('/pos/reports/summary', [PosController::class, 'getFinancialSummary'])->name('pos.reports.summary');
    Route::post('/pos/customers/{id}/pay-debt', [PosController::class, 'payDebt'])->name('pos.customers.payDebt');
    Route::post('/pos/shifts/open', [PosController::class, 'openShift'])->name('pos.shifts.open');
    Route::post('/pos/shifts/movement', [PosController::class, 'storeCashMovement'])->name('pos.shifts.movement');
    Route::post('/pos/shifts/close', [PosController::class, 'closeShift'])->name('pos.shifts.close');

    Route::get('/dashboard', function () {
        return redirect()->route('pos.index');
    })->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
