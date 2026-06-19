<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dilempar `RecordShoppingUsage` saat pemakaian saldo Wajib Belanja tak dapat
 * dieksekusi (saldo kurang / nominal tak valid). Controller API memetakan ke 422.
 */
class CannotSpendShopping extends RuntimeException
{
    public static function insufficientBalance(string $amount, string $balance): self
    {
        return new self(
            "Nominal pemakaian (Rp {$amount}) melebihi saldo Wajib Belanja (Rp {$balance})."
        );
    }

    public static function invalidAmount(): self
    {
        return new self('Nominal pemakaian harus lebih besar dari nol.');
    }
}
