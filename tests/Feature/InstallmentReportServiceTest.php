<?php

use App\Models\Agency;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Services\InstallmentReportService;

beforeEach(function () {
    $this->service = app(InstallmentReportService::class);
    asSuperAdmin();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function makeInstallment(Loan $loan, array $overrides = []): Installment
{
    return Installment::factory()->create([
        'loan_id' => $loan->id,
        'payment_date' => '2026-06-05',
        'amount_paid' => 1090000,
        ...$overrides,
    ]);
}

it('totals net of reversals using amount_paid, reversal row still shown', function () {
    $loan = Loan::factory()->create();
    $original = makeInstallment($loan);
    makeInstallment($loan, [
        'amount_paid' => 1090000,
        'is_reversal' => true,
        'reversal_of_id' => $original->id,
    ]);

    $filters = ['start' => '2026-06-01', 'end' => '2026-06-30'];

    expect($this->service->totals($filters))->toBe('0.00')
        ->and($this->service->rows($filters))->toHaveCount(2);
});

it('keeps bcmath precision on amount_paid', function () {
    $loan = Loan::factory()->create();
    makeInstallment($loan, ['amount_paid' => '1090000.10', 'installment_seq' => 1]);
    makeInstallment($loan, ['amount_paid' => '910000.05', 'installment_seq' => 2]);

    $total = $this->service->totals(['start' => '2026-06-01', 'end' => '2026-06-30']);

    expect($total)->toBe('2000000.15');
});

it('filters by payment_date range, agency, and member', function () {
    $agencyA = Agency::factory()->create();
    $agencyB = Agency::factory()->create();
    $memberA = Member::factory()->create(['agency_id' => $agencyA->id]);
    $memberB = Member::factory()->create(['agency_id' => $agencyB->id]);
    $loanA = Loan::factory()->create(['member_id' => $memberA->id]);
    $loanB = Loan::factory()->create(['member_id' => $memberB->id]);

    makeInstallment($loanA, ['amount_paid' => 1000000, 'payment_date' => '2026-06-05']);
    makeInstallment($loanB, ['amount_paid' => 500000, 'payment_date' => '2026-06-10']);
    // Di luar range → tak terhitung.
    makeInstallment($loanA, ['amount_paid' => 999999, 'payment_date' => '2026-07-05', 'installment_seq' => 2]);

    $base = ['start' => '2026-06-01', 'end' => '2026-06-30'];

    expect($this->service->totals($base))->toBe('1500000.00')
        ->and($this->service->totals([...$base, 'agency_id' => $agencyA->id]))->toBe('1000000.00')
        ->and($this->service->totals([...$base, 'member_id' => $memberB->id]))->toBe('500000.00');
});

it('keeps installments of soft-deleted members with member chain loaded', function () {
    $member = Member::factory()->create();
    $loan = Loan::factory()->create(['member_id' => $member->id]);
    makeInstallment($loan, ['amount_paid' => 1000000]);

    $member->delete(); // resign

    $filters = ['start' => '2026-06-01', 'end' => '2026-06-30'];
    $rows = $this->service->rows($filters);

    expect($this->service->totals($filters))->toBe('1000000.00')
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()->loan->member)->not->toBeNull()
        ->and($rows->first()->loan->member->full_name)->toBe($member->full_name);
});

it('returns empty total and no rows for an empty period', function () {
    $filters = ['start' => '2026-06-01', 'end' => '2026-06-30'];

    expect($this->service->totals($filters))->toBe('0.00')
        ->and($this->service->rows($filters))->toHaveCount(0);
});

it('groups by OPD then member with net subtotals via the loan.member chain', function () {
    $agency = Agency::factory()->create(['agency_name' => 'Dinas X']);
    $member = Member::factory()->create(['agency_id' => $agency->id]);
    $loan = Loan::factory()->create(['member_id' => $member->id]);

    $original = makeInstallment($loan, ['amount_paid' => 1000000, 'installment_seq' => 1]);
    makeInstallment($loan, [
        'amount_paid' => 400000, 'installment_seq' => 2,
        'is_reversal' => true, 'reversal_of_id' => $original->id,
    ]);

    $filters = ['start' => '2026-06-01', 'end' => '2026-06-30'];
    $grouped = $this->service->grouped($filters);

    expect($grouped['groups'])->toHaveCount(1)
        ->and($grouped['groups'][0]['agency'])->toBe('Dinas X')
        // net = 1000000 − 400000 = 600000, konsisten dengan totals().
        ->and($grouped['grand_total'])->toBe('600000.00')
        ->and($grouped['grand_total'])->toBe($this->service->totals($filters))
        ->and($grouped['groups'][0]['members'][0]['subtotal'])->toBe('600000.00')
        ->and($grouped['groups'][0]['members'][0]['rows'])->toHaveCount(2);
});
