<?php

use App\Livewire\Master\Agency\Agencies;
use App\Models\Agency;
use App\Models\Member;
use App\Models\User;
use Livewire\Livewire;

it('blocks access without the view permission', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('master.agencies'))->assertForbidden();
});

it('allows access for a super admin', function () {
    asSuperAdmin();

    $this->get(route('master.agencies'))->assertOk();
});

it('lists agencies', function () {
    asSuperAdmin();
    Agency::factory()->create(['agency_code' => 'OPD0001', 'agency_name' => 'Dinas Kesehatan']);

    Livewire::test(Agencies::class)
        ->assertOk()
        ->assertSee('OPD0001')
        ->assertSee('Dinas Kesehatan');
});

it('creates an agency with normalized phone', function () {
    asSuperAdmin();

    Livewire::test(Agencies::class)
        ->call('create')
        ->set('agency_code', 'OPD0009')
        ->set('agency_name', 'Dinas Pendidikan')
        ->set('payroll_treasurer', 'Budi')
        ->set('pic_phone_number', '081234567890')
        ->set('statusForm', 'Aktif')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    expect(Agency::where('agency_code', 'OPD0009')->first())
        ->not->toBeNull()
        ->payroll_treasurer->toBe('Budi')
        ->pic_phone_number->toBe('+6281234567890');
});

it('validates required fields and unique code', function () {
    asSuperAdmin();
    Agency::factory()->create(['agency_code' => 'OPD0001']);

    Livewire::test(Agencies::class)
        ->call('create')
        ->set('agency_code', '')
        ->set('agency_name', '')
        ->call('save')
        ->assertHasErrors(['agency_code', 'agency_name']);

    Livewire::test(Agencies::class)
        ->call('create')
        ->set('agency_code', 'OPD0001')
        ->set('agency_name', 'Duplikat')
        ->call('save')
        ->assertHasErrors(['agency_code']);
});

it('edits an agency keeping its own code unique and showing local phone', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create([
        'agency_code' => 'OPD0002',
        'agency_name' => 'Lama',
        'pic_phone_number' => '+6281234567890',
    ]);

    Livewire::test(Agencies::class)
        ->call('edit', $agency->id)
        ->assertSet('agency_code', 'OPD0002')
        ->assertSet('pic_phone_number', '81234567890')
        ->set('agency_name', 'Baru')
        ->call('save')
        ->assertHasNoErrors();

    expect($agency->fresh()->agency_name)->toBe('Baru');
});

it('toggles active status', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create(['status' => 'Aktif']);

    Livewire::test(Agencies::class)->call('toggleActive', $agency->id);

    expect($agency->fresh()->status)->toBe('Non-Aktif');
});

it('deletes an agency without members', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();

    Livewire::test(Agencies::class)->call('delete', $agency->id);

    expect(Agency::find($agency->id))->toBeNull();
});

it('refuses to delete an agency that still has members', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    Member::factory()->create(['agency_id' => $agency->id]);

    Livewire::test(Agencies::class)->call('delete', $agency->id);

    expect(Agency::find($agency->id))->not->toBeNull();
});

it('filters by search term and status', function () {
    asSuperAdmin();
    Agency::factory()->create(['agency_code' => 'OPD1111', 'agency_name' => 'Alpha', 'status' => 'Aktif']);
    Agency::factory()->create(['agency_code' => 'OPD2222', 'agency_name' => 'Beta', 'status' => 'Non-Aktif']);

    Livewire::test(Agencies::class)
        ->set('search', 'Alpha')
        ->assertSee('OPD1111')
        ->assertDontSee('OPD2222')
        ->set('search', '')
        ->set('status', 'inactive')
        ->assertSee('OPD2222')
        ->assertDontSee('OPD1111');
});

it('generates a unique agency code', function () {
    asSuperAdmin();

    $component = Livewire::test(Agencies::class)->call('generateCode');

    expect($component->get('agency_code'))->toMatch('/^OPD\d{4}$/');
});
