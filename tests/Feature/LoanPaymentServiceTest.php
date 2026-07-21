<?php

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\CannotProcessPayment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use App\Services\LoanPaymentService;
use App\Services\SavingsBalanceService;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->service = app(LoanPaymentService::class);
    $this->member = Member::factory()->create();
    $this->user = User::factory()->create();
    $this->balances = app(SavingsBalanceService::class);
});

/** Pinjaman jangka panjang 1.000.000 dengan N jadwal identik (konstan). */
function makeLoan(string $memberId, int $schedules = 1, float $swp = 10000, string $disbursementMethod = 'tunai'): array
{
    $loan = Loan::factory()->create([
        'member_id' => $memberId,
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 1000000,
        'swp_amount' => $swp,
        'term_months' => $schedules,
        'monthly_principal' => 1000000,
        'monthly_interest' => 6500,
        'monthly_time_deposit' => 1000,
        'disbursement_method' => $disbursementMethod,
    ]);

    $rows = collect(range(1, $schedules))->map(fn ($seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 6500,
        'time_deposit_due' => 1000,
        'total_due' => 1007500,
    ]));

    return [$loan, $rows];
}

function billPayment(): array
{
    return ['amount_paid' => 1007500];
}

it('records a payment, marks the schedule paid, and computes remaining principal', function () {
    [$loan, $rows] = makeLoan($this->member->id, schedules: 2);

    $inst = $this->service->pay($rows[0], billPayment(), $this->user->id);

    expect($inst->amount_paid)->toBe('1007500.00')
        ->and($loan->fresh()->remainingPrincipal())->toBe('0.00')
        ->and($rows[0]->fresh()->status)->toBe(InstallmentScheduleStatus::Terbayar)
        ->and($loan->fresh()->status)->toBe(LoanStatus::Cair); // belum semua terbayar
});

it('rejects a payment below the billed amount (anti-corruption)', function () {
    [$loan, $rows] = makeLoan($this->member->id);

    expect(fn () => $this->service->pay($rows[0], ['amount_paid' => 1007499], $this->user->id))
        ->toThrow(CannotProcessPayment::class);
});

it('records overpayment as "Lain-lain" without inflating tabungan berjangka or principal', function () {
    [$loan, $rows] = makeLoan($this->member->id, schedules: 1, swp: 10000);

    // bayar 1.100.000, tagihan 1.007.500 → kelebihan 92.500 = Lain-lain
    $inst = $this->service->pay($rows[0], ['amount_paid' => 1100000], $this->user->id);

    expect($inst->amount_paid)->toBe('1100000.00')
        ->and($inst->breakdown()['principal'])->toBe('1000000.00')
        ->and($inst->breakdown()['interest'])->toBe('6500.00')
        ->and($inst->breakdown()['time_deposit'])->toBe('1000.00')
        ->and($inst->breakdown()['other'])->toBe('92500.00')
        ->and($loan->fresh()->status)->toBe(LoanStatus::Lunas);

    // Tab berjangka = konstanta (1000), TIDAK bertambah dari kelebihan
    $tab = SavingsWithdrawal::where('related_loan_id', $loan->id)
        ->where('savings_type', 'tabungan_berjangka')->first();
    expect($tab->amount)->toBe('1000.00');
});

it('credits installment overpayment to the member sukarela savings', function () {
    [$loan, $rows] = makeLoan($this->member->id, schedules: 2, swp: 10000);

    // tagihan 1.007.500; bayar 1.107.500 → kelebihan 100.000 → Simpanan Sukarela
    $this->service->pay($rows[0], ['amount_paid' => 1107500], $this->user->id);

    $deposit = SavingsDeposit::where('member_id', $this->member->id)
        ->where('savings_type', 'sukarela')->first();

    expect($deposit)->not->toBeNull()
        ->and($deposit->amount)->toBe('100000.00')
        ->and($this->balances->balanceByType($this->member, 'sukarela'))->toBe('100000.00');
});

it('logs the sukarela credit of an installment overpayment', function () {
    [$loan, $rows] = makeLoan($this->member->id, schedules: 2, swp: 10000);
    $this->service->pay($rows[0], ['amount_paid' => 1107500], $this->user->id);

    $deposit = SavingsDeposit::where('member_id', $this->member->id)->where('savings_type', 'sukarela')->first();

    // Log eksplisit kelebihan_bayar + auto-log pembuatan deposit (LogsActivity).
    expect(Activity::where('event', 'kelebihan_bayar')->exists())->toBeTrue()
        ->and(Activity::where('subject_type', $deposit->getMorphClass())
            ->where('subject_id', $deposit->id)->exists())->toBeTrue();
});

