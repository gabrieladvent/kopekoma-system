<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Penerbitan token bearer untuk klien toko (ADR Integrasi API Toko, D1).
 * Client credentials → token Sanctum ber-ability `shopping:charge`, TTL pendek.
 */
class StoreAuthController extends Controller
{
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string'],
            'client_secret' => ['required', 'string'],
        ]);

        $client = StoreClient::query()
            ->where('client_id', $data['client_id'])
            ->first();

        // Pesan seragam untuk kredensial salah / klien nonaktif — jangan bocorkan
        // apakah client_id ada. Hash::check tetap dijalankan saat client null?
        // Tidak: cukup tolak generik, rate limiter (D1) menjaga brute-force.
        if ($client === null || ! $client->is_active || ! Hash::check($data['client_secret'], $client->client_secret)) {
            throw ValidationException::withMessages([
                'client_id' => ['Kredensial klien tidak valid atau klien nonaktif.'],
            ])->status(401);
        }

        $ttlMinutes = (int) config('store.token_ttl_minutes');

        $token = $client->createToken(
            'store-charge',
            ['shopping:charge'],
            now()->addMinutes($ttlMinutes),
        );

        return response()->json([
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttlMinutes * 60,
        ]);
    }
}
