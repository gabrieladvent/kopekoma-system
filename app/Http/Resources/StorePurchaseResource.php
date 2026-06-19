<?php

namespace App\Http\Resources;

use App\Models\ShoppingTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pembungkus response charge (ADR D2/D4b). Whitelist — hanya `transaction_number`
 * + `charged`. Tak pernah mengeluarkan `new_balance`/nama/NIK/saldo: toko tak perlu
 * tahu sisa saldo anggota.
 *
 * @mixin ShoppingTransaction
 */
class StorePurchaseResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'transaction_number' => $this->transaction_number,
            'charged' => true,
        ];
    }
}
