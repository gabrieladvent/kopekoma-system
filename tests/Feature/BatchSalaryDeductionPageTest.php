<?php

use App\Filament\Pages\BatchSalaryDeduction;
use App\Models\Agency;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\User;
use App\Services\BatchSalaryDeductionService;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

// ── Akses (D7, security #C: enforcement bukan sekadar sembunyikan tombol) ──

it('denies the batch page to a user without the permission', function () {
    asPetugas(); // seed roles
    $stranger = User::factory()->create(); // tanpa role/permission
    $this->actingAs($stranger);

    expect(BatchSalaryDeduction::canAccess())->toBeFalse();
});

it('grants batch page access to petugas, pengurus and super admin (D5/D7)', function () {
    expect(BatchSalaryDeduction::canAccess())->toBeFalse(); // belum login

    asPetugas();
    expect(BatchSalaryDeduction::canAccess())->toBeTrue();

    asPengurus();
    expect(BatchSalaryDeduction::canAccess())->toBeTrue();

    asSuperAdmin();
    // super_admin bypass Gate, tetapi permission juga ter-assign.
    expect(BatchSalaryDeduction::canAccess())->toBeTrue();
});

// ── Alur halaman ──────────────────────────────────────────────────────

it('loads active members of the chosen OPD with prefilled mandatory amount', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Aktif', 'mandatory_savings_amount' => 75000]);
    Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Keluar']); // tak aktif → tak dimuat

    $component = Livewire::test(BatchSalaryDeduction::class)
        ->set('data.agency_id', $agency->id);

    $rows = $component->get('data')['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['amount'])->toBe('75000.00')
        ->and($rows[0]['include'])->toBeTrue();
});

it('processes the batch from the page and creates one deposit per included member', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $a = Member::factory()->create(['agency_id' => $agency->id, 'mandatory_savings_amount' => 50000]);
    $b = Member::factory()->create(['agency_id' => $agency->id, 'mandatory_savings_amount' => 50000]);

    Livewire::test(BatchSalaryDeduction::class)
        ->set('data.agency_id', $agency->id)
        ->set('data.period_month', '2026-06-01')
        ->set('data.rows', [
            ['member_id' => $a->id, 'member_label' => 'a', 'include' => true, 'amount' => '50000'],
            ['member_id' => $b->id, 'member_label' => 'b', 'include' => false, 'amount' => '50000'],
        ])
        ->call('process')
        ->assertNotified();

    // Hanya anggota yang dicentang (include=true) yang disetor.
    expect(SavingsDeposit::where('savings_type', 'wajib')->count())->toBe(1)
        ->and(SavingsDeposit::where('member_id', $a->id)->exists())->toBeTrue()
        ->and(SavingsDeposit::where('member_id', $b->id)->exists())->toBeFalse();
});

// ── Rekap & export (3b, D7 security #E) ───────────────────────────────

it('withholds export recap from petugas but grants it to pengurus (D7)', function () {
    expect(asPetugas()->can('export_savings_recap'))->toBeFalse()
        ->and(asPengurus()->can('export_savings_recap'))->toBeTrue();
});

it('exports the recap and logs the export activity (security #E)', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $m = Member::factory()->create(['agency_id' => $agency->id, 'mandatory_savings_amount' => 50000]);
    app(BatchSalaryDeductionService::class)->run($agency, '2026-06-01', [['member_id' => $m->id, 'amount' => '50000']]);

    Livewire::test(BatchSalaryDeduction::class)
        ->set('data.agency_id', $agency->id)
        ->set('data.period_month', '2026-06-01')
        ->call('exportRecap');

    $export = Activity::where('event', 'export')->first();
    expect($export)->not->toBeNull()
        ->and($export->properties['rows'])->toBe(1)
        ->and($export->properties['agency_id'])->toBe($agency->id);
});
