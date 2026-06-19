<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Integrasi API Toko (ADR Integrasi API Toko Wajib Belanja)
    |--------------------------------------------------------------------------
    */

    // TTL token bearer store (menit). Token short-lived + revocable (D1).
    'token_ttl_minutes' => (int) env('STORE_TOKEN_TTL_MINUTES', 60),

    // Plafon nominal per transaksi charge (rupiah). Batasi blast-radius bila
    // client_secret bocor (D2). Di-enforce di controller API, bukan di Action.
    'max_charge_per_tx' => env('STORE_MAX_CHARGE_PER_TX', '2000000'),

    'rate_limit' => [
        // Endpoint token: anti brute-force client_secret (D1).
        'token_per_minute' => (int) env('STORE_RATE_TOKEN_PER_MINUTE', 10),
        // Endpoint verify/charge: anti enumerasi NIK & abuse (D3/D9).
        'purchase_per_minute' => (int) env('STORE_RATE_PURCHASE_PER_MINUTE', 60),
    ],

    // Lockout enumerasi NIK (D3): rate limit saja tak cukup. Setelah N kegagalan
    // lookup beruntun, klien diblok sementara (cooldown). Counter dibagi verify+charge.
    'lockout' => [
        'max_failures' => (int) env('STORE_LOCKOUT_MAX_FAILURES', 10),
        'window_minutes' => (int) env('STORE_LOCKOUT_WINDOW_MINUTES', 5),
        'cooldown_minutes' => (int) env('STORE_LOCKOUT_COOLDOWN_MINUTES', 15),
    ],

];
