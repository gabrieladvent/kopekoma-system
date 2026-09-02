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

    /**
     * Aturan cadangan saat bulan pembagian SHU belum ditetapkan: pencairan kedua
     * di dalam 12 bulan ditolak.
     */
    public static function timeDepositNotDueYet(string $lastDisbursedOn, string $nextEligibleOn): self
    {
        return new self(
            'Tabungan Berjangka dikembalikan satu kali dalam setahun. Pencairan terakhir '.$lastDisbursedOn.
            ', jadi pencairan berikutnya baru boleh mulai '.$nextEligibleOn.'.'
        );
    }

    /**
     * Di luar jendela pembagian SHU. Pesannya menyebut bulannya, bukan sekadar
     * "belum waktunya" — petugas perlu tahu kapan harus kembali.
     */
    public static function timeDepositOutsideShuWindow(string $shuMonth, string $nextWindow): self
    {
        return new self(
            'Tabungan Berjangka hanya dapat dicairkan pada bulan pembagian SHU ('.$shuMonth.
            '). Jendela berikutnya: '.$nextWindow.'.'
        );
    }

    /** Sudah dicairkan tahun ini — jendela SHU tak boleh dipakai dua kali. */
    public static function timeDepositAlreadyThisYear(string $lastDisbursedOn, string $nextWindow): self
    {
        return new self(
            'Tabungan Berjangka sudah dicairkan tahun ini ('.$lastDisbursedOn.
            '). Pencairan berikutnya pada jendela SHU '.$nextWindow.'.'
        );
    }
}
