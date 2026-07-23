<?php

namespace App\Services;

use App\Actions\ReverseTransaction;
use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\CannotProcessPayment;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class LoanPaymentService
{
    private const SCALE = 2;

    /** Sumber dana angsuran = debit saldo simpanan (ADR 2026-07-22). */
    private const SOURCE_SAVINGS = 'saldo_simpanan';

    /** Hanya Sukarela yang boleh didebit (hard — bukan mirror WITHDRAWABLE_TYPES). */
    private const DEBIT_SAVINGS_TYPE = 'sukarela';

    public function __construct(
        private readonly ReverseTransaction $reverse,
        private readonly WithdrawalWorkflow $workflow,
        private readonly SavingsBalanceService $balances,
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

        $fromSavings = ($input['payment_method'] ?? null) === self::SOURCE_SAVINGS;

        return DB::transaction(function () use ($schedule, $input, $causerId, $bukti, $fromSavings): Installment {
            // Debit simpanan: lock member DULU (urutan global member→loan→schedule),
            // konsisten dengan WithdrawalWorkflow::disburse agar tak deadlock.
            if ($fromSavings) {
                $memberId = Loan::query()->whereKey($schedule->loan_id)->value('member_id');
                Member::query()->lockForUpdate()->findOrFail($memberId);
            }

            /** @var Loan $loan */
            $loan = Loan::query()->lockForUpdate()->findOrFail($schedule->loan_id);

            /** @var InstallmentSchedule $schedule */
            $schedule = InstallmentSchedule::query()->lockForUpdate()->findOrFail($schedule->getKey());

            if ($loan->status !== LoanStatus::Cair) {
                throw CannotProcessPayment::loanNotActive();
            }

            if ($schedule->status === InstallmentScheduleStatus::Terbayar) {
                throw CannotProcessPayment::scheduleAlreadyPaid();
            }

            $amountPaid = $this->money($input['amount_paid']);

            $bill = $this->money($schedule->total_due);

            if (bccomp($amountPaid, $bill, self::SCALE) < 0) {
                throw CannotProcessPayment::belowBill();
            }

            if ($fromSavings) {
                // Otoritas Pengurus + atribusi (ADR §Design) — enforce di service
                // (defense-in-depth), bukan hanya di entry point Livewire.
                Gate::forUser($this->actingUser($causerId))->authorize('pay_installment_from_savings');

                // Consent WAJIB (server-side) — satu-satunya pengganti mata-kedua.
                if (! $bukti instanceof UploadedFile) {
                    throw CannotProcessPayment::consentRequired();
                }

                // Dikunci tepat-tagihan: cegah lingkaran debit sukarela → kelebihan
                // balik ke sukarela.
                if (bccomp($amountPaid, $bill, self::SCALE) > 0) {
                    throw CannotProcessPayment::savingsMustEqualBill();
                }

                if (! $this->balances->canWithdraw($loan->member, self::DEBIT_SAVINGS_TYPE, $amountPaid)) {
                    throw CannotProcessPayment::insufficientSavings();
                }
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

            if ($fromSavings) {
                $this->debitSavingsForInstallment($installment, $loan, $amountPaid, $causerId);
            }

            $excess = bcsub($amountPaid, $bill, self::SCALE);

            if (bccomp($excess, '0', self::SCALE) > 0) {
                $this->creditOverpaymentToSukarela($installment, $loan, $excess, $causerId);
            }

            $schedule->update(['status' => InstallmentScheduleStatus::Terbayar]);

            if (! $this->hasUnpaidSchedules($loan)) {
                $loan->update(['status' => LoanStatus::Lunas]);

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
     * Pelunasan Dipercepat (ADR 2026-07-22): tutup SELURUH sisa pinjaman sekaligus.
     * Jumlah pelunasan = sisa pokok + 1× jasa; jasa bulan sisa DIBEBASKAN, tabungan
     * berjangka masa depan tidak dipaksa. Satu baris `is_settlement=true` mewakili
     * penutupan (schedule_id & installment_seq null). Atomic.
     *
     * @param  array{amount_paid:string|int|float, payment_method?:string, payment_date?:string, idempotency_key?:string}  $input
     */
    public function settleEarly(
        Loan $loan,
        array $input,
        ?int $causerId = null,
        ?UploadedFile $bukti = null,
    ): Installment {
        $causerId ??= auth()->id();

        return DB::transaction(function () use ($loan, $input, $causerId, $bukti): Installment {
            /** @var Loan $loan */
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->getKey());

            if ($loan->status !== LoanStatus::Cair || $loan->loan_type !== 'jangka_panjang') {
                throw CannotProcessPayment::notSettleable();
            }

            /** @var Collection<int, InstallmentSchedule> $unpaid */
            $unpaid = InstallmentSchedule::query()
                ->where('loan_id', $loan->id)
                ->where('status', InstallmentScheduleStatus::BelumBayar)
                ->lockForUpdate()
                ->get();

            if ($unpaid->isEmpty()) {
                throw CannotProcessPayment::notSettleable();
            }

            $settledPrincipal = $loan->settledPrincipal();
            $interestCharged = $this->money($loan->monthly_interest);
            $payoff = bcadd($settledPrincipal, $interestCharged, self::SCALE);

            $amountPaid = $this->money($input['amount_paid']);

            if (bccomp($amountPaid, $payoff, self::SCALE) < 0) {
                throw CannotProcessPayment::belowSettlement($payoff);
            }

            $installment = Installment::create([
                'idempotency_key' => $input['idempotency_key'] ?? (string) Str::uuid(),
                'loan_id' => $loan->id,
                'schedule_id' => null,
                'installment_seq' => null,
                'payment_date' => $input['payment_date'] ?? now()->toDateString(),
                'due_date' => $input['payment_date'] ?? now()->toDateString(),
                'amount_paid' => $amountPaid,
                'payment_method' => $input['payment_method'] ?? 'manual',
                'is_reversal' => false,
                'is_settlement' => true,
                'recorded_by' => $causerId,
            ]);

            if ($bukti instanceof UploadedFile) {
                $installment->addMedia($bukti)->toMediaCollection('bukti');
            }

            $excess = bcsub($amountPaid, $payoff, self::SCALE);

            if (bccomp($excess, '0', self::SCALE) > 0) {
                $this->creditOverpaymentToSukarela($installment, $loan, $excess, $causerId);
            }

            InstallmentSchedule::query()
                ->whereIn('id', $unpaid->modelKeys())
                ->update(['status' => InstallmentScheduleStatus::Terbayar]);

            $loan->update(['status' => LoanStatus::Lunas]);
            $this->createRefunds($loan, $causerId);

            $waivedMonths = max(0, $unpaid->count() - 1);
            $interestWaived = bcmul($interestCharged, (string) $waivedMonths, self::SCALE);

            activity()
                ->performedOn($installment)
                ->causedBy($causerId)
                ->event('pelunasan_dipercepat')
                ->withProperties([
                    'loan_id' => $loan->id,
                    'amount_paid' => $amountPaid,
                    'settled_principal' => $settledPrincipal,
                    'interest_charged' => $interestCharged,
                    'interest_waived' => $interestWaived,
                    'excess_to_sukarela' => bccomp($excess, '0', self::SCALE) > 0 ? $excess : '0.00',
                    'schedules_closed' => $unpaid->count(),
                ])
                ->log("Pelunasan dipercepat pinjaman {$loan->loan_number}");

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

            if ($installment->is_settlement) {
                $normallyPaidScheduleIds = Installment::query()
                    ->where('loan_id', $loan->id)
                    ->where('is_settlement', false)
                    ->whereNotNull('schedule_id')
                    ->groupBy('schedule_id')
                    ->havingRaw('SUM(CASE WHEN is_reversal = 0 THEN 1 ELSE -1 END) > 0')
                    ->pluck('schedule_id');

                InstallmentSchedule::query()
                    ->where('loan_id', $loan->id)
                    ->where('status', InstallmentScheduleStatus::Terbayar)
                    ->whereNotIn('id', $normallyPaidScheduleIds)
                    ->update(['status' => InstallmentScheduleStatus::BelumBayar]);
            } elseif ($installment->schedule_id) {
                InstallmentSchedule::query()
                    ->whereKey($installment->schedule_id)
                    ->update(['status' => InstallmentScheduleStatus::BelumBayar]);
            }

            // Tarik kembali kredit Sukarela dari kelebihan bayar angsuran ini.
            $this->reverseOverpaymentCredit($installment, $reason, $causerId);

            if ($loan->status === LoanStatus::Lunas) {
                $loan->update(['status' => LoanStatus::Cair]);
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
     * Resolusi User pelaku untuk otorisasi debit simpanan. Debit sukarela =
     * uang withdrawable anggota → wajib ada pelaku terautentikasi.
     */
    private function actingUser(?int $causerId): User
    {
        $user = $causerId !== null ? User::find($causerId) : auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('Aksi debit simpanan memerlukan pengguna terautentikasi.');
        }

        return $user;
    }

    /**
     * Buat SavingsWithdrawal berpasangan (status Cair) sebagai debit sumber-dana
     * angsuran (ADR 2026-07-22). Atribusi Pengurus (`approved_by`/`approved_at`)
     * mengganti mata-kedua; `installment_id` menautkan pasangan tanpa mencemari
     * query refund pelunasan (`related_loan_id`). Saldo turun langsung — `withdrawalNet`
     * hanya menghitung baris Cair.
     */
    private function debitSavingsForInstallment(Installment $installment, Loan $loan, string $amount, ?int $causerId): void
    {
        $withdrawal = SavingsWithdrawal::create([
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $loan->member_id,
            'savings_type' => self::DEBIT_SAVINGS_TYPE,
            'amount' => $amount,
            'withdrawal_date' => now()->toDateString(),
            'status' => WithdrawalStatus::Cair,
            'approved_by' => $causerId,
            'approved_at' => now(),
            'disbursed_at' => now(),
            'installment_id' => $installment->id,
            'disbursement_method' => 'internal',
            'recorded_by' => $causerId,
            'notes' => "Debit angsuran {$installment->installment_number}",
        ]);

        activity()
            ->performedOn($withdrawal)
            ->causedBy($causerId)
            ->event('debit_simpanan_angsuran')
            ->withProperties([
                'member_id' => $loan->member_id,
                'savings_type' => self::DEBIT_SAVINGS_TYPE,
                'amount' => $amount,
                'installment_number' => $installment->installment_number,
                'loan_id' => $loan->id,
                'approved_by' => $causerId,
            ])
            ->log("Debit Simpanan Sukarela untuk angsuran {$installment->installment_number}");
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
            ->whereIn('status', [WithdrawalStatus::Draft, WithdrawalStatus::Acc, WithdrawalStatus::Cair])
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
            ->whereIn('status', [WithdrawalStatus::Draft, WithdrawalStatus::Acc, WithdrawalStatus::Cair])
            ->get();

        foreach ($refunds as $refund) {
            if ($refund->status === WithdrawalStatus::Cair) {
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
            ->where('status', InstallmentScheduleStatus::BelumBayar)
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
