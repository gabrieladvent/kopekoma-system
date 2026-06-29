<?php

use App\Livewire\Savings\Deposit\BatchSalaryDeduction;
use App\Models\Agency;
use App\Models\Member;
use App\Models\SavingsDeposit;
use Livewire\Livewire;

it('adds an optional, nullable, unchecked sukarela line per member', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Aktif', 'mandatory_savings_amount' => 50000]);

    $rows = Livewire::test(BatchSalaryDeduction::class)
        ->set('agency_id', $agency->id)
        ->set('period_month', '2026-06')
        ->get('rows');

    $sukarela = collect($rows[0]['lines'])->firstWhere('savings_type', 'sukarela');

    expect($sukarela)->not->toBeNull()
        ->and($sukarela['amount'])->toBeNull()      // default kosong (nullable)
        ->and($sukarela['include'])->toBeFalse()    // tak tercentang default
        ->and($sukarela['done'])->toBeFalse();
});

it('creates a sukarela deposit when an amount is entered in the batch', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $m = Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Aktif']);

    Livewire::test(BatchSalaryDeduction::class)
        ->set('agency_id', $agency->id)
        ->set('period_month', '2026-06')
        ->set('rows', [
            ['member_id' => $m->id, 'member_label' => 'x', 'include' => true, 'lines' => [
                ['savings_type' => 'sukarela', 'type_label' => 'Simpanan Sukarela', 'include' => true, 'amount' => '75000', 'done' => false],
            ]],
        ])
        ->call('process');

    $deposit = SavingsDeposit::where('member_id', $m->id)->where('savings_type', 'sukarela')->first();

    expect($deposit)->not->toBeNull()
        ->and((float) $deposit->amount)->toBe(75000.0)
        ->and($deposit->deposit_method)->toBe('potong_gaji')
        ->and($deposit->period_month->format('Y-m'))->toBe('2026-06');
});

it('skips a checked sukarela line left without an amount (nullable, no deposit)', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $m = Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Aktif', 'mandatory_savings_amount' => 50000]);

    Livewire::test(BatchSalaryDeduction::class)
        ->set('agency_id', $agency->id)
        ->set('period_month', '2026-06')
        ->set('rows', [
            ['member_id' => $m->id, 'member_label' => 'x', 'include' => true, 'lines' => [
                ['savings_type' => 'wajib', 'type_label' => 'Simpanan Wajib', 'include' => true, 'amount' => '50000', 'done' => false],
                ['savings_type' => 'sukarela', 'type_label' => 'Simpanan Sukarela', 'include' => true, 'amount' => null, 'done' => false],
            ]],
        ])
        ->call('process');

    // Wajib tetap dibuat; sukarela tanpa nominal dilewati (tidak meng-abort batch).
    expect(SavingsDeposit::where('member_id', $m->id)->pluck('savings_type')->all())->toBe(['wajib']);
});
