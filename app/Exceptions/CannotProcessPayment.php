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

    public static function belowBill(): self
    {
        return new self('Nominal yang dibayar tidak boleh kurang dari tagihan angsuran bulan ini.');
    }

    public static function belowSettlement(string $payoff): self
    {
        return new self('Nominal yang dibayar kurang untuk pelunasan. Jumlah pelunasan (sisa pokok + 1× jasa) = Rp '.number_format((float) $payoff, 0, ',', '.').'.');
    }

    public static function notSettleable(): self
    {
        return new self('Pinjaman ini tidak dapat dilunasi dipercepat (harus jangka panjang berstatus Cair).');
    }

    public static function insufficientSavings(): self
    {
        return new self('Saldo Simpanan Sukarela anggota tidak mencukupi untuk membayar angsuran ini.');
    }

    public static function consentRequired(): self
    {
        return new self('Bukti persetujuan anggota wajib diunggah untuk pembayaran dari saldo simpanan.');
    }

    public static function savingsMustEqualBill(): self
    {
        return new self('Pembayaran dari saldo simpanan harus tepat sebesar tagihan — kelebihan tidak diperbolehkan.');
    }
}
