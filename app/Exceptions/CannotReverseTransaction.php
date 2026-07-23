<?php

namespace App\Exceptions;

use RuntimeException;

class CannotReverseTransaction extends RuntimeException
{
    public static function alreadyReversed(): self
    {
        return new self('Transaksi sudah pernah di-reversal.');
    }

    public static function isAReversal(): self
    {
        return new self('Tidak dapat me-reversal sebuah baris reversal.');
    }

    public static function memberInactive(): self
    {
        return new self('Tidak dapat me-reversal transaksi anggota yang sudah Keluar/Meninggal.');
    }

    public static function reasonRequired(): self
    {
        return new self('Alasan reversal wajib diisi (minimal 5 karakter).');
    }

    public static function pairedInstallmentDebit(): self
    {
        return new self('Debit angsuran dari saldo simpanan hanya bisa dibalik lewat pembatalan angsurannya, bukan dari menu Pencairan.');
    }
}
