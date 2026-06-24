<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Integrasi API Toko (ADR Integrasi API Toko Wajib Belanja)
    |--------------------------------------------------------------------------
    */

    'token_ttl_minutes' => (int) env('STORE_TOKEN_TTL_MINUTES', 15),

    'max_charge_per_tx' => env('STORE_MAX_CHARGE_PER_TX', '2000000'),

    'rate_limit' => [
        'token_per_minute' => (int) env('STORE_RATE_TOKEN_PER_MINUTE', 10),
        'purchase_per_minute' => (int) env('STORE_RATE_PURCHASE_PER_MINUTE', 60),
    ],

    'lockout' => [
        'max_failures' => (int) env('STORE_LOCKOUT_MAX_FAILURES', 10),
        'window_minutes' => (int) env('STORE_LOCKOUT_WINDOW_MINUTES', 5),
        'cooldown_minutes' => (int) env('STORE_LOCKOUT_COOLDOWN_MINUTES', 15),
    ],

];
