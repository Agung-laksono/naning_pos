<?php

use App\Http\Controllers\PosController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Halaman POS Utama (Android Tablet 65:35 Split Touch Layout)
Route::get('/', [PosController::class, 'index'])->name('pos.home');
Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
Route::post('/pos/checkout', [PosController::class, 'checkout'])->name('pos.checkout');
Route::post('/pos/products', [PosController::class, 'storeProduct'])->name('pos.products.store');
Route::put('/pos/products/{id}', [PosController::class, 'updateProduct'])->name('pos.products.update');
Route::delete('/pos/products/{id}', [PosController::class, 'deleteProduct'])->name('pos.products.delete');

Route::post('/pos/customers', [PosController::class, 'storeCustomer'])->name('pos.customers.store');
Route::put('/pos/customers/{id}', [PosController::class, 'updateCustomer'])->name('pos.customers.update');
Route::delete('/pos/customers/{id}', [PosController::class, 'deleteCustomer'])->name('pos.customers.delete');
Route::post('/pos/categories', [PosController::class, 'storeCategory'])->name('pos.categories.store');
Route::post('/pos/hold-orders', [PosController::class, 'storeHoldOrder'])->name('pos.hold.store');
Route::delete('/pos/hold-orders/{id}', [PosController::class, 'deleteHoldOrder'])->name('pos.hold.delete');

Route::post('/pos/qris/generate', [PosController::class, 'generateQris'])->name('pos.qris.generate');
Route::get('/pos/qris/check/{orderId}', [PosController::class, 'checkQrisStatus'])->name('pos.qris.check');
Route::post('/pos/settings', [PosController::class, 'updateSettings'])->name('pos.settings.update');

Route::get('/pos/transactions', [PosController::class, 'getTransactions'])->name('pos.transactions.list');
Route::get('/pos/reports/summary', [PosController::class, 'getFinancialSummary'])->name('pos.reports.summary');
Route::post('/pos/customers/{id}/pay-debt', [PosController::class, 'payDebt'])->name('pos.customers.payDebt');

// Customer Facing Display (Dual Display / Layar Kedua Pembeli)
Route::get('/pos/display', [PosController::class, 'customerDisplay'])->name('pos.display');
Route::post('/pos/display/sync', [PosController::class, 'syncDisplay'])->name('pos.display.sync');
Route::get('/pos/display/live', [PosController::class, 'getLiveDisplay'])->name('pos.display.live');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
