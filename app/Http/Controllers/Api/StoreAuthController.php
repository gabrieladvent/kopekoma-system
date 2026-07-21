<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreClient;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

        if ($client === null || ! $client->is_active || ! Hash::check($data['client_secret'], $client->client_secret)) {
            return ApiResponse::error('Kredensial klien tidak valid atau klien nonaktif.', 401);
        }

        $ttlMinutes = (int) config('store.token_ttl_minutes');

        $abilities = ['shopping:charge'];

        if ($client->can_refund) {
            $abilities[] = 'shopping:refund';
        }

        $token = $client->createToken('store-charge', $abilities, now()->addMinutes($ttlMinutes));

        return ApiResponse::success([
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttlMinutes * 60,
        ], 'Token berhasil diterbitkan.');
    }
}
