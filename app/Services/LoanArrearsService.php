<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;

/**
 * Tunggakan/angsuran bolong runtime (ADR D10) + info bantu saat akad (D11).
 * Tidak ada denda/sanksi; murni hitung & informasikan.
 */
class LoanArrearsService
{
    private const SCALE = 2;

    /**
     * Jumlah angsuran terlewat (due_date < hari ini, masih Belum Bayar) untuk
     * sebuah pinjaman — lintas Sebrakan & jangka panjang (keduanya punya schedule).
     */
    public function overdueCount(Loan $loan): int
    {
        return InstallmentSchedule::query()
            ->where('loan_id', $loan->id)
            ->overdue()
            ->count();
    }

    /**
     * Total angsuran terlewat anggota lintas SEMUA pinjaman (riwayat + berjalan).
     * Dipakai warning track-record saat anggota mengajukan pinjaman baru (D10).
     */
    public function memberOverdueCount(Member $member): int
    {
        return InstallmentSchedule::query()
            ->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))
            ->overdue()
            ->count();
    }

    /**
     * Jumlah angsuran yang DIBAYAR TELAT (payment_date > due_date) anggota lintas
     * semua pinjaman. Berbeda dari overdue: ini riwayat keterlambatan yang akhirnya
     * dibayar — jejaknya tidak hilang meski schedule sudah 'Terbayar' (ADR D10).
     * Hanya baris pembayaran asli (is_reversal = false) yang dihitung.
     */
    public function memberLatePaymentCount(Member $member): int
    {
        return Installment::query()
            ->where('is_reversal', false)
            ->whereColumn('payment_date', '>', 'due_date')
            ->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))
            ->count();
    }

    /**
     * Pesan warning riwayat saat create pinjaman (null bila bersih) — menggabungkan
     * tunggakan berjalan (belum dibayar & lewat tempo) dan riwayat telat bayar (D10).
     */
    public function arrearsWarning(Member $member): ?string
    {
        $overdue = $this->memberOverdueCount($member);
        $late = $this->memberLatePaymentCount($member);

        if ($overdue === 0 && $late === 0) {
            return null;
        }

        $parts = [];

        if ($overdue > 0) {
            $parts[] = "{$overdue} angsuran masih nunggak (lewat tempo, belum dibayar)";
        }

        if ($late > 0) {
            $parts[] = "{$late} angsuran pernah dibayar telat";
        }

        return 'Anggota ini memiliki '.implode(' dan ', $parts).' pada pinjaman sebelumnya/berjalan. Pertimbangkan sebelum mencatat pinjaman baru.';
    }

    /**
     * Info kapasitas potong gaji (read-only, D11): total potongan rutin berjalan
     * = Σ total tagihan bulanan pinjaman AKTIF + simpanan wajib anggota.
     */
    public function monthlyDeductionLoad(Member $member): string
    {
        $loanLoad = Loan::query()
            ->where('member_id', $member->id)
            ->where('status', LoanStatus::Cair)
            ->get()
            ->reduce(function (string $carry, Loan $loan): string {
                $monthly = bcadd(
                    bcadd((string) $loan->monthly_principal, (string) $loan->monthly_interest, self::SCALE),
                    (string) $loan->monthly_time_deposit,
                    self::SCALE
                );

                return bcadd($carry, $monthly, self::SCALE);
            }, '0');

        return bcadd($loanLoad, (string) ($member->mandatory_savings_amount ?? '0'), self::SCALE);
    }
}
