<?php

namespace App\Exceptions;

use RuntimeException;

class CannotProcessPayment extends RuntimeException
{
    public static function loanNotActive(): self
    {
        return new self('Pinjaman sudah lunas atau tidak aktif — tidak dapat menerima angsuran.');
    }

    public static function scheduleAlreadyPaid(): self
    {
        return new self('Angsuran untuk jadwal ini sudah terbayar.');
    }

    public static function belowBill(string $item): self
    {
        return new self("Nominal {$item} tidak boleh kurang dari tagihan angsuran.");
    }
}
