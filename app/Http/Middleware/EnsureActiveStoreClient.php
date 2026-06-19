<?php

namespace App\Http\Middleware;

use App\Models\StoreClient;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
