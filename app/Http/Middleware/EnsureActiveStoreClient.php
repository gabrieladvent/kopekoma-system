<?php

namespace App\Http\Middleware;

use App\Models\StoreClient;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pastikan token yang dipakai milik StoreClient yang masih aktif (ADR D1).
 *
 * `personal_access_tokens.tokenable` polymorphic → `auth:sanctum` bisa
 * mengembalikan tokenable apa pun. Middleware ini menolak token non-StoreClient
 * (mis. milik User/petugas) dan klien yang sudah dinonaktifkan.
 */
class EnsureActiveStoreClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user();

        if (! $client instanceof StoreClient || ! $client->is_active) {
            return ApiResponse::error('Klien toko tidak valid atau nonaktif.', 403);
        }

        return $next($request);
    }
}
