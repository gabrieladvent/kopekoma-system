<?php

use App\Livewire\Master\AgencyDetail;
use App\Livewire\Master\GradeDetail;
use App\Models\Agency;
use App\Models\Grade;
use App\Models\User;
use Livewire\Livewire;

it('blocks grade detail without the view permission', function () {
    $this->actingAs(User::factory()->create());
    $grade = Grade::create(['code' => 'GOL-0001', 'name' => 'Golongan I', 'mandatory_savings_amount' => 50000, 'is_active' => true]);

    $this->get(route('master.grades.show', $grade))->assertForbidden();
});

it('shows grade detail with audit trail and opens an activity popup', function () {
    asSuperAdmin();
    $grade = Grade::create(['code' => 'GOL-0001', 'name' => 'Golongan I', 'mandatory_savings_amount' => 50000, 'is_active' => true]);
    $grade->update(['mandatory_savings_amount' => 75000]); // logs an "updated" activity

    $activity = $grade->activities()->latest()->first();

    Livewire::test(GradeDetail::class, ['grade' => $grade])
        ->assertOk()
        ->assertSee('Golongan I')
        ->assertSee('Audit Trail')
        ->call('viewAudit', $activity->id)
        ->assertSet('showAudit', true)
        ->assertSee('Perubahan Data')
        ->assertSee('Simpanan Wajib');
});

it('shows agency detail with audit trail and opens an activity popup', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create(['agency_name' => 'Dinas Kesehatan']);
    $agency->update(['agency_name' => 'Dinas Pendidikan']); // logs an "updated" activity

    $activity = $agency->activities()->latest()->first();

    Livewire::test(AgencyDetail::class, ['agency' => $agency])
        ->assertOk()
        ->assertSee('Dinas Pendidikan')
        ->assertSee('Audit Trail')
        ->call('viewAudit', $activity->id)
        ->assertSet('showAudit', true)
        ->assertSee('Perubahan Data')
        ->assertSee('Nama OPD / Instansi');
});

it('is read-only by default and edits a grade inline', function () {
    asSuperAdmin();
    $grade = Grade::create(['code' => 'GOL-0002', 'name' => 'Lama', 'mandatory_savings_amount' => 50000, 'is_active' => true]);

    Livewire::test(GradeDetail::class, ['grade' => $grade])
        ->assertSet('editing', false)
        ->call('startEdit')
        ->assertSet('editing', true)
        ->assertSet('code', 'GOL-0002')
        ->set('name', 'Baru')
        ->set('mandatory_savings_amount', 90000)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editing', false);

    expect($grade->fresh())
        ->name->toBe('Baru')
        ->mandatory_savings_amount->toBe(90000);
});

it('deletes a grade from detail and redirects to the list', function () {
    asSuperAdmin();
    $grade = Grade::create(['code' => 'GOL-0003', 'name' => 'Hapus', 'mandatory_savings_amount' => 50000, 'is_active' => true]);

    Livewire::test(GradeDetail::class, ['grade' => $grade])
        ->call('delete')
        ->assertRedirect(route('master.grades'));

    expect(Grade::find($grade->id))->toBeNull();
});

it('edits an agency inline with normalized phone', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create(['agency_code' => 'OPD0002', 'agency_name' => 'Lama']);

    Livewire::test(AgencyDetail::class, ['agency' => $agency])
        ->assertSet('editing', false)
        ->call('startEdit')
        ->assertSet('editing', true)
        ->assertSet('agency_code', 'OPD0002')
        ->set('agency_name', 'Baru')
        ->set('pic_phone_number', '081234567890')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editing', false);

    expect($agency->fresh())
        ->agency_name->toBe('Baru')
        ->pic_phone_number->toBe('+6281234567890');
});

it('deletes an agency from detail and redirects to the list', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();

    Livewire::test(AgencyDetail::class, ['agency' => $agency])
        ->call('delete')
        ->assertRedirect(route('master.agencies'));

    expect(Agency::find($agency->id))->toBeNull();
});
