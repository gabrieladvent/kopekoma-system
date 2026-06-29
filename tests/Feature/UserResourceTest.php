<?php

use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    asSuperAdmin();
});

it('lists users on the index page', function () {
    $users = User::factory()->count(3)->create();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords($users);
});

it('creates a user with multiple roles and a hashed password', function () {
    $pengurus = Role::firstOrCreate(['name' => 'pengurus', 'guard_name' => 'web']);
    $petugas = Role::firstOrCreate(['name' => 'petugas', 'guard_name' => 'web']);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Sri Bendahara',
            'email' => 'sri@kopekoma.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'roles' => [$pengurus->id, $petugas->id],
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'sri@kopekoma.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('secret123', $user->password))->toBeTrue()
        ->and($user->hasAllRoles(['pengurus', 'petugas']))->toBeTrue();
});

it('redirects to the index after creating', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Budi',
            'email' => 'budi@kopekoma.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])
        ->call('create')
        ->assertRedirect(UserResource::getUrl('index'));
});

it('requires name, email, and password on create', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => null,
            'email' => null,
            'password' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'email' => 'required',
            'password' => 'required',
        ]);
});

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'dupe@kopekoma.test']);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Lain',
            'email' => 'dupe@kopekoma.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique']);
});

it('keeps the existing password when left blank on edit', function () {
    $user = User::factory()->create(['password' => Hash::make('original')]);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['name' => 'Nama Baru', 'password' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->name)->toBe('Nama Baru')
        ->and(Hash::check('original', $user->password))->toBeTrue();
});

it('updates the roles of a user', function () {
    $pengurus = Role::firstOrCreate(['name' => 'pengurus', 'guard_name' => 'web']);
    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['roles' => [$pengurus->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->hasRole('pengurus'))->toBeTrue();
});

it('locks deactivated users out of the panel', function () {
    $active = User::factory()->create(['is_active' => true]);
    $inactive = User::factory()->create(['is_active' => false]);

    $panel = Filament::getPanel('admin');

    expect($active->canAccessPanel($panel))->toBeTrue()
        ->and($inactive->canAccessPanel($panel))->toBeFalse();
});

it('hides the delete action for the current user (anti self-lockout)', function () {
    $self = auth()->user();
    $other = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $self->getKey()])
        ->assertActionHidden('delete');

    Livewire::test(EditUser::class, ['record' => $other->getKey()])
        ->assertActionVisible('delete');
});

it('forbids petugas from managing users', function () {
    asPetugas();

    expect(auth()->user()->can('viewAny', User::class))->toBeFalse()
        ->and(auth()->user()->can('create', User::class))->toBeFalse();
});

it('renders the view page with infolist', function () {
    $user = User::factory()->create(['name' => 'Sri Bendahara', 'email' => 'sri@kopekoma.test']);

    Livewire::test(ViewUser::class, ['record' => $user->getKey()])
        ->assertOk()
        ->assertSee('Sri Bendahara')
        ->assertSee('sri@kopekoma.test');
});

it('registers the audit trail relation manager', function () {
    expect(UserResource::getRelations())
        ->toContain(AuditTrailRelationManager::class);
});

it('writes an activity log on update without leaking the password', function () {
    $user = User::factory()->create();

    $user->update(['name' => 'Nama Diubah', 'password' => Hash::make('newpass')]);

    $activity = Activity::where('subject_type', User::class)
        ->where('subject_id', $user->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->changes()->toArray())->not->toHaveKey('password')
        ->and(json_encode($activity->changes()))->not->toContain('password');
});
