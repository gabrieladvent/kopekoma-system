<?php

use App\Livewire\Loan\LoanDetail;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanPaymentService;
use Livewire\Livewire;

/**
 * Item 2e (ADR 2026-08-28) — panel Riwayat Titipan Pokok. Bersama kuitansi dan
 * jejak log, panel ini adalah salah satu dari tiga kanal pendeteksian yang jadi
 * syarat diterimanya risiko korupsi loket (R14/OQ-0). Ia menjawab *kapan masuk,
 * kapan dipotong dan berapa, kapan habis*.
 */
beforeEach(function () {
    $this->user = asSuperAdmin();
    $this->service = app(LoanPaymentService::class);

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

it('hides the panel on a loan that never had titipan', function () {
    $this->service->pay($this->schedules[0], ['amount_paid' => 1050000], $this->user->id);

    Livewire::test(LoanDetail::class, ['loan' => $this->loan])
        ->assertDontSee('Riwayat Titipan Pokok');
});

it('shows when the titipan came in, when it was used, and when it ran out', function () {
    // Masuk 1.050.000.
    $this->service->pay($this->schedules[0], ['amount_paid' => 2100000], $this->user->id);
    // Dipakai 1.000.000 → sisa 50.000.
    $this->service->pay($this->schedules[1]->fresh(), ['amount_paid' => 50000], $this->user->id);
    // Dipakai 50.000 → habis.
    $this->service->pay($this->schedules[2]->fresh(), ['amount_paid' => 1000000], $this->user->id);

    $history = Livewire::test(LoanDetail::class, ['loan' => $this->loan])
        ->assertSee('Riwayat Titipan Pokok')
        ->viewData('creditHistory');

    expect($history)->toHaveCount(3)
        ->and(array_column($history, 'in'))->toBe(['1050000.00', '0.00', '0.00'])
        ->and(array_column($history, 'used'))->toBe(['0.00', '1000000.00', '50000.00'])
        ->and(array_column($history, 'balance'))->toBe(['1050000.00', '50000.00', '0.00']);
});

/** Baris pembayaran pas tidak menggerakkan saldo — ia hanya jadi derau. */
it('skips rows that do not move the balance', function () {
    $this->service->pay($this->schedules[0], ['amount_paid' => 1050000], $this->user->id);
    $this->service->pay($this->schedules[1]->fresh(), ['amount_paid' => 2100000], $this->user->id);

    $history = Livewire::test(LoanDetail::class, ['loan' => $this->loan])
        ->viewData('creditHistory');

    expect($history)->toHaveCount(1)
        ->and($history[0]['in'])->toBe('1050000.00');
});

it('shows a reversal as a movement of its own', function () {
    $inst = $this->service->pay($this->schedules[0], ['amount_paid' => 2100000], $this->user->id);

    $this->service->reverse($inst, 'salah input nominal', $this->user->id);

    $history = Livewire::test(LoanDetail::class, ['loan' => $this->loan])
        ->viewData('creditHistory');

    expect($history)->toHaveCount(2)
        ->and($history[1]['reversal'])->toBeTrue()
        ->and($history[1]['used'])->toBe('1050000.00')
        ->and($history[1]['balance'])->toBe('0.00');
});

/**
 * Tanpa baris penutup, tabel berhenti pada saldo yang secara fisik sudah tidak
 * ada — dan pertanyaan "kapan habis" justru tak terjawab.
 */
it('closes the history with the transfer to sukarela when the loan is settled', function () {
    foreach (range(0, 3) as $i) {
        $this->service->pay($this->schedules[$i]->fresh(), ['amount_paid' => 1050000], $this->user->id);
    }

    $this->service->pay($this->schedules[4]->fresh(), ['amount_paid' => 1150000], $this->user->id);

    $history = Livewire::test(LoanDetail::class, ['loan' => $this->loan->fresh()])
        ->assertSee('Dilimpahkan ke Simpanan Sukarela')
        ->viewData('creditHistory');

    $last = end($history);

    expect($last['used'])->toBe('100000.00')
        ->and($last['balance'])->toBe('0.00')
        ->and($last['number'])->toBe('—');
});

/** Saldo di panel wajib sama dengan satu-satunya sumber, bukan hitungan sendiri. */
it('reports the same balance as the loan itself', function () {
    $this->service->pay($this->schedules[0], ['amount_paid' => 3000000], $this->user->id);

    $component = Livewire::test(LoanDetail::class, ['loan' => $this->loan]);

    $history = $component->viewData('creditHistory');

    expect($component->viewData('creditBalance'))->toBe($this->loan->fresh()->overpaymentCredit())
        ->and(end($history)['balance'])->toBe($this->loan->fresh()->overpaymentCredit());
});

/**
 * Temuan security review — panel ini dulu melabeli SELURUH sisa titipan sebagai
 * "Dilimpahkan ke Simpanan Sukarela" saat pinjaman ditutup, termasuk bagian yang
 * sesungguhnya dimakan potongan Pelunasan Dipercepat dan tak pernah sampai ke
 * simpanan anggota. Salah label di tabel yang tugasnya menjawab "titipan saya ke
 * mana" bukan soal kosmetik: ia menutup pertanyaannya dengan jawaban keliru.
 */
it('separates titipan eaten by the payoff from titipan moved to sukarela', function () {
    foreach ([0, 1, 2] as $i) {
        $this->service->pay($this->schedules[$i]->fresh(), ['amount_paid' => 1050000], $this->user->id);
    }

    // Titipan besar hanya bisa terbentuk lewat jalur potong gaji, tempat penjaga
    // "arahkan ke Pelunasan Dipercepat" memang dimatikan (R23) — di loket penjaga
    // itu justru mencegah titipan tumbuh melampaui sisa pokok.
    $this->service->pay(
        $this->schedules[3]->fresh(),
        ['amount_paid' => 3050000],
        $this->user->id,
        null,
        redirectToSettlement: false,
    );

    $loan = $this->loan->fresh();

    expect($loan->overpaymentCredit())->toBe('2000000.00')
        ->and($loan->settledPrincipal())->toBe('1000000.00');

    $this->service->settleEarly($loan, ['amount_paid' => $loan->payoffAmount()], $this->user->id);

    $rows = Livewire::test(LoanDetail::class, ['loan' => $this->loan->fresh()])
        ->viewData('creditHistory');

    $closing = array_slice($rows, -2);

    expect($closing[0]['used'])->toBe('1000000.00')
        ->and($closing[0]['note'])->toBe('Memotong jumlah Pelunasan Dipercepat')
        ->and($closing[0]['balance'])->toBe('1000000.00')
        ->and($closing[1]['used'])->toBe('1000000.00')
        ->and($closing[1]['note'])->toBe('Dilimpahkan ke Simpanan Sukarela saat pinjaman ditutup')
        ->and($closing[1]['balance'])->toBe('0.00');
});

/** Tanpa pelunasan, satu baris penutup saja — tak ada baris kosong tambahan. */
it('still shows a single closing row when the loan closes without a payoff', function () {
    foreach ([1050000, 1050000, 1050000, 1050000, 1100000] as $i => $amount) {
        $this->service->pay($this->schedules[$i]->fresh(), ['amount_paid' => $amount], $this->user->id);
    }

    $rows = Livewire::test(LoanDetail::class, ['loan' => $this->loan->fresh()])
        ->viewData('creditHistory');

    $last = end($rows);

    expect($last['note'])->toBe('Dilimpahkan ke Simpanan Sukarela saat pinjaman ditutup')
        ->and($last['used'])->toBe('50000.00');
});
