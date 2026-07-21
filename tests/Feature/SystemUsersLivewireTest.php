<?php

use App\Livewire\System\UserForm;
use App\Livewire\System\Users;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/** Sisipkan satu baris sesi database milik $user (untuk uji force-logout). */
function seedSessionFor(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);
}

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

it('creates a user with roles, an auto-generated password, and verification', function () {
    asSuperAdmin();
    Role::firstOrCreate(['name' => 'pengurus', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'petugas', 'guard_name' => 'web']);

    // Create tidak lagi meminta password — digenerate otomatis lalu ditampilkan
    // sekali lewat modal (showCredentials), redirect terjadi via finishCreate().
    $component = Livewire::test(UserForm::class)
        ->set('name', 'Sri Bendahara')
        ->set('email', 'sri@kopekoma.test')
        ->set('selectedRoles', ['pengurus', 'petugas'])
        ->set('email_verified', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showCredentials', true);

    $generated = $component->get('generatedPassword');
    expect($generated)->not->toBeNull()->and(strlen($generated))->toBeGreaterThanOrEqual(12);

    $component->call('finishCreate')->assertRedirect(route('system.users'));

    $user = User::where('email', 'sri@kopekoma.test')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check($generated, $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasAllRoles(['pengurus', 'petugas']))->toBeTrue();
});

it('validates required fields and unique email on create', function () {
    asSuperAdmin();
    User::factory()->create(['email' => 'dupe@kopekoma.test']);

    // Password tidak divalidasi saat create (digenerate otomatis).
    Livewire::test(UserForm::class)
        ->set('name', '')
        ->set('email', '')
        ->call('save')
        ->assertHasErrors(['name', 'email']);

    Livewire::test(UserForm::class)
        ->set('name', 'Lain')
        ->set('email', 'dupe@kopekoma.test')
        ->call('save')
        ->assertHasErrors(['email']);
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

it('ends all sessions of a user when they are deactivated', function () {
    config(['session.driver' => 'database']);
    asSuperAdmin();

    $user = User::factory()->create(['is_active' => true]);
    seedSessionFor($user, 'sess-deactivate');

    Livewire::test(Users::class)
        ->call('toggleActive', $user->id)
        ->assertHasNoErrors();

    expect($user->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('force-resets a password: generates a new one, ends sessions, and reveals it once', function () {
    config(['session.driver' => 'database']);
    asSuperAdmin();

    $user = User::factory()->create(['password' => Hash::make('original')]);
    seedSessionFor($user, 'sess-reset');

    $component = Livewire::test(Users::class)
        ->call('resetPassword', $user->id)
        ->assertHasNoErrors()
        ->assertSet('showResetPassword', true);

    $new = $component->get('resetPasswordValue');

    expect($new)->not->toBeNull()
        ->and(Hash::check($new, $user->fresh()->password))->toBeTrue()
        ->and(Hash::check('original', $user->fresh()->password))->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('refuses to reset your own password from the user list', function () {
    $self = asSuperAdmin();
    $self->forceFill(['password' => Hash::make('original')])->save();

    Livewire::test(Users::class)
        ->call('resetPassword', $self->id)
        ->assertSet('showResetPassword', false);

    expect(Hash::check('original', $self->fresh()->password))->toBeTrue();
});
