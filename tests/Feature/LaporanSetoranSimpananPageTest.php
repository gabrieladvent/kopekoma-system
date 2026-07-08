<?php

use App\Filament\Pages\LaporanSetoranSimpanan;
use App\Models\Agency;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\User;
use Livewire\Livewire;

// ── Akses ─────────────────────────────────────────────────────────────

it('denies the report page to a user without the permission', function () {
    asPetugas(); // seed roles
    $stranger = User::factory()->create(); // tanpa role/permission
    $this->actingAs($stranger);

    expect(LaporanSetoranSimpanan::canAccess())->toBeFalse();
});

it('grants access to super admin via Gate bypass', function () {
    asSuperAdmin();
    expect(LaporanSetoranSimpanan::canAccess())->toBeTrue();
});

// ── Preview ───────────────────────────────────────────────────────────

it('shows the deposit rows and net grand total after generate', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create(['agency_name' => 'Dinas Contoh']);
    $member = Member::factory()->create(['agency_id' => $agency->id, 'full_name' => 'Budi Santoso']);

    SavingsDeposit::factory()->create([
        'member_id' => $member->id,
        'savings_type' => 'wajib',
        'amount' => 100000,
        'period_month' => '2026-06-01',
        'deposit_date' => '2026-06-05',
        'deposit_method' => 'potong_gaji',
        'is_reversal' => false,
    ]);

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('data.basis', 'period_month')
        ->set('data.start', '2026-06-01')
        ->set('data.end', '2026-06-30')
        ->call('generate')
        ->assertHasNoFormErrors()
        ->assertSee('Budi Santoso')
        ->assertSee('Dinas Contoh')
        ->assertSee('Grand Total');
});

it('rejects a range longer than one year', function () {
    asSuperAdmin();

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('data.basis', 'deposit_date')
        ->set('data.start', '2026-01-01')
        ->set('data.end', '2027-06-01')
        ->call('generate')
        ->assertHasFormErrors(['end']);

    expect(true)->toBeTrue();
});

it('renders an empty-state message when no transactions match', function () {
    asSuperAdmin();

    Livewire::test(LaporanSetoranSimpanan::class)
        ->set('data.basis', 'deposit_date')
        ->set('data.start', '2020-01-01')
        ->set('data.end', '2020-12-31')
        ->call('generate')
        ->assertHasNoFormErrors()
        ->assertSee('Tidak ada data');
});
