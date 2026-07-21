<?php

use App\Actions\ReverseTransaction;
use App\Models\Agency;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Services\BatchSalaryDeductionService;
use App\Services\SavingsBalanceService;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->service = app(BatchSalaryDeductionService::class);
    asSuperAdmin();
});

function rowsFor(array $members): array
{
    return array_map(fn (Member $m) => ['member_id' => $m->id, 'amount' => '50000'], $members);
}

it('creates one wajib deposit per member with audit per row (chunked create)', function () {
    $agency = Agency::factory()->create();
    $members = Member::factory()->count(3)->create(['agency_id' => $agency->id]);

    $result = $this->service->run($agency, '2026-06-01', rowsFor($members->all()));

    expect($result['created'])->toBe(3)
        ->and($result['skipped'])->toBe(0)
        ->and(SavingsDeposit::where('savings_type', 'wajib')->count())->toBe(3);

    $deposit = SavingsDeposit::where('member_id', $members->first()->id)->first();
    expect($deposit->deposit_method)->toBe('potong_gaji')
        ->and($deposit->deposited_by)->toBe('bendahara')
        ->and($deposit->transaction_number)->toStartWith('STR-2026-')
        ->and($deposit->recorded_by)->not->toBeNull();

    // Audit per-baris (LogsActivity) ada untuk tiap anggota.
    expect(Activity::where('subject_type', SavingsDeposit::class)->where('event', 'created')->count())->toBe(3);
});

it('reserves a contiguous number range without collision', function () {
    $agency = Agency::factory()->create();
    $members = Member::factory()->count(3)->create(['agency_id' => $agency->id]);

    $this->service->run($agency, '2026-06-01', rowsFor($members->all()));

    $numbers = SavingsDeposit::orderBy('transaction_number')->pluck('transaction_number')->all();
    expect($numbers)->toBe(['STR-2026-000001', 'STR-2026-000002', 'STR-2026-000003']);
});

it('logs the batch as a single event above the per-row logs', function () {
    $agency = Agency::factory()->create(['agency_name' => 'Dinas A']);
    $members = Member::factory()->count(2)->create(['agency_id' => $agency->id]);

    $this->service->run($agency, '2026-06-01', rowsFor($members->all()));

    expect(Activity::where('event', 'batch_potong_gaji')->count())->toBe(1);
    $batch = Activity::where('event', 'batch_potong_gaji')->first();
    expect($batch->properties['created'])->toBe(2);
});

it('skips members who already have an active wajib deposit for the period (double-run guard)', function () {
    $agency = Agency::factory()->create();
    $members = Member::factory()->count(2)->create(['agency_id' => $agency->id]);

    $this->service->run($agency, '2026-06-01', rowsFor($members->all()));
    // Run kedua periode sama → semua dilewati, tak ada duplikat.
    $second = $this->service->run($agency, '2026-06-01', rowsFor($members->all()));

    expect($second['created'])->toBe(0)
        ->and($second['skipped'])->toBe(2)
        ->and(SavingsDeposit::where('savings_type', 'wajib')->count())->toBe(2);
});

it('allows re-running after a deposit was reversed (slot freed)', function () {
    $agency = Agency::factory()->create();
    $member = Member::factory()->create(['agency_id' => $agency->id]);

    $this->service->run($agency, '2026-06-01', [['member_id' => $member->id, 'amount' => '50000']]);
    $deposit = SavingsDeposit::where('member_id', $member->id)->first();

    // Reversal → slot periode kosong lagi.
    app(ReverseTransaction::class)($deposit, 'koreksi nominal salah');

    $rerun = $this->service->run($agency, '2026-06-01', [['member_id' => $member->id, 'amount' => '75000']]);

    expect($rerun['created'])->toBe(1)
        ->and($rerun['skipped'])->toBe(0)
        // saldo wajib = setoran asli 50rb − reversal 50rb + setoran baru 75rb = 75rb
        ->and(app(SavingsBalanceService::class)->balanceByType($member->fresh(), 'wajib'))->toBe('75000.00');
});

it('creates one deposit per type per member for multi-type rows', function () {
    $agency = Agency::factory()->create();
    $member = Member::factory()->create(['agency_id' => $agency->id]);

    $result = $this->service->run($agency, '2026-06-01', [[
        'member_id' => $member->id,
        'deposits' => [
            ['type' => 'wajib', 'amount' => '100000'],
            ['type' => 'pokok', 'amount' => '250000'],
        ],
    ]]);

    expect($result['created'])->toBe(2)
        ->and(SavingsDeposit::where('member_id', $member->id)->pluck('savings_type')->sort()->values()->all())
        ->toBe(['pokok', 'wajib']);
});

it('dedups per (member, type, period) so a re-run only adds missing types', function () {
    $agency = Agency::factory()->create();
    $member = Member::factory()->create(['agency_id' => $agency->id]);

    // Run pertama: wajib saja.
    $this->service->run($agency, '2026-06-01', [[
        'member_id' => $member->id,
        'deposits' => [['type' => 'wajib', 'amount' => '100000']],
    ]]);

    // Run kedua: wajib (sudah ada → skip) + pokok (baru → dibuat).
    $second = $this->service->run($agency, '2026-06-01', [[
        'member_id' => $member->id,
        'deposits' => [
            ['type' => 'wajib', 'amount' => '100000'],
            ['type' => 'pokok', 'amount' => '250000'],
        ],
    ]]);

    expect($second['created'])->toBe(1)
        ->and($second['skipped'])->toBe(1)
        ->and(SavingsDeposit::where('member_id', $member->id)->where('savings_type', 'wajib')->count())->toBe(1)
        ->and(SavingsDeposit::where('member_id', $member->id)->where('savings_type', 'pokok')->count())->toBe(1);
});

it('rolls back the whole batch when a row amount is invalid', function () {
    $agency = Agency::factory()->create();
    $members = Member::factory()->count(2)->create(['agency_id' => $agency->id]);

    $rows = rowsFor($members->all());
    $rows[1]['amount'] = '0'; // invalid

    expect(fn () => $this->service->run($agency, '2026-06-01', $rows))
        ->toThrow(InvalidArgumentException::class);

    expect(SavingsDeposit::count())->toBe(0); // atomic — tak ada yang tersisa
});
