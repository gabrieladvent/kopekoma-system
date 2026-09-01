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

    /**
     * Setoran cukup melunasi SELURUH sisa pinjaman (ADR 2026-08-28). Diproses
     * diam-diam sebagai "tutup angsuran sekalian", anggota membayar jasa jauh
     * lebih banyak tanpa ada yang sadar — menutup k angsuran berharga
     * `k × tagihan`, sedangkan pelunasan hanya `k × pokok + 1× jasa`.
     */
    public static function shouldSettleEarly(string $payoff): self
    {
        return new self('Nominal ini cukup untuk melunasi seluruh sisa pinjaman. Gunakan Pelunasan Dipercepat — jumlahnya Rp '.number_format((float) $payoff, 0, ',', '.').', lebih ringan bagi anggota karena jasa bulan sisa dibebaskan.');
    }

    /**
     * Saldo Titipan Pokok berubah antara pratinjau dan simpan (ADR 2026-08-28
     * item 1f). Ditolak, bukan disimpan diam-diam dengan hasil berbeda dari yang
     * dikonfirmasi petugas di depan anggota.
     */
    public static function stalePreview(string $currentCredit): self
    {
        return new self('Saldo Titipan Pokok berubah sejak rincian ini dihitung (kini Rp '.number_format((float) $currentCredit, 0, ',', '.').'). Ulangi pembayaran agar angkanya benar.');
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
