<?php

use App\Models\Installment;
use App\Models\Loan;
use App\Models\LoanBlacklist;

/**
 * Matriks RBAC modul Pinjaman (ADR D11). Gating berbasis permission Shield.
 * - Petugas: catat pinjaman, catat angsuran, reversal angsuran.
 * - Pengurus: di atas + koreksi pinjaman (reverse_loan).
 */
it('grants petugas create + installment reversal but NOT loan correction', function () {
    $user = asPetugas();
    $loan = Loan::factory()->create();
    $inst = Installment::factory()->create();

    expect($user->can('create_loan'))->toBeTrue()
        ->and($user->can('create_installment'))->toBeTrue()
        ->and($user->can('reverse_installment'))->toBeTrue()
        ->and($user->can('reverse', $loan))->toBeFalse()      // koreksi pinjaman = Pengurus+
        ->and($user->can('reverse_loan'))->toBeFalse();
});

it('grants pengurus everything including loan correction', function () {
    $user = asPengurus();
    $loan = Loan::factory()->create();

    expect($user->can('create_loan'))->toBeTrue()
        ->and($user->can('create_installment'))->toBeTrue()
        ->and($user->can('reverse_installment'))->toBeTrue()
        ->and($user->can('reverse', $loan))->toBeTrue()
        ->and($user->can('reverse_loan'))->toBeTrue()
        ->and($user->can('create_loan::blacklist'))->toBeTrue();
});

it('lets petugas manage blacklist records', function () {
    $user = asPetugas();
    $bl = LoanBlacklist::factory()->create();

    expect($user->can('create_loan::blacklist'))->toBeTrue()
        ->and($user->can('update', $bl))->toBeTrue();
});
