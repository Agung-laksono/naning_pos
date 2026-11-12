<?php

use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\PosController;
use Illuminate\Support\Facades\Route;

// Stateless Dual Display Read-Only & WebRTC Pairing Signaling (Untuk Tablet Pembeli Nirkabel)
Route::get('/pos/display/live', [PosController::class, 'getLiveDisplay']);
Route::get('/pos/display/banners', [PosController::class, 'getBanners']);
Route::post('/pos/display/signal', [PosController::class, 'postSignal'])->middleware('throttle:60,1');
Route::get('/pos/display/signal', [PosController::class, 'getSignal'])->middleware('throttle:120,1');

// Endpoint Write Dual Display & Offline Sync (Wajib Autentikasi Kasir/Owner)
Route::middleware('auth')->group(function () {
    Route::post('/pos/display/sync', [PosController::class, 'syncDisplay']);
    Route::post('/pos/display/banners/upload', [PosController::class, 'uploadBannerMedia']);
    Route::post('/pos/display/banners', [PosController::class, 'saveBanners']);

    // Offline-First Sync Endpoints for POS Tablet
    Route::prefix('sync')->group(function () {
        Route::get('/pull', [SyncController::class, 'pull']);
        Route::post('/transactions', [SyncController::class, 'pushTransactions']);
        Route::post('/products', [SyncController::class, 'pushProducts']);
    });
});
