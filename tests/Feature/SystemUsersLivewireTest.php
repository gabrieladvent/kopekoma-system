<?php

use App\Livewire\System\UserForm;
use App\Livewire\System\Users;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('blocks access for non super admin', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('system.users'))->assertForbidden();
    $this->get(route('system.users.create'))->assertForbidden();
});

it('allows access for a super admin', function () {
    asSuperAdmin();

    $this->get(route('system.users'))->assertOk();
});

it('lists and searches users', function () {
    asSuperAdmin();
    User::factory()->create(['name' => 'Alpha', 'email' => 'alpha@kopekoma.test']);
    User::factory()->create(['name' => 'Beta', 'email' => 'beta@kopekoma.test']);

    Livewire::test(Users::class)
        ->assertSee('Alpha')
        ->assertSee('Beta')
        ->set('search', 'Alpha')
        ->assertSee('alpha@kopekoma.test')
        ->assertDontSee('beta@kopekoma.test');
});

it('toggles a user active status', function () {
    asSuperAdmin();
    $user = User::factory()->create(['is_active' => true]);

    Livewire::test(Users::class)->call('toggleActive', $user->id);

    expect($user->fresh()->is_active)->toBeFalse();
});

it('refuses to toggle or delete your own account', function () {
    $self = asSuperAdmin();

    Livewire::test(Users::class)->call('toggleActive', $self->id);
    Livewire::test(Users::class)->call('delete', $self->id);

    expect($self->fresh())->not->toBeNull()
        ->and($self->fresh()->is_active)->toBeTrue();
});

it('deletes another user', function () {
    asSuperAdmin();
    $user = User::factory()->create();

    Livewire::test(Users::class)->call('delete', $user->id);

    expect(User::find($user->id))->toBeNull();
});

it('creates a user with roles, hashed password, and verification', function () {
    asSuperAdmin();
    Role::firstOrCreate(['name' => 'pengurus', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'petugas', 'guard_name' => 'web']);

    Livewire::test(UserForm::class)
        ->set('name', 'Sri Bendahara')
        ->set('email', 'sri@kopekoma.test')
        ->set('password', 'secret123')
        ->set('password_confirmation', 'secret123')
        ->set('selectedRoles', ['pengurus', 'petugas'])
        ->set('email_verified', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('system.users'));

    $user = User::where('email', 'sri@kopekoma.test')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check('secret123', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasAllRoles(['pengurus', 'petugas']))->toBeTrue();
});

it('validates required fields, unique email, and password rules', function () {
    asSuperAdmin();
    User::factory()->create(['email' => 'dupe@kopekoma.test']);

    Livewire::test(UserForm::class)
        ->set('name', '')
        ->set('email', '')
        ->set('password', '')
        ->call('save')
        ->assertHasErrors(['name', 'email', 'password']);

    Livewire::test(UserForm::class)
        ->set('name', 'Lain')
        ->set('email', 'dupe@kopekoma.test')
        ->set('password', 'short')
        ->set('password_confirmation', 'mismatch')
        ->call('save')
        ->assertHasErrors(['email', 'password']);
});

it('keeps the existing password and updates roles on edit', function () {
    asSuperAdmin();
    Role::firstOrCreate(['name' => 'pengurus', 'guard_name' => 'web']);
    $user = User::factory()->create(['password' => Hash::make('original')]);

    Livewire::test(UserForm::class, ['user' => $user])
        ->set('name', 'Nama Baru')
        ->set('password', '')
        ->set('selectedRoles', ['pengurus'])
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Nama Baru')
        ->and(Hash::check('original', $user->password))->toBeTrue()
        ->and($user->hasRole('pengurus'))->toBeTrue();
});

it('forces the active flag to stay true when editing your own account', function () {
    $self = asSuperAdmin();

    Livewire::test(UserForm::class, ['user' => $self])
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($self->fresh()->is_active)->toBeTrue();
});
