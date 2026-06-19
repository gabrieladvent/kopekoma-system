<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pembungkus response verify (ADR D2/D4b). Whitelist field: `balance` selalu,
 * `affordable` hanya bila `amount` dikirim. Tetap TIDAK mengeluarkan nama anggota
 * maupun `member_number` (minim-PII identitas dipertahankan).
 *
 * @property array{balance: string, affordable: bool|null} $resource
 */
class VerifyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = ['balance' => (string) $this->resource['balance']];

        if (($this->resource['affordable'] ?? null) !== null) {
            $data['affordable'] = (bool) $this->resource['affordable'];
        }

        return $data;
    }
}
