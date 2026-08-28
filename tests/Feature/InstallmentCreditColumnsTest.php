<?php

use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Item 0a (ADR 2026-08-28) — schema Titipan Pokok. Perilakunya belum berubah:
 * yang dikunci di sini hanyalah bahwa kolomnya ada, aman untuk baris lama, dan
 * bahwa kunci idempotensi turunan (`kunci_sesi + "-" + urutan`) benar-benar muat
 * beserta perlindungan UNIQUE-nya.
 */
beforeEach(function () {
    $this->loan = Loan::factory()->create([
        'member_id' => Member::factory()->create()->id,
    ]);
});

it('adds credit_applied and session_key to installments', function () {
    expect(Schema::hasColumns('installments', ['credit_applied', 'session_key']))->toBeTrue();
});

it('leaves credit_applied and session_key null for rows that do not use titipan', function () {
    $installment = Installment::factory()->create(['loan_id' => $this->loan->id]);

    expect($installment->fresh()->credit_applied)->toBeNull()
        ->and($installment->fresh()->session_key)->toBeNull();
});

it('stores credit_applied as a 2-decimal money value', function () {
    $installment = Installment::factory()->create([
        'loan_id' => $this->loan->id,
        'credit_applied' => 1000000,
    ]);

    expect($installment->fresh()->credit_applied)->toBe('1000000.00');
});

it('fits a derived idempotency key of session key plus sequence', function () {
    $session = (string) Str::uuid();

    foreach ([1, 2, 3] as $seq) {
        Installment::factory()->create([
            'loan_id' => $this->loan->id,
            'installment_seq' => $seq,
            'idempotency_key' => $session.'-'.$seq,
            'session_key' => $session,
        ]);
    }

    expect(Installment::where('session_key', $session)->pluck('idempotency_key')->all())
        ->toBe([$session.'-1', $session.'-2', $session.'-3']);
});

it('still rejects a duplicate derived idempotency key', function () {
    $session = (string) Str::uuid();

    Installment::factory()->create([
        'loan_id' => $this->loan->id,
        'idempotency_key' => $session.'-1',
        'session_key' => $session,
    ]);

    Installment::factory()->create([
        'loan_id' => $this->loan->id,
        'idempotency_key' => $session.'-1',
        'session_key' => $session,
    ]);
})->throws(UniqueConstraintViolationException::class);
