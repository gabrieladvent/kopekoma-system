<?php

namespace App\Services;

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotProcessPayment;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pembayaran angsuran (ADR D5/D8 + amendment 2026-06-26). Prinsip:
 * - Petugas input SATU nominal diterima (`amount_paid`), divalidasi ≥ tagihan
 *   bulan ini (`installment_schedules.total_due` = Σ konstanta). Lebih = sah,
 *   jadi pos "Kelebihan Bayar" yang dihitung di nota (tak disimpan).
 * - Breakdown (Pokok/Jasa/Tab), sisa pokok, & saldo Tab Berjangka DIHITUNG dari
 *   konstanta `loans.monthly_*` × jumlah angsuran terbayar (net reversal).
 * - Pelunasan (angsuran terakhir → Lunas) memicu pembuatan refund SWP + Tabungan
 *   Berjangka sebagai DRAFT (D1) — saldo baru berkurang saat pengurus mencairkan.
 *   Reversal pelunasan membatalkan refund yatim: draft/acc di-reject, cair
 *   di-reverse (D4). Auto-create idempoten (D5) — satu refund aktif per tipe.
 */
class LoanPaymentService
{
    private const SCALE = 2;

    public function __construct(
        private readonly ReverseTransaction $reverse,
        private readonly WithdrawalWorkflow $workflow,
    ) {}

    /**
     * Catat pembayaran satu angsuran (jadwal). Atomic.
     *
     * @param  array{amount_paid:string|int|float, payment_method?:string, payment_date?:string, idempotency_key?:string}  $input
     */
    public function pay(
        InstallmentSchedule $schedule,
        array $input,
        ?int $causerId = null,
        ?UploadedFile $bukti = null,
    ): Installment {
        $causerId ??= auth()->id();

        return DB::transaction(function () use ($schedule, $input, $causerId, $bukti): Installment {
            /** @var Loan $loan */
            $loan = Loan::query()->lockForUpdate()->findOrFail($schedule->loan_id);
            /** @var InstallmentSchedule $schedule */
            $schedule = InstallmentSchedule::query()->lockForUpdate()->findOrFail($schedule->getKey());

            if ($loan->status !== 'Cair') {
                throw CannotProcessPayment::loanNotActive();
            }

            if ($schedule->status === 'Terbayar') {
                throw CannotProcessPayment::scheduleAlreadyPaid();
            }

            $amountPaid = $this->money($input['amount_paid']);

            // Validasi anti-korupsi total-level (ADR 2026-06-26 D4): nominal
            // diterima tak boleh kurang dari tagihan bulan ini. Lebih = sah →
            // pos "Kelebihan Bayar" yang dihitung di nota (tak disimpan).
            $bill = $this->money($schedule->total_due);
            if (bccomp($amountPaid, $bill, self::SCALE) < 0) {
                throw CannotProcessPayment::belowBill();
            }

            $installment = Installment::create([
                'idempotency_key' => $input['idempotency_key'] ?? (string) Str::uuid(),
                'loan_id' => $loan->id,
                'schedule_id' => $schedule->getKey(),
                'installment_seq' => $schedule->installment_seq,
                'payment_date' => $input['payment_date'] ?? now()->toDateString(),
                'due_date' => $schedule->due_date,
                'amount_paid' => $amountPaid,
                'payment_method' => $input['payment_method'] ?? 'manual',
                'is_reversal' => false,
                'recorded_by' => $causerId,
            ]);

            if ($bukti instanceof UploadedFile) {
                $installment->addMedia($bukti)->toMediaCollection('bukti');
            }

            // Kelebihan bayar (amount_paid − tagihan) dikreditkan ke Simpanan
            // Sukarela anggota — bisa dicairkan kapan saja. Tak menyentuh pokok
            // (count-based) maupun konstanta breakdown.
            $excess = bcsub($amountPaid, $bill, self::SCALE);
            if (bccomp($excess, '0', self::SCALE) > 0) {
                $this->creditOverpaymentToSukarela($installment, $loan, $excess, $causerId);
            }

            $schedule->update(['status' => 'Terbayar']);

            // Auto-Lunas: semua jadwal terbayar → Lunas + refund SWP/Tab (atomik).
            if (! $this->hasUnpaidSchedules($loan)) {
                $loan->update(['status' => 'Lunas']);
                $this->createRefunds($loan, $causerId);
            }

            activity()
                ->performedOn($installment)
                ->causedBy($causerId)
                ->event('angsuran')
                ->withProperties(['loan_id' => $loan->id, 'amount_paid' => $amountPaid, 'seq' => $schedule->installment_seq])
                ->log("Pembayaran angsuran {$installment->installment_number}");

            return $installment;
        });
    }

