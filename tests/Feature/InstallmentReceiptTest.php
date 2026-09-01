<?php

use App\Enums\LoanStatus;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use App\Services\LoanPaymentService;

/**
 * Item 1l / 3k (ADR 2026-08-28) — kuitansi WAJIB menutup di KEDUA arah:
 *
 *     pokok + jasa + tab − dipakai + disisihkan = total diterima
 *
 * Nota ini diserahkan ke anggota dan naik peran jadi kontrol utama atas risiko
 * korupsi loket yang diterima sadar (R14), jadi "kurang lebih benar" tidak cukup.
 */
beforeEach(function () {
    $this->service = app(LoanPaymentService::class);
    $this->user = User::factory()->create();

    $this->loan = Loan::factory()->create([
        'member_id' => Member::factory()->create()->id,
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 5000000,
        'term_months' => 5,
        'monthly_principal' => 1000000,
        'monthly_interest' => 40000,
        'monthly_time_deposit' => 10000,
    ]);

    $this->schedules = collect(range(1, 5))->map(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $this->loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 40000,
        'time_deposit_due' => 10000,
        'total_due' => 1050000,
    ]));
});

/** pokok + jasa + tab − dipakai + disisihkan, harus sama dengan total. */
function kuitansiTutup(Installment $installment): bool
{
    $b = $installment->breakdown();

    $sum = bcadd(bcadd($b['principal'], $b['interest'], 2), $b['time_deposit'], 2);
    $sum = bcsub($sum, $b['credit_applied'], 2);
    $sum = bcadd($sum, $b['credit_reserved'], 2);

    return bccomp($sum, $b['total'], 2) === 0;
}

it('balances a plain installment receipt', function () {
    $inst = $this->service->pay($this->schedules[0], ['amount_paid' => 1050000], $this->user->id);

    expect(kuitansiTutup($inst))->toBeTrue()
        ->and($inst->breakdown()['credit_applied'])->toBe('0.00')
        ->and($inst->breakdown()['credit_reserved'])->toBe('0.00');
});

/** Arah "disisihkan": dibayar > kontrak. */
it('balances a receipt that sets titipan aside', function () {
    $inst = $this->service->pay($this->schedules[0], ['amount_paid' => 3000000], $this->user->id);

    $b = $inst->breakdown();

    expect(kuitansiTutup($inst))->toBeTrue()
        ->and($b['credit_reserved'])->toBe('1950000.00')
        ->and($b['credit_applied'])->toBe('0.00')
        ->and($b['credit_balance'])->toBe('1950000.00');
});

/** Arah "dipakai": dibayar < kontrak karena titipan memotong pokok. */
it('balances a receipt that consumes titipan', function () {
    $this->service->pay($this->schedules[0], ['amount_paid' => 3000000], $this->user->id);

    $inst = $this->service->pay($this->schedules[1]->fresh(), ['amount_paid' => 50000], $this->user->id);

    $b = $inst->breakdown();

    // Komponen 1.050.000, dipakai 1.000.000, total 50.000 — dan tetap berjumlah.
    expect(kuitansiTutup($inst))->toBeTrue()
        ->and($b['credit_applied'])->toBe('1000000.00')
        ->and($b['credit_reserved'])->toBe('0.00')
        ->and($b['total'])->toBe('50000.00')
        ->and($b['credit_balance'])->toBe('950000.00');
});

/** Contoh Kuitansi di ADR: baris terakhir sesi tutup-sekalian menyerap sisa. */
it('balances both rows of a multi-installment session', function () {
    $this->service->pay(
        $this->schedules[0],
        ['amount_paid' => 3000000, 'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN],
        $this->user->id,
    );

    $rows = Installment::where('loan_id', $this->loan->id)->orderBy('installment_seq')->get();

    foreach ($rows as $row) {
        expect(kuitansiTutup($row))->toBeTrue();
    }

    expect($rows[1]->breakdown()['credit_reserved'])->toBe('900000.00')
        ->and($rows[1]->breakdown()['credit_balance'])->toBe('900000.00');
});

it('shows a zero titipan balance on the deposit that exhausts it', function () {
    $this->service->pay($this->schedules[0], ['amount_paid' => 2100000], $this->user->id);

    // Titipan 1.050.000: #2 memakai 1.000.000 (sisa 50.000), #3 memakai 50.000.
    $second = $this->service->pay($this->schedules[1]->fresh(), ['amount_paid' => 50000], $this->user->id);
    $third = $this->service->pay($this->schedules[2]->fresh(), ['amount_paid' => 1000000], $this->user->id);

    expect($second->breakdown()['credit_balance'])->toBe('50000.00')
        ->and($third->breakdown()['credit_applied'])->toBe('50000.00')
        ->and($third->breakdown()['credit_balance'])->toBe('0.00');
});

/**
 * Baris pra-fitur ber-`credit_applied` NULL harus menutup persis seperti sebelum
 * fitur ini ada — kelebihannya tampil sebagai "disisihkan", bukan hilang.
 */
it('balances a pre-feature row whose credit_applied is null', function () {
    $legacy = Installment::factory()->create([
        'loan_id' => $this->loan->id,
        'schedule_id' => $this->schedules[0]->getKey(),
        'installment_seq' => 1,
        'amount_paid' => 1150000,
        'credit_applied' => null,
        'is_settlement' => false,
    ]);

    $b = $legacy->breakdown();

    expect(kuitansiTutup($legacy))->toBeTrue()
        ->and($b['credit_applied'])->toBe('0.00')
        ->and($b['credit_reserved'])->toBe('100000.00')
        // Baris pra-fitur tak dihitung sebagai titipan (R21) — saldonya tetap 0.
        ->and($b['credit_balance'])->toBe('0.00');
});

it('balances a settlement receipt that used titipan', function () {
    $this->service->pay($this->schedules[0], ['amount_paid' => 2100000], $this->user->id);

    $payoff = $this->loan->fresh()->payoffAmount();

    $settlement = $this->service->settleEarly($this->loan->fresh(), ['amount_paid' => $payoff], $this->user->id);

    $b = $settlement->breakdown();

    expect(kuitansiTutup($settlement))->toBeTrue()
        ->and($b['credit_applied'])->toBe('1050000.00')
        ->and($b['total'])->toBe($payoff);
});

/**
 * Saat pinjaman ditutup, sisa titipan pindah ke Sukarela — jadi kuitansi penutup
 * TIDAK boleh menampilkan titipan yang sudah tidak ada lagi.
 */
it('shows no leftover titipan on the closing receipt', function () {
    foreach (range(0, 3) as $i) {
        $this->service->pay($this->schedules[$i]->fresh(), ['amount_paid' => 1050000], $this->user->id);
    }

    $closing = $this->service->pay($this->schedules[4]->fresh(), ['amount_paid' => 1150000], $this->user->id);

    expect($this->loan->fresh()->status)->toBe(LoanStatus::Lunas)
        ->and(kuitansiTutup($closing))->toBeTrue()
        ->and($closing->breakdown()['credit_reserved'])->toBe('100000.00')
        ->and($closing->breakdown()['credit_balance'])->toBe('0.00');
});
