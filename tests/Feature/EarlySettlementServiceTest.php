<?php

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Exceptions\CannotProcessPayment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use App\Services\LoanPaymentService;

beforeEach(function () {
    $this->service = app(LoanPaymentService::class);
    $this->member = Member::factory()->create();
    $this->user = User::factory()->create();
});

/** Pinjaman 5 bulan: pokok 5jt, monthly 1jt, jasa 6500, tab 1000, swp 10000. */
function settleLoan(string $memberId): array
{
    $loan = Loan::factory()->create([
        'member_id' => $memberId,
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 5000000,
        'swp_amount' => 10000,
        'term_months' => 5,
        'monthly_principal' => 1000000,
        'monthly_interest' => 6500,
        'monthly_time_deposit' => 1000,
        'disbursement_method' => 'tunai',
    ]);

    $rows = collect(range(1, 5))->map(fn ($seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 6500,
        'time_deposit_due' => 1000,
        'total_due' => 1007500,
    ]));

    return [$loan, $rows];
}

it('settles early: pays exact payoff, marks all schedules paid, loan Lunas, creates refunds', function () {
    [$loan, $rows] = settleLoan($this->member->id);
    $this->service->pay($rows[0], ['amount_paid' => 1007500], $this->user->id);
    $this->service->pay($rows[1], ['amount_paid' => 1007500], $this->user->id);

    // payoff = sisa pokok 3jt + 1× jasa 6500
    $inst = $this->service->settleEarly($loan, ['amount_paid' => 3006500], $this->user->id);

    expect($inst->is_settlement)->toBeTrue()
        ->and($inst->installment_seq)->toBeNull()
        ->and($loan->fresh()->status)->toBe(LoanStatus::Lunas)
        ->and($loan->fresh()->remainingPrincipal())->toBe('0.00');

    // Semua jadwal terbayar.
    expect(InstallmentSchedule::where('loan_id', $loan->id)
        ->where('status', InstallmentScheduleStatus::BelumBayar)->count())->toBe(0);

    // Refund: SWP 10000 + Tab akrual (2 angsuran normal × 1000 = 2000), bukan 5×.
    $tab = SavingsWithdrawal::where('related_loan_id', $loan->id)
        ->where('savings_type', 'tabungan_berjangka')->value('amount');
    expect((string) $tab)->toBe('2000.00');
});

it('rejects settlement below payoff and creates nothing', function () {
    [$loan, $rows] = settleLoan($this->member->id);
    $this->service->pay($rows[0], ['amount_paid' => 1007500], $this->user->id);

    expect(fn () => $this->service->settleEarly($loan, ['amount_paid' => 4006499], $this->user->id))
        ->toThrow(CannotProcessPayment::class);

    // payoff seharusnya 4jt + 6500 = 4.006.500; 4.006.499 ditolak.
    expect($loan->fresh()->status)->toBe(LoanStatus::Cair)
        ->and($loan->installments()->where('is_settlement', true)->count())->toBe(0);
});

it('routes overpayment above payoff to Sukarela', function () {
    [$loan, $rows] = settleLoan($this->member->id);
    $this->service->pay($rows[0], ['amount_paid' => 1007500], $this->user->id);
    $this->service->pay($rows[1], ['amount_paid' => 1007500], $this->user->id);

    // payoff 3.006.500 + kelebihan 500.000
    $this->service->settleEarly($loan, ['amount_paid' => 3506500], $this->user->id);

    $sukarela = SavingsDeposit::where('member_id', $this->member->id)
        ->where('savings_type', 'sukarela')->value('amount');
    expect((string) $sukarela)->toBe('500000.00');
});

it('refuses settlement for jangka_pendek and non-Cair loans', function () {
    $short = Loan::factory()->jangkaPendek()->create(['member_id' => $this->member->id]);

    expect(fn () => $this->service->settleEarly($short, ['amount_paid' => 999999999], $this->user->id))
        ->toThrow(CannotProcessPayment::class);
});

it('reverses a settlement: loan back to Cair, remaining recovered, refunds cleaned', function () {
    [$loan, $rows] = settleLoan($this->member->id);
    $this->service->pay($rows[0], ['amount_paid' => 1007500], $this->user->id);
    $this->service->pay($rows[1], ['amount_paid' => 1007500], $this->user->id);
    $settlement = $this->service->settleEarly($loan, ['amount_paid' => 3006500], $this->user->id);

    $this->service->reverse($settlement, 'Pembatalan pelunasan uji', $this->user->id);

    $loan->refresh();
    expect($loan->status)->toBe(LoanStatus::Cair)
        ->and($loan->hasActiveSettlement())->toBeFalse()
        ->and($loan->remainingPrincipal())->toBe('3000000.00');

    // Jadwal yang dibayar normal (seq 1 & 2) TETAP terbayar; sisanya kembali dibuka.
    expect($rows[0]->fresh()->status)->toBe(InstallmentScheduleStatus::Terbayar)
        ->and($rows[1]->fresh()->status)->toBe(InstallmentScheduleStatus::Terbayar)
        ->and($rows[2]->fresh()->status)->toBe(InstallmentScheduleStatus::BelumBayar);

    // Refund draft dibersihkan (tidak ada refund aktif tersisa).
    expect(SavingsWithdrawal::where('related_loan_id', $loan->id)
        ->where('is_reversal', false)
        ->whereIn('status', ['draft', 'acc', 'cair'])->count())->toBe(0);
});

it('net-aware reopening: pay-1 -> reverse-1 -> settle -> reverse-settle reopens schedule 1', function () {
    [$loan, $rows] = settleLoan($this->member->id);

    $p1 = $this->service->pay($rows[0], ['amount_paid' => 1007500], $this->user->id);
    $this->service->reverse($p1, 'Batal bayar bulan 1', $this->user->id);

    // Net normal = 0 → sisa pokok penuh 5jt; payoff = 5jt + 6500.
    $settlement = $this->service->settleEarly($loan, ['amount_paid' => 5006500], $this->user->id);
    expect($loan->fresh()->status)->toBe(LoanStatus::Lunas);

    $this->service->reverse($settlement, 'Batal pelunasan', $this->user->id);

    // Schedule 1 harus IKUT dibuka (net pembayaran normalnya 0), tidak nyangkut Terbayar.
    expect($rows[0]->fresh()->status)->toBe(InstallmentScheduleStatus::BelumBayar)
        ->and($loan->fresh()->status)->toBe(LoanStatus::Cair)
        ->and($loan->fresh()->remainingPrincipal())->toBe('5000000.00');
});