    /**
     * Reversal pembayaran angsuran. Membalik jadwal ke Belum Bayar; bila pinjaman
     * sudah Lunas, kembalikan ke Cair dan batalkan refund SWP/Tab terkait (D8/M2).
     */
    public function reverse(Installment $installment, string $reason, ?int $causerId = null): Installment
    {
        $causerId ??= auth()->id();

        return DB::transaction(function () use ($installment, $reason, $causerId): Installment {
            $reversal = ($this->reverse)($installment, $reason, $causerId);

            /** @var Loan $loan */
            $loan = Loan::query()->lockForUpdate()->findOrFail($installment->loan_id);

            if ($installment->schedule_id) {
                InstallmentSchedule::query()
                    ->whereKey($installment->schedule_id)
                    ->update(['status' => 'Belum Bayar']);
            }

            // Tarik kembali kredit Sukarela dari kelebihan bayar angsuran ini.
            $this->reverseOverpaymentCredit($installment, $reason, $causerId);

            if ($loan->status === 'Lunas') {
                $loan->update(['status' => 'Cair']);
                $this->cleanupRefunds($loan, $reason, $causerId);
            }

            return $reversal;
        });
    }

    /**
     * Kredit kelebihan bayar ke Simpanan Sukarela anggota. Ditautkan ke angsuran
     * via `reference_number` (= installment_number) agar bisa ditarik kembali saat
     * angsuran di-reverse. Aktivitas tercatat otomatis (SavingsDeposit LogsActivity)
     * + log eksplisit di bawah agar jejaknya jelas.
     */
    private function creditOverpaymentToSukarela(Installment $installment, Loan $loan, string $excess, ?int $causerId): void
    {
        $deposit = SavingsDeposit::create([
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $loan->member_id,
            'savings_type' => 'sukarela',
            'amount' => $excess,
            'deposit_date' => $installment->payment_date?->toDateString() ?? now()->toDateString(),
            'deposit_method' => $installment->payment_method === 'potong_gaji' ? 'potong_gaji' : 'setor_sendiri',
            'deposited_by' => 'bendahara',
            'reference_number' => $installment->installment_number,
            'notes' => "Pengalihan kelebihan dana dari angsuran {$installment->installment_number}",
            'recorded_by' => $causerId,
        ]);

        activity()
            ->performedOn($deposit)
            ->causedBy($causerId)
            ->event('kelebihan_bayar')
            ->withProperties([
                'installment_number' => $installment->installment_number,
                'loan_id' => $loan->id,
                'amount' => $excess,
            ])
            ->log("Pengalihan kelebihan dana angsuran {$installment->installment_number} ke Simpanan Sukarela");
    }

    /**
     * Tarik kembali kredit Sukarela saat angsuran di-reverse: balikkan deposit
     * sukarela yang tertaut (non-reversal, belum dibalik) via mekanisme reversal
     * generik agar saldo ter-net ke semula.
     */
    private function reverseOverpaymentCredit(Installment $installment, string $reason, ?int $causerId): void
    {
        $reversedIds = SavingsDeposit::query()
            ->whereNotNull('reversal_of_id')
            ->pluck('reversal_of_id');

        SavingsDeposit::query()
            ->where('reference_number', $installment->installment_number)
            ->where('savings_type', 'sukarela')
            ->where('is_reversal', false)
            ->whereNotIn('id', $reversedIds)
            ->get()
            ->each(fn (SavingsDeposit $deposit) => ($this->reverse)($deposit, $reason, $causerId));
    }

