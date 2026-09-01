<?php

use App\Livewire\Loan\Installment\InstallmentForm;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanPaymentService;
use Livewire\Livewire;

/**
 * Item 2a / 2b / 1f (ADR 2026-08-28) — pintu loket.
 *
 * Yang dikunci: prefill dan lantai anti-korupsi memakai tagihan EFEKTIF, dialog
 * hanya muncul bila sisa uang cukup menutup angsuran berikutnya, akibat kedua
 * mode tersaji DALAM ANGKA, dan pratinjau yang basi ditolak — bukan disimpan
 * diam-diam dengan hasil berbeda dari yang dikonfirmasi di depan anggota.
 */
beforeEach(function () {
    $this->user = asSuperAdmin();
    $this->service = app(LoanPaymentService::class);
    $this->member = Member::factory()->create();

    $this->loan = Loan::factory()->create([
        'member_id' => $this->member->id,
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

/** Buat titipan lebih dulu lewat service, seperti setoran bulan sebelumnya. */
function beriTitipan(int|string $amount): void
{
    test()->service->pay(test()->schedules[0], ['amount_paid' => $amount], test()->user->id);
}

it('prefills the contract bill when there is no titipan', function () {
    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->assertSet('amount_paid', 1050000);
});

it('prefills the titipan-reduced bill', function () {
    beriTitipan(2100000); // titipan 1.050.000

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        // Tagihan efektif #2 = 1.050.000 − min(1.050.000, pokok 1.000.000).
        ->assertSet('amount_paid', 50000);
});

it('shows the titipan lines on the bill panel', function () {
    beriTitipan(2100000);

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->assertSee('Titipan Pokok dipakai')
        ->assertSee('Tagihan Bulan Ini')
        // R12: nama lama dicabut dari layar loket.
        ->assertDontSee('dikreditkan ke Simpanan Sukarela');
});

it('accepts the reduced bill that the contract floor would have rejected', function () {
    beriTitipan(2100000);

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('amount_paid', 50000)
        ->call('pay')
        ->assertHasNoErrors();

    expect($this->loan->fresh()->overpaymentCredit())->toBe('50000.00');
});

it('still rejects a payment below the effective bill', function () {
    beriTitipan(2100000);

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('amount_paid', 49999)
        ->call('pay')
        ->assertHasErrors('amount_paid');
});

/** Kelebihan receh tak boleh memunculkan dialog apa pun. */
it('does not ask anything for an ordinary rounding overpayment', function () {
    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('amount_paid', 1060000)
        ->call('pay')
        ->assertSet('showAllocationDialog', false);

    expect($this->loan->fresh()->overpaymentCredit())->toBe('10000.00');
});

it('asks the officer to choose once the money covers the next installment', function () {
    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('amount_paid', 2100000)
        ->call('pay')
        ->assertSet('showAllocationDialog', true);

    // Belum tersimpan apa pun sebelum petugas memilih.
    expect(Installment::where('loan_id', $this->loan->id)->count())->toBe(0);
});

/** Akibat WAJIB berangka, bukan hanya nama pilihan. */
it('spells out the consequence of each mode in rupiah', function () {
    $preview = Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('amount_paid', 2100000)
        ->instance()
        ->allocationPreview();

    expect($preview[LoanPaymentService::MODE_TITIPAN]['closed'])->toBe([1])
        ->and($preview[LoanPaymentService::MODE_TITIPAN]['credit_after'])->toBe('1050000.00')
        ->and($preview[LoanPaymentService::MODE_TITIPAN]['next'])->toBe([
            ['seq' => 2, 'bill' => '50000.00'],
            ['seq' => 3, 'bill' => '1000000.00'],
        ])
        ->and($preview[LoanPaymentService::MODE_TUTUP_SEKALIAN]['closed'])->toBe([1, 2])
        ->and($preview[LoanPaymentService::MODE_TUTUP_SEKALIAN]['credit_after'])->toBe('0.00')
        ->and($preview[LoanPaymentService::MODE_TUTUP_SEKALIAN]['next'])->toBe([
            ['seq' => 3, 'bill' => '1050000.00'],
        ]);
});

it('keeps the money as titipan when the officer picks the default', function () {
    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('amount_paid', 2100000)
        ->call('pay')
        ->call('chooseMode', LoanPaymentService::MODE_TITIPAN);

    expect(Installment::where('loan_id', $this->loan->id)->count())->toBe(1)
        ->and($this->loan->fresh()->overpaymentCredit())->toBe('1050000.00');
});

it('closes two installments when the officer picks tutup sekalian', function () {
    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('amount_paid', 2100000)
        ->call('pay')
        ->call('chooseMode', LoanPaymentService::MODE_TUTUP_SEKALIAN);

    expect(Installment::where('loan_id', $this->loan->id)->count())->toBe(2)
        ->and($this->loan->fresh()->overpaymentCredit())->toBe('0.00');
});

/**
 * Item 1f — petugas membuka form, pembayaran lain masuk, petugas menyimpan.
 * Ditolak dengan pesan minta ulangi, bukan disimpan dengan hasil berbeda.
 */
it('rejects a stale preview when the titipan moved in the meantime', function () {
    beriTitipan(2100000); // titipan 1.050.000

    $form = Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('amount_paid', 50000);

    // Setoran lain masuk lewat jalur berbeda — saldo titipan bergeser.
    $this->service->pay($this->schedules[1]->fresh(), ['amount_paid' => 1050000], $this->user->id);

    $form->call('pay')->assertHasErrors('amount_paid');
});

it('sends no allocation choice for a savings-funded payment', function () {
    beriTitipan(2100000);

    $form = Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id);

    expect($form->instance()->mode)->toBe(LoanPaymentService::MODE_TITIPAN);
});
