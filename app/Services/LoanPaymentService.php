<?php

namespace App\Services;

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotProcessPayment;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\SavingsWithdrawal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pembayaran angsuran (ADR D5/D8 + amendment 2026-06-26). Prinsip:
 * - Petugas input SATU nominal diterima (`amount_paid`), divalidasi ≥ tagihan
 *   bulan ini (`installment_schedules.total_due` = Σ konstanta). Lebih = sah,
 *   jadi pos "Lain-lain" yang dihitung di nota (tak disimpan).
 * - Breakdown (Pokok/Jasa/Tab), sisa pokok, & saldo Tab Berjangka DIHITUNG dari
 *   konstanta `loans.monthly_*` × jumlah angsuran terbayar (net reversal).
 * - Pelunasan (angsuran terakhir → Lunas) memicu refund SWP + Tabungan Berjangka
 *   secara atomik. Reversal pelunasan membatalkan refund (tak ada refund yatim).
 */
class LoanPaymentService
{
    private const SCALE = 2;

    public function __construct(private readonly ReverseTransaction $reverse) {}

    /**
     * Catat pembayaran satu angsuran (jadwal). Atomic.
     *
     * @param  array{amount_paid:string|int|float, payment_method?:string, payment_date?:string, idempotency_key?:string}  $input
     */
    public function pay(
        InstallmentSchedule $schedule,
        array $input,
        ?int $causerId = null,
        string $refundMethod = 'tunai',
        ?UploadedFile $bukti = null,
    ): Installment {
        $causerId ??= auth()->id();

        return DB::transaction(function () use ($schedule, $input, $causerId, $refundMethod, $bukti): Installment {
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
            // pos "Lain-lain" yang dihitung di nota (tak disimpan).
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

            $schedule->update(['status' => 'Terbayar']);

            // Auto-Lunas: semua jadwal terbayar → Lunas + refund SWP/Tab (atomik).
            if (! $this->hasUnpaidSchedules($loan)) {
                $loan->update(['status' => 'Lunas']);
                $this->createRefunds($loan, $refundMethod, $causerId);
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

            if ($loan->status === 'Lunas') {
                $loan->update(['status' => 'Cair']);
                $this->reverseRefunds($loan, $reason, $causerId);
            }

            return $reversal;
        });
    }

    private function createRefunds(Loan $loan, string $method, ?int $causerId): void
    {
        $swp = (string) $loan->swp_amount;
        if (bccomp($swp, '0', self::SCALE) > 0) {
            $this->makeRefund($loan, 'swp', $swp, $method, $causerId);
        }

        $tab = $this->loanTimeDepositAccrued($loan);
        if (bccomp($tab, '0', self::SCALE) > 0) {
            $this->makeRefund($loan, 'tabungan_berjangka', $tab, $method, $causerId);
        }
    }

    private function makeRefund(Loan $loan, string $type, string $amount, string $method, ?int $causerId): void
    {
        SavingsWithdrawal::create([
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $loan->member_id,
            'savings_type' => $type,
            'amount' => $amount,
            'withdrawal_date' => now()->toDateString(),
            'status' => 'cair',
            'disbursed_at' => now(),
            'related_loan_id' => $loan->id,
            'disbursement_method' => $method,
            'recorded_by' => $causerId,
            'notes' => "Pengembalian saat pelunasan pinjaman {$loan->loan_number}",
        ]);
    }

    private function reverseRefunds(Loan $loan, string $reason, ?int $causerId): void
    {
        $reversedIds = SavingsWithdrawal::query()
            ->whereNotNull('reversal_of_id')
            ->pluck('reversal_of_id');

        /** @var Collection<int, SavingsWithdrawal> $refunds */
        $refunds = SavingsWithdrawal::query()
            ->where('related_loan_id', $loan->id)
            ->where('is_reversal', false)
            ->whereIn('savings_type', ['swp', 'tabungan_berjangka'])
            ->whereNotIn('id', $reversedIds)
            ->get();

        foreach ($refunds as $refund) {
            ($this->reverse)($refund, $reason, $causerId);
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
