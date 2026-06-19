<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pembungkus response verify (ADR D2/D4b). Whitelist field — **hanya** `affordable`.
 * Tak pernah mengeluarkan nama/`member_number`/saldo/NIK → minim-PII jadi jaminan
 * struktural, bukan kedisiplinan controller.
 *
 * @property array{affordable: bool} $resource
 */
class VerifyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'affordable' => (bool) $this->resource['affordable'],
        ];
    }
}
