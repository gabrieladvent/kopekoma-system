<?php

use App\Filament\Resources\LoanResource;
use App\Filament\Resources\LoanResource\Pages\CreateLoan;
use App\Filament\Resources\LoanResource\Pages\ListLoans;
use App\Filament\Resources\LoanResource\Pages\ViewLoan;
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

it('records a transfer loan with destination bank and account number', function () {
    Livewire::test(CreateLoan::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_type' => 'jangka_panjang',
            'principal_amount' => '12000000',
            'term_months' => 12,
            'disbursement_date' => '2026-07-01',
            'first_due_date' => '2026-08-01',
            'disbursement_method' => 'transfer',
            'disbursement_bank' => 'BRI',
            'disbursement_account_number' => '1234567890',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $loan = Loan::where('member_id', $this->member->id)->first();
    expect($loan->disbursement_method)->toBe('transfer')
        ->and($loan->disbursement_bank)->toBe('BRI')
        ->and($loan->disbursement_account_number)->toBe('1234567890');
});

it('requires bank and account number when disbursement method is transfer', function () {
    Livewire::test(CreateLoan::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_type' => 'jangka_panjang',
            'principal_amount' => '12000000',
            'term_months' => 12,
            'disbursement_date' => '2026-07-01',
            'first_due_date' => '2026-08-01',
            'disbursement_method' => 'transfer',
        ])
        // Kosongkan eksplisit (membatalkan prefill dari rekening payroll anggota).
        ->fillForm([
            'disbursement_bank' => '',
            'disbursement_account_number' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['disbursement_bank', 'disbursement_account_number']);
});

it('does not require bank or account number for a tunai loan', function () {
    Livewire::test(CreateLoan::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'loan_type' => 'jangka_panjang',
            'principal_amount' => '12000000',
            'term_months' => 12,
            'disbursement_date' => '2026-07-01',
            'first_due_date' => '2026-08-01',
            'disbursement_method' => 'tunai',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Loan::where('member_id', $this->member->id)->first()->disbursement_method)->toBe('tunai');
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

it('cancels a loan as Dibatalkan from its detail page, keeping it as history', function () {
    $loan = Loan::factory()->create(['member_id' => $this->member->id]);
    InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id, 'installment_seq' => 1, 'total_due' => 1090000,
    ]);

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->callAction('correct', ['reason' => 'salah input nominal']);

    $loan->refresh();
    expect($loan->status)->toBe('Dibatalkan')
        // Jadwal (proyeksi) dibuang agar tak terhitung tunggakan; record pinjaman tetap ada.
        ->and(InstallmentSchedule::where('loan_id', $loan->id)->count())->toBe(0)
        ->and(Loan::find($loan->id))->not->toBeNull();
});

it('does not allow cancelling a loan that is already Dibatalkan', function () {
    $loan = Loan::factory()->create(['member_id' => $this->member->id, 'status' => 'Dibatalkan']);

    expect(LoanResource::canCorrect($loan))->toBeFalse();
});