    private function createRefunds(Loan $loan, ?int $causerId): void
    {
        // Metode pengembalian diwarisi dari pinjaman (ditetapkan saat akad) — satu
        // sumber kebenaran, terekam sejak awal. Fallback 'tunai' untuk pinjaman
        // lama yang belum punya disbursement_method.
        $method = $loan->disbursement_method ?? 'tunai';

        $swp = (string) $loan->swp_amount;
        if (bccomp($swp, '0', self::SCALE) > 0 && ! $this->hasActiveRefund($loan, 'swp')) {
            $this->makeRefund($loan, 'swp', $swp, $method, $causerId);
        }

        $tab = $this->loanTimeDepositAccrued($loan);
        if (bccomp($tab, '0', self::SCALE) > 0 && ! $this->hasActiveRefund($loan, 'tabungan_berjangka')) {
            $this->makeRefund($loan, 'tabungan_berjangka', $tab, $method, $causerId);
        }
    }

    /**
     * Refund auto sebagai DRAFT (D1) — saldo baru berkurang saat pengurus cair-kan
     * lewat WithdrawalWorkflow. Metode pencairan dititip di draft untuk dipakai saat
     * disburse.
     */
    private function makeRefund(Loan $loan, string $type, string $amount, string $method, ?int $causerId): void
    {
        SavingsWithdrawal::create([
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $loan->member_id,
            'savings_type' => $type,
            'amount' => $amount,
            'withdrawal_date' => now()->toDateString(),
            'status' => 'draft',
            'related_loan_id' => $loan->id,
            'disbursement_method' => $method,
            'recorded_by' => $causerId,
            'notes' => "Pengembalian saat pelunasan pinjaman {$loan->loan_number}",
        ]);
    }

    /**
     * Idempotensi (D5): ada refund aktif (draft/acc/cair, non-reversal) bertipe ini
     * untuk pinjaman ini? Refund yang sudah `ditolak` tak menghalangi pembuatan baru
     * (mis. bayar → lunas → reverse → bayar lagi).
     */
    private function hasActiveRefund(Loan $loan, string $type): bool
    {
        return SavingsWithdrawal::query()
            ->where('related_loan_id', $loan->id)
            ->where('savings_type', $type)
            ->where('is_reversal', false)
            ->whereIn('status', ['draft', 'acc', 'cair'])
            ->exists();
    }

    /**
     * Bersihkan refund yatim saat pelunasan dibatalkan (D4): draft/acc → reject
     * (terminal ditolak); cair → reverse (reversal-clone generik). Tak ada
     * hard-delete dokumen bernomor.
     */
    private function cleanupRefunds(Loan $loan, string $reason, ?int $causerId): void
    {
        /** @var Collection<int, SavingsWithdrawal> $refunds */
        $refunds = SavingsWithdrawal::query()
            ->where('related_loan_id', $loan->id)
            ->where('is_reversal', false)
            ->whereIn('savings_type', ['swp', 'tabungan_berjangka'])
            ->whereIn('status', ['draft', 'acc', 'cair'])
            ->get();

        foreach ($refunds as $refund) {
            if ($refund->status === 'cair') {
                ($this->reverse)($refund, $reason, $causerId);
            } else {
                $this->workflow->reject($refund, $causerId);
            }
        }
    }

    private function hasUnpaidSchedules(Loan $loan): bool
    {
        return InstallmentSchedule::query()
            ->where('loan_id', $loan->id)
            ->where('status', 'Belum Bayar')
            ->exists();
    }

    /**
     * Tabungan Berjangka terakumulasi pinjaman ini = `monthly_time_deposit` ×
     * jumlah angsuran terbayar (net reversal), via scope count-based. Satu rumus
     * dengan saldo (SavingsBalanceService) agar refund yang dibatalkan match.
     */
    private function loanTimeDepositAccrued(Loan $loan): string
    {
        $net = Installment::query()
            ->where('installments.loan_id', $loan->id)
            ->signedTimeDeposit()
            ->value('net');

        return bcadd((string) ($net ?? '0'), '0', self::SCALE);
    }

    private function money(string|int|float $value): string
    {
        return bcadd((string) $value, '0', self::SCALE);
    }
}
