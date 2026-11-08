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
Route::post('/pos/categories', [PosController::class, 'storeCategory'])->name('pos.categories.store');
Route::post('/pos/hold-orders', [PosController::class, 'storeHoldOrder'])->name('pos.hold.store');
Route::delete('/pos/hold-orders/{id}', [PosController::class, 'deleteHoldOrder'])->name('pos.hold.delete');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
