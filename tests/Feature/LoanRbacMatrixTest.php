<?php

use App\Models\Installment;
use App\Models\Loan;
use App\Models\LoanBlacklist;

/**
 * Matriks RBAC modul Pinjaman (ADR D11). Gating berbasis permission Shield.
 * - Petugas: catat pinjaman, catat angsuran, reversal angsuran.
 * - Pengurus: di atas + koreksi pinjaman (reverse_loan).
 */
it('grants petugas create + installment reversal but NOT loan correction or early settlement', function () {
    $user = asPetugas();
    $loan = Loan::factory()->create();
    $inst = Installment::factory()->create();
    $settlement = Installment::factory()->create(['is_settlement' => true, 'installment_seq' => null]);

    expect($user->can('create_loan'))->toBeTrue()
        ->and($user->can('create_installment'))->toBeTrue()
        ->and($user->can('reverse_installment'))->toBeTrue()
        ->and($user->can('reverse', $inst))->toBeTrue()            // reversal angsuran biasa OK
        ->and($user->can('reverse', $loan))->toBeFalse()          // koreksi pinjaman = Pengurus+
        ->and($user->can('reverse_loan'))->toBeFalse()
        // Pelunasan dipercepat = Pengurus only (ADR 2026-07-22 §Keamanan #1/#3).
        ->and($user->can('settle_early_installment'))->toBeFalse()
        ->and($user->can('reverse', $settlement))->toBeFalse();   // reversal pelunasan butuh reverse_loan
});

it('grants pengurus everything including loan correction and early settlement', function () {
    $user = asPengurus();
    $loan = Loan::factory()->create();
    $settlement = Installment::factory()->create(['is_settlement' => true, 'installment_seq' => null]);

    expect($user->can('create_loan'))->toBeTrue()
        ->and($user->can('create_installment'))->toBeTrue()
        ->and($user->can('reverse_installment'))->toBeTrue()
        ->and($user->can('reverse', $loan))->toBeTrue()
        ->and($user->can('reverse_loan'))->toBeTrue()
        ->and($user->can('create_loan::blacklist'))->toBeTrue()
        // Pelunasan dipercepat + reversal-nya = Pengurus (ADR 2026-07-22).
        ->and($user->can('settle_early_installment'))->toBeTrue()
        ->and($user->can('reverse', $settlement))->toBeTrue();
});

it('gates savings-source debit + its reversal to pengurus only (ADR 2026-07-22 1e)', function () {
    $savingsInst = Installment::factory()->create(['payment_method' => 'saldo_simpanan']);

    // Petugas: TIDAK boleh debit dari simpanan, TIDAK boleh reverse angsuran saldo_simpanan
    // (reverse menaikkan saldo withdrawable → butuh reverse_loan/Pengurus, cegah privilege inversion).
    $petugas = asPetugas();
    expect($petugas->can('pay_installment_from_savings'))->toBeFalse()
        ->and($petugas->can('payFromSavings', Installment::class))->toBeFalse()
        ->and($petugas->can('reverse', $savingsInst))->toBeFalse();

    // Pengurus: boleh keduanya.
    $pengurus = asPengurus();
    expect($pengurus->can('pay_installment_from_savings'))->toBeTrue()
        ->and($pengurus->can('payFromSavings', Installment::class))->toBeTrue()
        ->and($pengurus->can('reverse', $savingsInst))->toBeTrue();
});

it('lets petugas manage blacklist records', function () {
    $user = asPetugas();
    $bl = LoanBlacklist::factory()->create();

    expect($user->can('create_loan::blacklist'))->toBeTrue()
        ->and($user->can('update', $bl))->toBeTrue();
});
