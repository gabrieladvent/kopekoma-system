<?php

use App\Livewire\Profile\EditProfile;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('renders the profile page for an authenticated user', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertSuccessful()
        ->assertSeeLivewire(EditProfile::class);
});

it('redirects guests away from the profile page', function () {
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
});

it('updates the name without touching the email', function () {
    $user = User::factory()->create(['name' => 'Lama']);

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('name', 'Baru')
        ->call('saveAccount')
        ->assertHasNoErrors();

    expect($user->fresh()->name)->toBe('Baru');
});

it('requires the current password to change the email', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('email', 'new@example.com')
        ->set('account_current_password', 'wrong-password')
        ->call('saveAccount')
        ->assertHasErrors(['account_current_password']);

    expect($user->fresh()->email)->toBe('old@example.com');
});

it('changes the email, resets verification, and sends a fresh link', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'old@example.com',
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('email', 'new@example.com')
        ->set('account_current_password', 'password')
        ->call('saveAccount')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->email)->toBe('new@example.com')
        ->and($user->email_verified_at)->toBeNull();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('rejects an email already taken by another user', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('email', 'taken@example.com')
        ->set('account_current_password', 'password')
        ->call('saveAccount')
        ->assertHasErrors(['email']);
});

it('changes the password with the correct current password', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('current_password', 'password')
        ->set('password', 'new-secret-password')
        ->set('password_confirmation', 'new-secret-password')
        ->call('savePassword')
        ->assertHasNoErrors();

    expect(Hash::check('new-secret-password', $user->fresh()->password))->toBeTrue();
});

it('rejects a password change with the wrong current password', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('current_password', 'wrong')
        ->set('password', 'new-secret-password')
        ->set('password_confirmation', 'new-secret-password')
        ->call('savePassword')
        ->assertHasErrors(['current_password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('uploads a profile photo and stores it on the public disk', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('photo', UploadedFile::fake()->image('avatar.png'))
        ->call('savePhoto')
        ->assertHasNoErrors();

    $path = $user->fresh()->avatar_path;
    expect($path)->not->toBeNull();
    Storage::disk('public')->assertExists($path);
});

it('deletes the old photo when a new one is uploaded', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('photo', UploadedFile::fake()->image('first.png'))
        ->call('savePhoto');

    $first = $user->fresh()->avatar_path;

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('photo', UploadedFile::fake()->image('second.png'))
        ->call('savePhoto');

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($user->fresh()->avatar_path);
});

it('removes the profile photo and deletes the file', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('photo', UploadedFile::fake()->image('avatar.png'))
        ->call('savePhoto');

    $path = $user->fresh()->avatar_path;

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->call('removePhoto')
        ->assertHasNoErrors();

    expect($user->fresh()->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('rejects a non-image photo upload', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->set('photo', UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'))
        ->call('savePhoto')
        ->assertHasErrors(['photo']);
});

it('resends the verification link for an unverified user', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    Livewire::actingAs($user)
        ->test(EditProfile::class)
        ->call('resendVerification');

    Notification::assertSentTo($user, VerifyEmail::class);
});
