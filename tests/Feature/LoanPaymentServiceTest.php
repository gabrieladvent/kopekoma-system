<?php

use App\Exceptions\CannotProcessPayment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use App\Services\LoanPaymentService;
use App\Services\SavingsBalanceService;

beforeEach(function () {
    $this->service = app(LoanPaymentService::class);
    $this->member = Member::factory()->create();
    $this->user = User::factory()->create();
    $this->balances = app(SavingsBalanceService::class);
});

/** Pinjaman jangka panjang 1.000.000 dengan N jadwal identik (konstan). */
function makeLoan(string $memberId, int $schedules = 1, float $swp = 10000): array
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
        ->and($rows[0]->fresh()->status)->toBe('Terbayar')
        ->and($loan->fresh()->status)->toBe('Cair'); // belum semua terbayar
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
        ->and($loan->fresh()->status)->toBe('Lunas');

    // Tab berjangka = konstanta (1000), TIDAK bertambah dari kelebihan
    $tab = SavingsWithdrawal::where('related_loan_id', $loan->id)
        ->where('savings_type', 'tabungan_berjangka')->first();
    expect($tab->amount)->toBe('1000.00');
});

it('auto-settles the loan and refunds SWP + tabungan berjangka on final payment', function () {
    [$loan, $rows] = makeLoan($this->member->id, schedules: 1, swp: 10000);

    $this->service->pay($rows[0], billPayment(), $this->user->id, refundMethod: 'transfer');

    expect($loan->fresh()->status)->toBe('Lunas');

    $swp = SavingsWithdrawal::where('related_loan_id', $loan->id)->where('savings_type', 'swp')->first();
    $tab = SavingsWithdrawal::where('related_loan_id', $loan->id)->where('savings_type', 'tabungan_berjangka')->first();

    expect($swp->amount)->toBe('10000.00')
        ->and($swp->status)->toBe('cair')
        ->and($swp->disbursement_method)->toBe('transfer')
        ->and($tab->amount)->toBe('1000.00')
        // saldo ter-net: akumulasi − refund = 0
        ->and($this->balances->balanceByType($this->member, 'swp'))->toBe('0.00')
        ->and($this->balances->balanceByType($this->member, 'tabungan_berjangka'))->toBe('0.00');
});

it('reverses a settlement payment: loan back to Cair and refunds cancelled (M2)', function () {
    [$loan, $rows] = makeLoan($this->member->id, schedules: 1, swp: 10000);
    $inst = $this->service->pay($rows[0], billPayment(), $this->user->id);

    $this->service->reverse($inst, 'Salah catat nominal angsuran', $this->user->id);

    expect($loan->fresh()->status)->toBe('Cair')
        ->and($rows[0]->fresh()->status)->toBe('Belum Bayar')
        // refund SWP dibatalkan → saldo SWP kembali penuh (uang ditahan lagi)
        ->and($this->balances->balanceByType($this->member, 'swp'))->toBe('10000.00')
        ->and($this->balances->balanceByType($this->member, 'tabungan_berjangka'))->toBe('0.00');

    // refund withdrawal swp punya baris reversal-nya
    $swpReversed = SavingsWithdrawal::where('related_loan_id', $loan->id)
        ->where('savings_type', 'swp')->where('is_reversal', true)->exists();
    expect($swpReversed)->toBeTrue();
});

it('rejects paying a schedule that is already paid', function () {
    [$loan, $rows] = makeLoan($this->member->id, schedules: 2);
    $this->service->pay($rows[0], billPayment(), $this->user->id);

    expect(fn () => $this->service->pay($rows[0]->fresh(), billPayment(), $this->user->id))
        ->toThrow(CannotProcessPayment::class);
});
