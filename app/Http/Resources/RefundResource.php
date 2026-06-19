<?php

namespace App\Http\Resources;

use App\Models\ShoppingTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pembungkus response refund (ADR D8/D4b). Whitelist — hanya nomor transaksi
 * reversal + flag refunded. Tak mengeluarkan saldo/PII.
 *
 * @mixin ShoppingTransaction
 */
class RefundResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'transaction_number' => $this->transaction_number,
            'refunded' => true,
        ];
    }
}
