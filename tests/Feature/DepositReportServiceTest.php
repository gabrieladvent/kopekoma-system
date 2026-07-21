<?php

use App\Models\Agency;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Services\BatchSalaryDeductionService;
use App\Services\DepositReportService;

beforeEach(function () {
    $this->service = app(DepositReportService::class);
    asSuperAdmin();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function makeDeposit(Member $member, array $overrides = []): SavingsDeposit
{
    return SavingsDeposit::factory()->create([
        'member_id' => $member->id,
        'savings_type' => 'wajib',
        'amount' => 100000,
        'deposit_date' => '2026-06-05',
        'period_month' => '2026-06-01',
        'deposit_method' => 'potong_gaji',
        ...$overrides,
    ]);
}

it('totals net of reversals (terbayar − reversal), reversal row still shown in detail', function () {
    $member = Member::factory()->create();
    $original = makeDeposit($member);
    makeDeposit($member, [
        'amount' => 100000,
        'is_reversal' => true,
        'reversal_of_id' => $original->id,
    ]);

    $filters = ['basis' => 'period_month', 'start' => '2026-06-01', 'end' => '2026-06-30'];

    // Grand total = 100000 − 100000 = 0.
    expect($this->service->totals($filters))->toBe('0.00')
        // Kedua baris (asli + reversal) tetap tampil untuk audit.
        ->and($this->service->rows($filters))->toHaveCount(2);
});

it('keeps bcmath precision (2 decimals, no float drift)', function () {
    $member = Member::factory()->create();
    makeDeposit($member, ['amount' => '100000.10']);
    makeDeposit($member, ['amount' => '50000.05']);

    $total = $this->service->totals(['basis' => 'period_month', 'start' => '2026-06-01', 'end' => '2026-06-30']);

    expect($total)->toBe('150000.15');
});

it('excludes period_month NULL rows on period_month basis but includes them on deposit_date basis', function () {
    $member = Member::factory()->create();
    // Sukarela tanpa period_month (setor sendiri).
    makeDeposit($member, [
        'savings_type' => 'sukarela',
        'amount' => 25000,
        'period_month' => null,
        'deposit_date' => '2026-06-10',
        'deposit_method' => 'setor_sendiri',
    ]);

    $periodBasis = $this->service->totals(['basis' => 'period_month', 'start' => '2026-06-01', 'end' => '2026-06-30']);
    $dateBasis = $this->service->totals(['basis' => 'deposit_date', 'start' => '2026-06-01', 'end' => '2026-06-30']);

    expect($periodBasis)->toBe('0.00')
        ->and($dateBasis)->toBe('25000.00');
});

it('reconciles with the salary-deduction batch when scoped (wajib + potong_gaji + period_month)', function () {
    $agency = Agency::factory()->create();
    $members = Member::factory()->count(3)->create(['agency_id' => $agency->id]);

    app(BatchSalaryDeductionService::class)->run(
        $agency,
        '2026-06-01',
        array_map(fn (Member $m) => ['member_id' => $m->id, 'amount' => '50000'], $members->all()),
    );

    $total = $this->service->totals([
        'basis' => 'period_month',
        'start' => '2026-06-01',
        'end' => '2026-06-30',
        'savings_type' => ['wajib'],
        'deposit_method' => 'potong_gaji',
        'agency_id' => $agency->id,
    ]);

    // 3 × 50000 = 150000, sama dengan batch.
    expect($total)->toBe('150000.00');
});

it('filters by savings_type, deposit_method, agency, and member', function () {
    $agencyA = Agency::factory()->create();
    $agencyB = Agency::factory()->create();
    $memberA = Member::factory()->create(['agency_id' => $agencyA->id]);
    $memberB = Member::factory()->create(['agency_id' => $agencyB->id]);

    makeDeposit($memberA, ['savings_type' => 'wajib', 'deposit_method' => 'potong_gaji', 'amount' => 100000]);
    makeDeposit($memberA, ['savings_type' => 'pokok', 'deposit_method' => 'setor_sendiri', 'amount' => 250000]);
    makeDeposit($memberB, ['savings_type' => 'wajib', 'deposit_method' => 'potong_gaji', 'amount' => 100000]);

    $base = ['basis' => 'period_month', 'start' => '2026-06-01', 'end' => '2026-06-30'];

    expect($this->service->totals([...$base, 'savings_type' => ['wajib']]))->toBe('200000.00')
        ->and($this->service->totals([...$base, 'deposit_method' => 'setor_sendiri']))->toBe('250000.00')
        ->and($this->service->totals([...$base, 'agency_id' => $agencyA->id]))->toBe('350000.00')
        ->and($this->service->totals([...$base, 'member_id' => $memberB->id]))->toBe('100000.00');
});

it('keeps transactions of soft-deleted (resigned) members in historical reports', function () {
    $member = Member::factory()->create();
    makeDeposit($member, ['amount' => 100000]);

    $member->delete(); // resign → soft delete

    $filters = ['basis' => 'period_month', 'start' => '2026-06-01', 'end' => '2026-06-30'];
    $rows = $this->service->rows($filters);

    expect($this->service->totals($filters))->toBe('100000.00')
        ->and($rows)->toHaveCount(1)
        // Relasi member tetap ter-load (withTrashed) → nama masih bisa dicetak.
        ->and($rows->first()->member)->not->toBeNull()
        ->and($rows->first()->member->full_name)->toBe($member->full_name);
});

it('returns empty total and no rows when the period has no transactions', function () {
    $filters = ['basis' => 'period_month', 'start' => '2026-06-01', 'end' => '2026-06-30'];

    expect($this->service->totals($filters))->toBe('0.00')
        ->and($this->service->rows($filters))->toHaveCount(0);
});

it('groups by OPD then member with net subtotals and a grand total consistent with totals()', function () {
    $agencyA = Agency::factory()->create(['agency_name' => 'Dinas A']);
    $agencyB = Agency::factory()->create(['agency_name' => 'Dinas B']);
    $memberA = Member::factory()->create(['agency_id' => $agencyA->id]);
    $memberB = Member::factory()->create(['agency_id' => $agencyB->id]);

    $original = makeDeposit($memberA, ['amount' => 100000]);
    makeDeposit($memberA, ['amount' => 30000, 'is_reversal' => true, 'reversal_of_id' => $original->id]);
    makeDeposit($memberB, ['amount' => 50000]);

    $filters = ['basis' => 'period_month', 'start' => '2026-06-01', 'end' => '2026-06-30'];
    $grouped = $this->service->grouped($filters);

    expect($grouped['groups'])->toHaveCount(2)
        // Grand total = (100000 − 30000) + 50000 = 120000, konsisten dengan totals().
        ->and($grouped['grand_total'])->toBe('120000.00')
        ->and($grouped['grand_total'])->toBe($this->service->totals($filters));

    $dinasA = collect($grouped['groups'])->firstWhere('agency', 'Dinas A');
    expect($dinasA['subtotal'])->toBe('70000.00')
        ->and($dinasA['members'])->toHaveCount(1)
        ->and($dinasA['members'][0]['subtotal'])->toBe('70000.00')
        // Baris reversal tetap ikut di grup (transparansi audit).
        ->and($dinasA['members'][0]['rows'])->toHaveCount(2);
});
