<?php

use App\Http\Controllers\Api\StoreAuthController;
use App\Http\Controllers\Api\StorePurchaseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/store')->group(function (): void {
    Route::post('token', [StoreAuthController::class, 'token'])
        ->middleware('throttle:store-token')
        ->name('api.store.token');

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
    });

    Route::middleware([
        'auth:sanctum',
        'abilities:shopping:refund',
        'store.client',
        'throttle:store-purchase',
    ])->group(function (): void {
        Route::post('purchases/{transaction_number}/refund', [StorePurchaseController::class, 'refund'])
            ->name('api.store.purchases.refund');
    });
});
