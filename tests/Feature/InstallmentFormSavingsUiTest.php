<?php

use App\Livewire\Loan\Installment\InstallmentForm;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * ADR 2026-07-22 item 2a — UI form pilih sumber "Saldo Simpanan".
 * Opsi Pengurus-only, nominal dikunci tagihan, consent bukti wajib, saldo divalidasi.
 */
beforeEach(function () {
    $this->member = Member::factory()->create();
    $this->loan = Loan::factory()->create([
        'member_id' => $this->member->id,
        'status' => 'Cair',
        'principal_amount' => 1000000,
        'monthly_principal' => 1000000,
        'monthly_interest' => 6500,
        'monthly_time_deposit' => 1000,
    ]);
    $this->schedule = InstallmentSchedule::factory()->create([
        'loan_id' => $this->loan->id,
        'installment_seq' => 1,
        'principal_due' => 1000000,
        'interest_due' => 6500,
        'time_deposit_due' => 1000,
        'total_due' => 1007500,
    ]);
    Storage::fake(config('media-library.disk_name'));
});

function fundSukarelaUi(string $memberId, int $amount): void
{
    SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $memberId,
        'amount' => $amount,
    ]);
}

it('offers the Saldo Simpanan source to pengurus', function () {
    asPengurus();

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->assertSee('Saldo Simpanan');
});

it('hides the Saldo Simpanan source from petugas', function () {
    asPetugas();

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->assertDontSee('Saldo Simpanan');
});

it('rejects a petugas who force-posts payment_method=saldo_simpanan (whitelist)', function () {
    asPetugas();
    fundSukarelaUi($this->member->id, 2_000_000);

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('payment_method', 'saldo_simpanan')
        ->set('bukti', UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'))
        ->call('pay')
        ->assertHasErrors('payment_method');

    expect(Installment::where('loan_id', $this->loan->id)->exists())->toBeFalse();
});

it('requires the consent proof when paying from savings', function () {
    asPengurus();
    fundSukarelaUi($this->member->id, 2_000_000);

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('payment_method', 'saldo_simpanan')
        ->call('pay')
        ->assertHasErrors('bukti');

    expect(Installment::where('loan_id', $this->loan->id)->exists())->toBeFalse();
});

it('rejects paying from savings when balance is insufficient', function () {
    asPengurus();
    fundSukarelaUi($this->member->id, 100_000); // < tagihan 1.007.500

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('payment_method', 'saldo_simpanan')
        ->set('bukti', UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'))
        ->call('pay')
        ->assertHasErrors('amount_paid');

    expect(Installment::where('loan_id', $this->loan->id)->exists())->toBeFalse();
});

it('locks the amount to the bill when the source is savings', function () {
    asPengurus();
    fundSukarelaUi($this->member->id, 2_000_000);

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('payment_method', 'saldo_simpanan')
        ->assertSet('amount_paid', 1007500);
});

it('records an installment debited from sukarela via the form (happy path)', function () {
    asPengurus();
    fundSukarelaUi($this->member->id, 2_000_000);

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('payment_method', 'saldo_simpanan')
        ->set('bukti', UploadedFile::fake()->create('consent.pdf', 100, 'application/pdf'))
        ->call('pay')
        ->assertHasNoErrors();

    $inst = Installment::where('loan_id', $this->loan->id)->firstOrFail();

    expect($inst->payment_method)->toBe('saldo_simpanan')
        ->and($inst->hasMedia('bukti'))->toBeTrue()
        ->and(SavingsWithdrawal::where('installment_id', $inst->id)->where('savings_type', 'sukarela')->exists())->toBeTrue();
});

it('clears the savings source when early-settlement is toggled on', function () {
    asPengurus();

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $this->member->id)
        ->set('loan_id', $this->loan->id)
        ->set('payment_method', 'saldo_simpanan')
        ->set('settle_early', true)
        ->assertSet('payment_method', 'potong_gaji');
});
