<?php

use App\Livewire\Master\Grades;
use App\Models\Grade;
use App\Models\Member;
use App\Models\User;
use Livewire\Livewire;

function makeGrade(array $attributes = []): Grade
{
    return Grade::create(array_merge([
        'code' => 'GOL-'.fake()->unique()->numerify('####'),
        'name' => 'Golongan '.fake()->randomLetter(),
        'mandatory_savings_amount' => 50000,
        'is_active' => true,
    ], $attributes));
}

it('blocks access without the view permission', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('master.grades'))->assertForbidden();
});

it('allows access for users granted the view permission', function () {
    asRole('pengurus'); // pengurus carries grade permissions in the RBAC matrix

    $this->get(route('master.grades'))->assertOk();
});

it('lists grades', function () {
    asSuperAdmin();
    $grade = makeGrade(['code' => 'GOL-0001', 'name' => 'Golongan I']);

    Livewire::test(Grades::class)
        ->assertOk()
        ->assertSee('GOL-0001')
        ->assertSee('Golongan I');
});

it('creates a grade', function () {
    asSuperAdmin();

    Livewire::test(Grades::class)
        ->call('create')
        ->set('code', 'GOL-0009')
        ->set('name', 'Golongan IX')
        ->set('mandatory_savings_amount', 75000)
        ->set('is_active', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    expect(Grade::where('code', 'GOL-0009')->first())
        ->not->toBeNull()
        ->mandatory_savings_amount->toBe(75000);
});

it('validates required fields and unique code', function () {
    asSuperAdmin();
    makeGrade(['code' => 'GOL-0001']);

    Livewire::test(Grades::class)
        ->call('create')
        ->set('code', '')
        ->set('name', '')
        ->set('mandatory_savings_amount', null)
        ->call('save')
        ->assertHasErrors(['code', 'name', 'mandatory_savings_amount']);

    Livewire::test(Grades::class)
        ->call('create')
        ->set('code', 'GOL-0001')
        ->set('name', 'Duplikat')
        ->set('mandatory_savings_amount', 1000)
        ->call('save')
        ->assertHasErrors(['code']);
});

it('edits a grade keeping its own code unique', function () {
    asSuperAdmin();
    $grade = makeGrade(['code' => 'GOL-0002', 'name' => 'Lama']);

    Livewire::test(Grades::class)
        ->call('edit', $grade->id)
        ->assertSet('code', 'GOL-0002')
        ->set('name', 'Baru')
        ->call('save')
        ->assertHasNoErrors();

    expect($grade->fresh()->name)->toBe('Baru');
});

it('toggles active status', function () {
    asSuperAdmin();
    $grade = makeGrade(['is_active' => true]);

    Livewire::test(Grades::class)->call('toggleActive', $grade->id);

    expect($grade->fresh()->is_active)->toBeFalse();
});

it('deletes a grade without members', function () {
    asSuperAdmin();
    $grade = makeGrade();

    Livewire::test(Grades::class)->call('delete', $grade->id);

    expect(Grade::find($grade->id))->toBeNull();
});

it('refuses to delete a grade that still has members', function () {
    asSuperAdmin();
    $grade = makeGrade();
    Member::factory()->create(['grade_id' => $grade->id]);

    Livewire::test(Grades::class)->call('delete', $grade->id);

    expect(Grade::find($grade->id))->not->toBeNull();
});

it('filters by search term and status', function () {
    asSuperAdmin();
    makeGrade(['code' => 'GOL-1111', 'name' => 'Alpha', 'is_active' => true]);
    makeGrade(['code' => 'GOL-2222', 'name' => 'Beta', 'is_active' => false]);

    Livewire::test(Grades::class)
        ->set('search', 'Alpha')
        ->assertSee('GOL-1111')
        ->assertDontSee('GOL-2222')
        ->set('search', '')
        ->set('status', 'inactive')
        ->assertSee('GOL-2222')
        ->assertDontSee('GOL-1111');
});

it('generates a unique grade code', function () {
    asSuperAdmin();

    $component = Livewire::test(Grades::class)->call('generateCode');

    expect($component->get('code'))->toMatch('/^GOL-\d{4}$/');
});
