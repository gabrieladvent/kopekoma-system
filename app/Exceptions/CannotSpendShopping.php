<?php

namespace App\Exceptions;

use RuntimeException;

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
