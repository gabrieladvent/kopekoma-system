<?php

use App\Http\Controllers\Api\StoreAuthController;
use App\Http\Controllers\Api\StorePurchaseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Integrasi API Toko — Pemakaian Saldo Wajib Belanja
|--------------------------------------------------------------------------
| ADR: docs/adr/2026-06-18-integrasi-api-toko-wajib-belanja.md
| Prefix efektif: /api/v1/store/...
*/

Route::prefix('v1/store')->group(function (): void {
    // Penerbitan token (D1). Rate limit anti brute-force client_secret.
    Route::post('token', [StoreAuthController::class, 'token'])
        ->middleware('throttle:store-token')
        ->name('api.store.token');

    // Rute terproteksi: bearer Sanctum + ability shopping:charge + klien aktif
    // + rate limit anti enumerasi/abuse (D1/D3/D9).
    Route::middleware([
        'auth:sanctum',
        'abilities:shopping:charge',
        'store.client',
        'throttle:store-purchase',
    ])->group(function (): void {
        Route::post('purchases/verify', [StorePurchaseController::class, 'verify'])
            ->name('api.store.purchases.verify');
        Route::post('purchases', [StorePurchaseController::class, 'charge'])
            ->name('api.store.purchases.charge');
        // refund ditambah di item 7.
    });
});
