<?php

use App\Actions\ReverseTransaction;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;

/**
 * Kunci perilaku helper count-based untuk Pelunasan Dipercepat (ADR 2026-07-22),
 * SEBELUM settleEarly() ada — baris settlement/reversal dibuat via factory langsung.
 * Test-first area silent-money-bug: settledPrincipal vs remainingPrincipal (gate),
 * exclude tab, dan reverseClone membawa is_settlement.
 */
beforeEach(function () {
    $this->member = Member::factory()->create();
    $this->user = User::factory()->create();
});

/** Pinjaman 5 bulan: pokok 5jt, monthly 1jt, jasa 6500, tab 1000. */
function settlementLoan(string $memberId): Loan
{
    return Loan::factory()->create([
        'member_id' => $memberId,
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 5000000,
        'term_months' => 5,
        'monthly_principal' => 1000000,
        'monthly_interest' => 6500,
        'monthly_time_deposit' => 1000,
    ]);
}

/** Catat N angsuran normal (is_settlement=0) di loan. */
function payNormal(Loan $loan, int $count): void
{
    foreach (range(1, $count) as $seq) {
        Installment::factory()->create([
            'loan_id' => $loan->id,
            'installment_seq' => $seq,
            'is_settlement' => false,
            'amount_paid' => 1007500,
        ]);
    }
}

it('settledPrincipal excludes settlement rows and stays correct after Lunas', function () {
    $loan = settlementLoan($this->member->id);
    payNormal($loan, 2); // sisa pokok = 5jt - 2×1jt = 3jt

    expect($loan->settledPrincipal())->toBe('3000000.00')
        ->and($loan->remainingPrincipal())->toBe('3000000.00')
        ->and($loan->hasActiveSettlement())->toBeFalse();

    // Baris pelunasan menutup sisa (payoff = 3jt + 6500 jasa).
    Installment::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => null,
        'schedule_id' => null,
        'is_settlement' => true,
        'amount_paid' => 3006500,
    ]);

    $loan->refresh();

    // settledPrincipal NON-GATED tetap 3jt (settlement dikecualikan dari count);
    // remainingPrincipal GATED → 0 karena ada pelunasan aktif.
    expect($loan->settledPrincipal())->toBe('3000000.00')
        ->and($loan->hasActiveSettlement())->toBeTrue()
        ->and($loan->remainingPrincipal())->toBe('0.00');
});

it('breakdown of a settlement row shows real principal (not 0), 1x interest, 0 tab', function () {
    $loan = settlementLoan($this->member->id);
    payNormal($loan, 2);

    $settlement = Installment::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => null,
        'schedule_id' => null,
        'is_settlement' => true,
        'amount_paid' => 3506500, // payoff 3.006.500 + 500.000 kelebihan → sukarela
    ]);

    $b = $settlement->fresh()->breakdown();

    expect($b['principal'])->toBe('3000000.00')
        ->and($b['interest'])->toBe('6500.00')
        ->and($b['time_deposit'])->toBe('0.00')
        ->and($b['other'])->toBe('500000.00')
        ->and($b['total'])->toBe('3506500.00');
});

it('excludes settlement rows from time-deposit accrual', function () {
    $loan = settlementLoan($this->member->id);
    payNormal($loan, 2);

    Installment::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => null,
        'is_settlement' => true,
        'amount_paid' => 3006500,
    ]);

    // Tab accrued = 2 angsuran normal × 1000, baris settlement TIDAK menambah.
    $net = Installment::query()
        ->where('installments.loan_id', $loan->id)
        ->signedTimeDeposit()
        ->value('net');

    expect((string) $net)->toBe('2000');
});

it('recovers remaining principal after a settlement is reversed (reverseClone carries is_settlement)', function () {
    $loan = settlementLoan($this->member->id);
    payNormal($loan, 2);

    $settlement = Installment::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => null,
        'schedule_id' => null,
        'is_settlement' => true,
        'amount_paid' => 3006500,
    ]);

    expect($loan->fresh()->hasActiveSettlement())->toBeTrue();

    $reversal = app(ReverseTransaction::class)($settlement, 'Pembatalan pelunasan uji', $this->user->id);

    // Baris-lawan WAJIB bertanda settlement — kalau tidak, gate & count meleset.
    expect($reversal->is_settlement)->toBeTrue()
        ->and($reversal->is_reversal)->toBeTrue();

    $loan->refresh();

    // Net settlement = 1 − 1 = 0 → tidak aktif; remainingPrincipal pulih ke 3jt.
    expect($loan->hasActiveSettlement())->toBeFalse()
        ->and($loan->remainingPrincipal())->toBe('3000000.00')
        ->and($loan->settledPrincipal())->toBe('3000000.00');
});
