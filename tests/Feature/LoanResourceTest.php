<?php

use App\Filament\Resources\LoanResource;
use App\Filament\Resources\LoanResource\Pages\CreateLoan;
use App\Filament\Resources\LoanResource\Pages\ListLoans;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\LoanBlacklist;
use App\Models\Member;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    asSuperAdmin();
    $this->member = Member::factory()->create();
});

it('lists loans on the index page', function () {
    $loans = Loan::factory()->count(2)->create();

    Livewire::test(ListLoans::class)->assertCanSeeTableRecords($loans);
});

it('records a jangka panjang loan with server-computed deductions and generates a 12-row schedule', function () {
    Livewire::test(CreateLoan::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_type' => 'jangka_panjang',
            'principal_amount' => '12000000',
            'term_months' => 12,
            'disbursement_date' => '2026-07-01',
            'first_due_date' => '2026-08-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $loan = Loan::where('member_id', $this->member->id)->first();

    expect($loan)->not->toBeNull()
        ->and($loan->admin_fee)->toBe('120000.00')
        ->and($loan->swp_amount)->toBe('120000.00')
        ->and($loan->disbursed_amount)->toBe('11760000.00')
        ->and($loan->monthly_principal)->toBe('1000000.00')
        ->and($loan->monthly_interest)->toBe('78000.00')
        ->and($loan->monthly_time_deposit)->toBe('12000.00')
        ->and($loan->status)->toBe('Cair')
        ->and(InstallmentSchedule::where('loan_id', $loan->id)->count())->toBe(12);
});

it('records a Sebrakan with no deductions and a single schedule row', function () {
    Livewire::test(CreateLoan::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_type' => 'jangka_pendek',
            'principal_amount' => '500000',
            'term_months' => 1,
            'disbursement_date' => '2026-07-01',
            'first_due_date' => '2026-08-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $loan = Loan::where('member_id', $this->member->id)->first();
    $schedules = InstallmentSchedule::where('loan_id', $loan->id)->get();

    expect($loan->disbursed_amount)->toBe('500000.00')
        ->and($loan->swp_amount)->toBe('0.00')
        ->and($schedules)->toHaveCount(1)
        ->and($schedules[0]->interest_due)->toBe('0.00')
        ->and($schedules[0]->time_deposit_due)->toBe('0.00');
});

it('rejects a jangka panjang loan of Rp 1.000.000 or less', function () {
    Livewire::test(CreateLoan::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_type' => 'jangka_panjang',
            'principal_amount' => '500000',
            'term_months' => 12,
            'disbursement_date' => '2026-07-01',
            'first_due_date' => '2026-08-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['principal_amount']);

    expect(Loan::where('member_id', $this->member->id)->exists())->toBeFalse();
});

it('rejects a Sebrakan above Rp 1.000.000', function () {
    Livewire::test(CreateLoan::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_type' => 'jangka_pendek',
            'principal_amount' => '2000000',
            'term_months' => 1,
            'disbursement_date' => '2026-07-01',
            'first_due_date' => '2026-08-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['principal_amount']);

    expect(Loan::where('member_id', $this->member->id)->exists())->toBeFalse();
});

it('streams a Tanda Terima Pinjaman PDF', function () {
    $loan = Loan::factory()->create(['member_id' => $this->member->id]);
    InstallmentSchedule::factory()->create(['loan_id' => $loan->id]);

    expect(LoanResource::printReceipt($loan))->toBeInstanceOf(StreamedResponse::class);
});

it('blocks creating a loan for a blacklisted member', function () {
    LoanBlacklist::factory()->create(['member_id' => $this->member->id, 'is_active' => true]);

    Livewire::test(CreateLoan::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_type' => 'jangka_panjang',
            'principal_amount' => '12000000',
            'term_months' => 12,
            'disbursement_date' => '2026-07-01',
            'first_due_date' => '2026-08-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['member_id']);

    expect(Loan::where('member_id', $this->member->id)->exists())->toBeFalse();
});
