<?php

use App\Http\Controllers\Api\StoreAuthController;
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

    // Rute terproteksi (verify/charge/refund) ditambah di item 4/5/7:
    // Route::middleware(['auth:sanctum', 'abilities:shopping:charge', ...])->group(...)
});
