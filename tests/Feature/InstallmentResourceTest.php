<?php

use App\Filament\Resources\InstallmentResource;
use App\Filament\Resources\InstallmentResource\Pages\CreateInstallment;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsWithdrawal;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    asSuperAdmin();
    $this->member = Member::factory()->create();
    $this->loan = Loan::factory()->create([
        'member_id' => $this->member->id,
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 12000000,
        'swp_amount' => 120000,
        'term_months' => 1,
        'monthly_principal' => 1000000,
        'monthly_interest' => 78000,
        'monthly_time_deposit' => 12000,
    ]);
    $this->schedule = InstallmentSchedule::factory()->create([
        'loan_id' => $this->loan->id,
        'installment_seq' => 1,
        'principal_due' => 1000000,
        'interest_due' => 78000,
        'time_deposit_due' => 12000,
        'total_due' => 1090000,
    ]);
});

it('records a payment through the service, settles the loan and refunds (final installment)', function () {
    Livewire::test(CreateInstallment::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_id' => $this->loan->id,
            'schedule_id' => $this->schedule->id,
            'payment_method' => 'manual',
            'principal_paid' => '1000000',
            'interest_paid' => '78000',
            'time_deposit_saved' => '12000',
            'payment_date' => now()->toDateString(),
            'refund_method' => 'tunai',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Installment::where('loan_id', $this->loan->id)->where('is_reversal', false)->count())->toBe(1)
        ->and($this->schedule->fresh()->status)->toBe('Terbayar')
        ->and($this->loan->fresh()->status)->toBe('Lunas')
        ->and(SavingsWithdrawal::where('related_loan_id', $this->loan->id)->where('savings_type', 'swp')->where('amount', '120000.00')->exists())->toBeTrue();
});

it('prefills installment amounts as integer rupiah, not scaled by decimals', function () {
    Livewire::test(CreateInstallment::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_id' => $this->loan->id,
            'schedule_id' => $this->schedule->id,
        ])
        ->assertFormSet([
            'principal_paid' => '1000000',
            'interest_paid' => '78000',
            'time_deposit_saved' => '12000',
        ]);
});

it('streams a kuitansi angsuran PDF', function () {
    $inst = Installment::factory()->create(['loan_id' => $this->loan->id]);

    expect(InstallmentResource::printReceipt($inst))->toBeInstanceOf(StreamedResponse::class);
});

it('rejects a below-bill payment (no installment created)', function () {
    Livewire::test(CreateInstallment::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_id' => $this->loan->id,
            'schedule_id' => $this->schedule->id,
            'payment_method' => 'manual',
            'principal_paid' => '999999',
            'interest_paid' => '78000',
            'time_deposit_saved' => '12000',
            'payment_date' => now()->toDateString(),
        ])
        ->call('create');

    expect(Installment::where('loan_id', $this->loan->id)->exists())->toBeFalse()
        ->and($this->loan->fresh()->status)->toBe('Cair');
});
