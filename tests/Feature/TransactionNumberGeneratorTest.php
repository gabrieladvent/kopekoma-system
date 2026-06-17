<?php

use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\ShoppingTransaction;

/**
 * Item 1c (ADR Modul Simpanan, D2) — generator nomor race-safe per jenis.
 */
it('generates STR- numbers for deposits, sequential and zero-padded', function () {
    $first = SavingsDeposit::factory()->create();
    $second = SavingsDeposit::factory()->create();

    expect($first->transaction_number)->toBe('STR-2026-000001')
        ->and($second->transaction_number)->toBe('STR-2026-000002');
});

it('generates TRK- numbers for withdrawals', function () {
    expect(SavingsWithdrawal::factory()->create()->withdrawal_number)->toBe('TRK-2026-000001');
});

it('generates BLJ- numbers for shopping transactions', function () {
    expect(ShoppingTransaction::factory()->create()->transaction_number)->toBe('BLJ-2026-000001');
});

it('does not overwrite an explicitly provided number', function () {
    $deposit = SavingsDeposit::factory()->create(['transaction_number' => 'STR-2026-009999']);

    expect($deposit->transaction_number)->toBe('STR-2026-009999');
});

it('resets the sequence per year', function () {
    // Baris tahun lalu tak boleh memajukan sekuens tahun ini.
    SavingsDeposit::factory()->create(['transaction_number' => 'STR-2025-000042']);

    $current = SavingsDeposit::factory()->create();

    expect($current->transaction_number)->toBe('STR-2026-000001');
});
