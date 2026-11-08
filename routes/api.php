<?php

use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

// Offline-First Sync Endpoints for Android Tablet (Capacitor)
Route::prefix('sync')->group(function () {
    Route::get('/pull', [SyncController::class, 'pull']);
    Route::post('/transactions', [SyncController::class, 'pushTransactions']);
    Route::post('/products', [SyncController::class, 'pushProducts']);
});

