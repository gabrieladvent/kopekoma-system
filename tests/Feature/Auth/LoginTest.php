<?php

use App\Livewire\Auth\Login;
use App\Models\User;
use Livewire\Livewire;

it('renders the login page', function () {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSeeLivewire(Login::class);
});

it('authenticates with valid credentials and redirects to dashboard', function () {
    $user = User::factory()->create([
        'email' => 'pengurus@koperasi.id',
        'password' => 'password',
    ]);

    Livewire::test(Login::class)
        ->set('email', 'pengurus@koperasi.id')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials', function () {
    User::factory()->create([
        'email' => 'pengurus@koperasi.id',
        'password' => 'password',
    ]);

    Livewire::test(Login::class)
        ->set('email', 'pengurus@koperasi.id')
        ->set('password', 'salah')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('validates required fields', function () {
    Livewire::test(Login::class)
        ->set('email', '')
        ->set('password', '')
        ->call('login')
        ->assertHasErrors(['email' => 'required', 'password' => 'required']);
});

it('throttles login after 5 failed attempts', function () {
    User::factory()->create([
        'email' => 'pengurus@koperasi.id',
        'password' => 'password',
    ]);

    $component = Livewire::test(Login::class)
        ->set('email', 'pengurus@koperasi.id')
        ->set('password', 'salah');

    foreach (range(1, 5) as $i) {
        $component->call('login');
    }

    // Percobaan ke-6 walau dengan sandi BENAR tetap diblokir oleh throttle.
    $component->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('redirects authenticated user away from login (guest middleware)', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect();
});

it('logs the user out', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
