<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Envelope response API seragam.
 *
 * Sukses : { response_code, response_message, response_data }
 * Error  : { response_code, response_message }
 *
 * `response_code` = HTTP status code (int), sama dengan status response.
 */
class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'Berhasil', int $code = 200): JsonResponse
    {
        return response()->json([
            'response_code' => $code,
            'response_message' => $message,
            'response_data' => $data,
        ], $code);
    }

    public static function error(string $message, int $code = 400): JsonResponse
    {
        return response()->json([
            'response_code' => $code,
            'response_message' => $message,
        ], $code);
    }

    /**
     * Petakan exception ke envelope error seragam (dipakai exception handler
     * untuk rute api/*). Pesan untuk status non-422 sengaja generik agar tak
     * membocorkan detail internal.
     */
    public static function fromException(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::error($e->validator->errors()->first() ?: 'Data tidak valid.', 422);
        }

        $status = match (true) {
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => 403, // termasuk Sanctum MissingAbilityException
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };

        $message = match ($status) {
            400 => 'Permintaan tidak valid.',
            401 => 'Tidak terautentikasi.',
            403 => 'Akses ditolak.',
            404 => 'Sumber daya tidak ditemukan.',
            405 => 'Metode tidak diizinkan.',
            429 => 'Terlalu banyak permintaan. Coba lagi nanti.',
            default => $status >= 500 ? 'Terjadi kesalahan pada server.' : 'Permintaan tidak dapat diproses.',
        };

        return self::error($message, $status);
    }
}
