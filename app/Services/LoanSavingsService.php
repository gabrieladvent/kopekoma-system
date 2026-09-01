<?php

namespace App\Services;

use App\Actions\ReverseTransaction;
use App\Enums\LoanStatus;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\SavingsDeposit;
use Illuminate\Support\Str;

/**
 * SWP & Tabungan Berjangka sebagai simpanan sungguhan.
 *
 * Keduanya diperlakukan persis seperti `pokok`/`wajib`/`sukarela`: setiap
 * rupiah yang masuk punya baris `savings_deposits` sendiri — bernomor
 * transaksi, bertanggal, bisa dibalik, terlihat di buku mutasi anggota.
 *
 * Yang membedakan hanya **pintu masuknya**, dan pintunya cuma dua:
 *
 *   - **SWP** — sekali, saat pinjaman cair. Nominalnya `loans.swp_amount`,
 *     sudah dipotong dari dana yang diterima anggota.
 *   - **Tabungan Berjangka** — per angsuran yang benar-benar dibayar. Baris
 *     pelunasan dipercepat (`is_settlement`) TIDAK mengakru: jasa bulan sisa
 *     dibebaskan, dan tabungan bulan sisa memang tak pernah disetor.
 *
 * Tak ada setoran manual untuk kedua jenis ini. Bila suatu saat layar setoran
 * membolehkannya, saldo akan naik tanpa pinjaman yang menaunginya — dan tak ada
 * satu pun angka di sistem yang bisa membantahnya.
 *
 * **Pengembalian saat lunas dicabut.** Dulu pelunasan otomatis menerbitkan
 * draft pencairan untuk kedua jenis ini. Sekarang tidak: uangnya tetap jadi
 * simpanan anggota di jenisnya masing-masing, dan anggota menariknya kapan ia
 * mau lewat alur penarikan biasa — yang sudah punya gerbang draft → ACC → cair.
 * Mata-keduanya tidak hilang, ia pindah ke saat anggota benar-benar meminta
 * uangnya, bukan saat pinjamannya kebetulan lunas.
 */
class LoanSavingsService
{
    private const SCALE = 2;

    public function __construct(private readonly ReverseTransaction $reverse) {}

    /**
     * Setoran SWP saat pinjaman cair. Idempotent — dipanggil dua kali untuk
     * pinjaman yang sama tidak menghasilkan baris kedua.
     */
    public function recordSwp(Loan $loan): ?SavingsDeposit
    {
        if ($loan->status !== LoanStatus::Cair) {
            return null;
        }

        $amount = $this->money($loan->swp_amount);

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            return null;
        }

        if ($this->hasActiveDeposit($loan->loan_number, 'swp')) {
            return null;
        }

        return SavingsDeposit::create([
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $loan->member_id,
            'savings_type' => 'swp',
            'amount' => $amount,
            'deposit_date' => $loan->disbursement_date?->toDateString() ?? now()->toDateString(),
            'period_month' => $loan->disbursement_date?->startOfMonth()->toDateString() ?? now()->startOfMonth()->toDateString(),
            // Dipotong langsung dari dana pencairan, bukan disetor anggota di
            // loket maupun lewat gaji — 'setor_sendiri' dipakai sebagai lawan
            // dari potong gaji; asal-usul sebenarnya ada di catatan & referensi.
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'bendahara',
            'reference_number' => $loan->loan_number,
            'notes' => "Potongan SWP saat pencairan pinjaman {$loan->loan_number}",
            'recorded_by' => $loan->recorded_by,
        ]);
    }

    /**
     * Setoran Tabungan Berjangka untuk satu baris angsuran.
     *
     * Baris pelunasan dilewati — akrualnya count-based atas angsuran biasa, dan
     * baris pelunasan mewakili penutupan, bukan satu bulan tagihan.
     */
    public function recordTimeDeposit(Installment $installment, Loan $loan): ?SavingsDeposit
    {
        if ($installment->is_settlement) {
            return null;
        }

        $amount = $this->money($loan->monthly_time_deposit);

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            return null;
        }

        return SavingsDeposit::create([
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $loan->member_id,
            'savings_type' => 'tabungan_berjangka',
            'amount' => $amount,
            'deposit_date' => $installment->payment_date?->toDateString() ?? now()->toDateString(),
            'period_month' => $installment->due_date?->startOfMonth()->toDateString()
                ?? $installment->payment_date?->startOfMonth()->toDateString()
                ?? now()->startOfMonth()->toDateString(),
            'deposit_method' => $installment->payment_method === 'potong_gaji' ? 'potong_gaji' : 'setor_sendiri',
            'deposited_by' => 'bendahara',
            // Ditautkan lewat nomor angsuran — sama seperti pengalihan kelebihan
            // dana ke Sukarela, jadi pembatalannya memakai mesin pencarian yang
            // sama dan tak perlu kolom penaut baru.
            'reference_number' => $installment->installment_number,
            'notes' => "Tabungan Berjangka angsuran {$installment->installment_number}",
            'recorded_by' => $installment->recorded_by,
        ]);
    }

    /**
     * Balik setoran SWP saat pinjaman dibatalkan (salah input).
     *
     * Tanpa ini, membatalkan pinjaman meninggalkan simpanan SWP yang tak punya
     * pinjaman di belakangnya — saldo anggota naik permanen karena kesalahan
     * ketik petugas.
     */
    public function reverseSwp(Loan $loan, string $reason, ?int $causerId = null): void
    {
        $this->reverseDepositsFor($loan->loan_number, 'swp', $reason, $causerId);
    }

    /**
     * Balik setoran Tabungan Berjangka saat baris angsurannya dibatalkan.
     *
     * Pasangannya wajib ikut: angsuran yang batal berarti bulan itu tak pernah
     * dibayar, jadi tabungannya juga tak pernah disetor.
     */
    public function reverseTimeDeposit(Installment $installment, string $reason, ?int $causerId = null): void
    {
        $this->reverseDepositsFor($installment->installment_number, 'tabungan_berjangka', $reason, $causerId);
    }

    /**
     * Balik seluruh setoran non-reversal bertipe ini yang tertaut ke referensi
     * tersebut dan BELUM pernah dibalik. Pola `whereNotIn(reversed_ids)` sama
     * dengan jalur pembalikan lain agar dua kali pembatalan tidak menumpuk dua
     * baris-lawan atas satu setoran.
     */
    private function reverseDepositsFor(?string $reference, string $type, string $reason, ?int $causerId): void
    {
        if (blank($reference)) {
            return;
        }

        $reversedIds = SavingsDeposit::query()
            ->whereNotNull('reversal_of_id')
            ->pluck('reversal_of_id');

        SavingsDeposit::query()
            ->where('reference_number', $reference)
            ->where('savings_type', $type)
            ->where('is_reversal', false)
            ->whereNotIn('id', $reversedIds)
            ->get()
            // `allowInactiveMember` — membalik SETORAN menurunkan saldo anggota,
            // tapi ia pasangan wajib dari transaksi yang dibatalkan. Menolaknya
            // karena anggota sudah Keluar akan meninggalkan simpanan yatim yang
            // tak bisa dibersihkan siapa pun.
            ->each(fn (SavingsDeposit $deposit) => ($this->reverse)($deposit, $reason, $causerId, allowInactiveMember: true));
    }

    private function hasActiveDeposit(?string $reference, string $type): bool
    {
        if (blank($reference)) {
            return false;
        }

        return SavingsDeposit::query()
            ->where('reference_number', $reference)
            ->where('savings_type', $type)
            ->where('is_reversal', false)
            ->exists();
    }

    private function money(string|int|float|null $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', self::SCALE);
    }
}