it('does not create a sukarela deposit when payment equals the bill exactly', function () {
    [$loan, $rows] = makeLoan($this->member->id, schedules: 2, swp: 10000);

    $this->service->pay($rows[0], billPayment(), $this->user->id);

    expect(SavingsDeposit::where('member_id', $this->member->id)->where('savings_type', 'sukarela')->exists())->toBeFalse();
});

it('reverses the sukarela overpayment deposit when the installment is reversed', function () {
    [$loan, $rows] = makeLoan($this->member->id, schedules: 2, swp: 10000);
    $inst = $this->service->pay($rows[0], ['amount_paid' => 1107500], $this->user->id);
    expect($this->balances->balanceByType($this->member, 'sukarela'))->toBe('100000.00');

    $this->service->reverse($inst, 'salah input nominal', $this->user->id);

    expect($this->balances->balanceByType($this->member, 'sukarela'))->toBe('0.00');
});

it('auto-settles the loan and creates DRAFT refunds for SWP + tabungan berjangka on final payment', function () {
    // Metode refund diwarisi dari pinjaman (disbursement_method), bukan argumen.
    [$loan, $rows] = makeLoan($this->member->id, schedules: 1, swp: 10000, disbursementMethod: 'transfer');

    $this->service->pay($rows[0], billPayment(), $this->user->id);

    expect($loan->fresh()->status)->toBe(LoanStatus::Lunas);

    $swp = SavingsWithdrawal::where('related_loan_id', $loan->id)->where('savings_type', 'swp')->first();
    $tab = SavingsWithdrawal::where('related_loan_id', $loan->id)->where('savings_type', 'tabungan_berjangka')->first();

    expect($swp->amount)->toBe('10000.00')
        ->and($swp->status)->toBe(WithdrawalStatus::Draft)
        ->and($swp->disbursed_at)->toBeNull()
        ->and($swp->disbursement_method)->toBe('transfer')
        ->and($tab->amount)->toBe('1000.00')
        ->and($tab->status)->toBe(WithdrawalStatus::Draft)
        // draft belum kurangi saldo — refund menunggu persetujuan (D3)
        ->and($this->balances->balanceByType($this->member, 'swp'))->toBe('10000.00')
        ->and($this->balances->balanceByType($this->member, 'tabungan_berjangka'))->toBe('1000.00');
});

it('reverses a settlement: loan back to Cair and DRAFT refunds rejected, not reversed (D4)', function () {
    [$loan, $rows] = makeLoan($this->member->id, schedules: 1, swp: 10000);
    $inst = $this->service->pay($rows[0], billPayment(), $this->user->id);

    $this->service->reverse($inst, 'Salah catat nominal angsuran', $this->user->id);

    expect($loan->fresh()->status)->toBe(LoanStatus::Cair)
        ->and($rows[0]->fresh()->status)->toBe(InstallmentScheduleStatus::BelumBayar)
        ->and($this->balances->balanceByType($this->member, 'swp'))->toBe('10000.00')
        ->and($this->balances->balanceByType($this->member, 'tabungan_berjangka'))->toBe('0.00');

    // Draft refund di-reject (terminal ditolak), BUKAN reversal-clone (D4).
    $swp = SavingsWithdrawal::where('related_loan_id', $loan->id)->where('savings_type', 'swp')->first();
    expect($swp->status)->toBe(WithdrawalStatus::Ditolak)
        ->and(SavingsWithdrawal::where('related_loan_id', $loan->id)->where('is_reversal', true)->exists())->toBeFalse();
});

it('does not duplicate refunds when a settled loan is reversed then re-paid (D5)', function () {
    [$loan, $rows] = makeLoan($this->member->id, schedules: 1, swp: 10000);
    $inst = $this->service->pay($rows[0], billPayment(), $this->user->id);
    $this->service->reverse($inst, 'koreksi', $this->user->id);

    $this->service->pay($rows[0]->fresh(), billPayment(), $this->user->id);

    // Satu refund AKTIF (draft) per tipe; yang lama berstatus ditolak.
    expect(SavingsWithdrawal::where('related_loan_id', $loan->id)
        ->where('savings_type', 'swp')->where('status', 'draft')->where('is_reversal', false)->count())->toBe(1);
});

it('rejects paying a schedule that is already paid', function () {
    [$loan, $rows] = makeLoan($this->member->id, schedules: 2);
    $this->service->pay($rows[0], billPayment(), $this->user->id);

    expect(fn () => $this->service->pay($rows[0]->fresh(), billPayment(), $this->user->id))
        ->toThrow(CannotProcessPayment::class);
});
