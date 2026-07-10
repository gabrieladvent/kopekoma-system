<?php

namespace App\Exceptions;

use RuntimeException;

class CannotProcessWithdrawal extends RuntimeException
{
    public static function illegalTransition(string $from, string $to): self
    {
        return new self("Transisi status pencairan tidak diizinkan: {$from} → {$to}.");
    }

    public static function insufficientBalance(): self
    {
        return new self('Saldo tidak mencukupi untuk pencairan ini.');
    }

    public static function unsupportedType(string $type): self
    {
        return new self("Jenis simpanan \"{$type}\" tidak dapat dicairkan pada modul ini.");
    }
}
