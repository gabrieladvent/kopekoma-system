<?php

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotReverseTransaction;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\ShoppingTransaction;
use App\Models\User;
use App\Services\SavingsBalanceService;

/**
 * Item 1b/1d (ADR Modul Simpanan, D3) — reversal generik + guard.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->reverse = app(ReverseTransaction::class);
    $this->member = Member::factory()->create();
});

it('creates a counter-row without touching the original', function () {
    $deposit = SavingsDeposit::factory()->type('pokok')->create([
        'member_id' => $this->member->id, 'amount' => 100000,
    ]);

    $reversal = ($this->reverse)($deposit, 'Salah input nominal');

    expect($reversal->is_reversal)->toBeTrue()
        ->and($reversal->reversal_of_id)->toBe($deposit->id)
        ->and((string) $reversal->amount)->toBe('100000.00')
        ->and($reversal->transaction_number)->toBe('STR-2026-000002')
        ->and($deposit->fresh()->is_reversal)->toBeFalse();

    // net asli + reversal = 0
    expect(app(SavingsBalanceService::class)->balanceByType($this->member, 'pokok'))->toBe('0.00');
});

it('rejects reversing a reversal', function () {
    $deposit = SavingsDeposit::factory()->type('pokok')->create(['member_id' => $this->member->id]);
    $reversal = ($this->reverse)($deposit, 'Koreksi pertama');

    expect(fn () => ($this->reverse)($reversal, 'Koreksi kedua'))
        ->toThrow(CannotReverseTransaction::class);
});

it('rejects a second reversal of the same original (single-reversal guard)', function () {
    $deposit = SavingsDeposit::factory()->type('pokok')->create(['member_id' => $this->member->id]);
    ($this->reverse)($deposit, 'Koreksi pertama');

    expect(fn () => ($this->reverse)($deposit->fresh(), 'Koreksi kedua'))
        ->toThrow(CannotReverseTransaction::class);
});

it('rejects reversal for a Keluar/Meninggal member', function () {
    $inactive = Member::factory()->create(['status' => 'Keluar']);
    $deposit = SavingsDeposit::factory()->type('pokok')->create(['member_id' => $inactive->id]);

    expect(fn () => ($this->reverse)($deposit, 'Koreksi'))
        ->toThrow(CannotReverseTransaction::class);
});

it('requires a reason of at least 5 characters', function () {
    $deposit = SavingsDeposit::factory()->type('pokok')->create(['member_id' => $this->member->id]);

    expect(fn () => ($this->reverse)($deposit, 'eh'))
        ->toThrow(CannotReverseTransaction::class);
});

it('reverses a cair Hari Raya withdrawal back to the correct period_year', function () {
    SavingsDeposit::factory()->holiday(2026)->create(['member_id' => $this->member->id, 'amount' => 100000]);
    $withdrawal = SavingsWithdrawal::factory()->holiday(2026)->cair()->create([
        'member_id' => $this->member->id, 'amount' => 40000,
    ]);

    $service = app(SavingsBalanceService::class);
    expect($service->holidayBalance($this->member, 2026))->toBe('60000.00');

    $reversal = ($this->reverse)($withdrawal, 'Batal pencairan Hari Raya');

    expect($reversal->period_year)->toBe(2026)
        ->and($reversal->status)->toBe('cair')
        // outflow dikembalikan ke tahun yang benar
        ->and($service->holidayBalance($this->member, 2026))->toBe('100000.00');
});

it('reverses a shopping transaction', function () {
    SavingsDeposit::factory()->type('wajib_belanja')->create(['member_id' => $this->member->id, 'amount' => 100000]);
    $shopping = ShoppingTransaction::factory()->create(['member_id' => $this->member->id, 'amount' => 25000]);

    $service = app(SavingsBalanceService::class);
    expect($service->shoppingBalance($this->member))->toBe('75000.00');

    ($this->reverse)($shopping, 'Salah catat belanja');

    expect($service->shoppingBalance($this->member))->toBe('100000.00');
});
