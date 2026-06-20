<?php

use App\Filament\Pages\BatchSalaryDeduction;
use App\Models\Agency;
use App\Models\Member;
use App\Models\MemberHolidaySaving;
use App\Models\SavingsDeposit;
use App\Models\User;
use App\Services\BatchSalaryDeductionService;
use App\Settings\CooperativeSettings;
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

it('loads active members of the chosen OPD with per-member savings lines, wajib prefilled', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Aktif', 'mandatory_savings_amount' => 75000]);
    Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Keluar']); // tak aktif → tak dimuat

    $component = Livewire::test(BatchSalaryDeduction::class)
        ->set('data.agency_id', $agency->id);

    $rows = $component->get('data')['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['include'])->toBeTrue();

    $wajib = collect($rows[0]['lines'])->firstWhere('savings_type', 'wajib');

    expect($wajib['amount'])->toBe('75000.00')
        ->and($wajib['include'])->toBeTrue()
        // pokok & wajib_belanja muncul tapi default tak dicentang.
        ->and(collect($rows[0]['lines'])->pluck('savings_type')->all())
        ->toBe(['wajib', 'pokok', 'wajib_belanja']);
});

it('processes the batch from the page and creates deposits only for included members', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $a = Member::factory()->create(['agency_id' => $agency->id, 'mandatory_savings_amount' => 50000]);
    $b = Member::factory()->create(['agency_id' => $agency->id, 'mandatory_savings_amount' => 50000]);

    Livewire::test(BatchSalaryDeduction::class)
        ->set('data.agency_id', $agency->id)
        ->set('data.period_month', '2026-06-01')
        ->set('data.rows', [
            ['member_id' => $a->id, 'member_label' => 'a', 'include' => true, 'lines' => [
                ['savings_type' => 'wajib', 'type_label' => 'Simpanan Wajib', 'include' => true, 'amount' => '50000'],
            ]],
            ['member_id' => $b->id, 'member_label' => 'b', 'include' => false, 'lines' => [
                ['savings_type' => 'wajib', 'type_label' => 'Simpanan Wajib', 'include' => true, 'amount' => '50000'],
            ]],
        ])
        ->call('process')
        ->assertNotified();

    // Hanya anggota yang "Ikut" (include=true) yang disetor.
    expect(SavingsDeposit::where('savings_type', 'wajib')->count())->toBe(1)
        ->and(SavingsDeposit::where('member_id', $a->id)->exists())->toBeTrue()
        ->and(SavingsDeposit::where('member_id', $b->id)->exists())->toBeFalse();
});

it('processes multiple savings types per member in one batch', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $m = Member::factory()->create(['agency_id' => $agency->id, 'mandatory_savings_amount' => 50000]);

    Livewire::test(BatchSalaryDeduction::class)
        ->set('data.agency_id', $agency->id)
        ->set('data.period_month', '2026-06-01')
        ->set('data.rows', [
            ['member_id' => $m->id, 'member_label' => 'x', 'include' => true, 'lines' => [
                ['savings_type' => 'wajib', 'type_label' => 'Simpanan Wajib', 'include' => true, 'amount' => '50000'],
                ['savings_type' => 'pokok', 'type_label' => 'Simpanan Pokok', 'include' => true, 'amount' => null],
            ]],
        ])
        ->call('process')
        ->assertNotified();

    $deposits = SavingsDeposit::where('member_id', $m->id)->get();

    expect($deposits->pluck('savings_type')->sort()->values()->all())->toBe(['pokok', 'wajib'])
        // wajib pakai nominal per anggota; pokok pakai nominal tetap ketentuan koperasi (di-derive server-side).
        ->and((float) $deposits->firstWhere('savings_type', 'wajib')->amount)->toBe(50000.0)
        ->and((float) $deposits->firstWhere('savings_type', 'pokok')->amount)
        ->toBe((float) app(CooperativeSettings::class)->savings_pokok_amount);
});

it('skips a member type whose toggle is off and creates only the checked types', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $m = Member::factory()->create(['agency_id' => $agency->id, 'mandatory_savings_amount' => 50000]);

    Livewire::test(BatchSalaryDeduction::class)
        ->set('data.agency_id', $agency->id)
        ->set('data.period_month', '2026-06-01')
        ->set('data.rows', [
            ['member_id' => $m->id, 'member_label' => 'x', 'include' => true, 'lines' => [
                ['savings_type' => 'wajib', 'type_label' => 'Simpanan Wajib', 'include' => true, 'amount' => '50000'],
                ['savings_type' => 'pokok', 'type_label' => 'Simpanan Pokok', 'include' => false, 'amount' => null],
            ]],
        ])
        ->call('process')
        ->assertNotified();

    expect(SavingsDeposit::where('member_id', $m->id)->pluck('savings_type')->all())->toBe(['wajib']);
});

it('adds a hari_raya line per member only when an active program covers the period, stored by program year', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $member = Member::factory()->create(['agency_id' => $agency->id, 'mandatory_savings_amount' => 50000]);
    MemberHolidaySaving::factory()->year(2026)->create([
        'member_id' => $member->id,
        'monthly_amount' => 80000,
    ]);

    $component = Livewire::test(BatchSalaryDeduction::class)
        ->set('data.agency_id', $agency->id)
        ->set('data.period_month', '2026-06-01');

    $lines = collect($component->get('data')['rows'][0]['lines']);

    // hari_raya muncul (program aktif memuat Juni 2026) & tercentang default.
    expect($lines->pluck('savings_type')->all())->toContain('hari_raya')
        ->and($lines->firstWhere('savings_type', 'hari_raya')['include'])->toBeTrue();

    $component->call('process')->assertNotified();

    $hariRaya = SavingsDeposit::where('member_id', $member->id)->where('savings_type', 'hari_raya')->first();

    expect($hariRaya)->not->toBeNull()
        ->and((float) $hariRaya->amount)->toBe(80000.0)
        // periode disimpan sebagai tahun program (konsisten dgn setoran manual), bukan bulan potong gaji.
        ->and($hariRaya->period_month->format('Y'))->toBe('2026');
});

it('locks a member type already deposited for the period and never duplicates it', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $m = Member::factory()->create(['agency_id' => $agency->id, 'mandatory_savings_amount' => 50000]);
    // Sudah ada setoran wajib aktif untuk Juni 2026.
    SavingsDeposit::factory()->create([
        'member_id' => $m->id,
        'savings_type' => 'wajib',
        'amount' => 50000,
        'period_month' => '2026-06-01',
        'is_reversal' => false,
    ]);

    $component = Livewire::test(BatchSalaryDeduction::class)
        ->set('data.agency_id', $agency->id)
        ->set('data.period_month', '2026-06-01');

    $wajib = collect($component->get('data')['rows'][0]['lines'])->firstWhere('savings_type', 'wajib');

    // Wajib terkunci & tak tercentang karena sudah disetor periode ini.
    expect($wajib['done'])->toBeTrue()
        ->and($wajib['include'])->toBeFalse()
        ->and($wajib['type_label'])->toContain('sudah disetor');

    // Walau dipaksa centang lewat state, service tetap tak menduplikasi.
    $component
        ->set('data.rows.0.lines.0.include', true)
        ->call('process');

    expect(SavingsDeposit::where('member_id', $m->id)->where('savings_type', 'wajib')->count())->toBe(1);
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
