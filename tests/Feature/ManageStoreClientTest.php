<?php

use App\Filament\Pages\ManageSettings;
use App\Models\StoreClient;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(fn () => asSuperAdmin());

it('creates a store client with generated client_id and hashed secret', function () {
    Livewire::test(ManageSettings::class)
        ->callAction('createStoreClient', data: [
            'name' => 'Toko Maju',
            'can_refund' => true,
        ])
        ->assertHasNoActionErrors();

    $client = StoreClient::query()->firstOrFail();

    expect($client->name)->toBe('Toko Maju')
        ->and($client->client_id)->toStartWith('store_')
        ->and($client->is_active)->toBeTrue()
        ->and($client->can_refund)->toBeTrue()
        // secret tersimpan ter-hash, bukan plaintext
        ->and($client->client_secret)->toStartWith('$2y$');
});

it('defaults can_refund to false', function () {
    Livewire::test(ManageSettings::class)
        ->callAction('createStoreClient', data: ['name' => 'Toko Hemat']);

    expect(StoreClient::query()->firstOrFail()->can_refund)->toBeFalse();
});

it('requires a name', function () {
    Livewire::test(ManageSettings::class)
        ->callAction('createStoreClient', data: ['name' => ''])
        ->assertHasActionErrors(['name']);

    expect(StoreClient::query()->count())->toBe(0);
});

it('regenerates the secret (hash changes) via the row action', function () {
    $client = StoreClient::factory()->create();
    $oldHash = $client->client_secret;

    Livewire::test(ManageSettings::class)
        ->callTableAction('regenerateSecret', $client);

    expect($client->refresh()->client_secret)->not->toBe($oldHash)
        ->and($client->client_secret)->toStartWith('$2y$');
});

it('keeps store client data in the store_clients table, not settings', function () {
    Livewire::test(ManageSettings::class)
        ->callAction('createStoreClient', data: ['name' => 'Toko Cek']);

    // Token Sanctum bisa diterbitkan karena ada baris StoreClient sungguhan.
    $client = StoreClient::query()->firstOrFail();
    $token = $client->createToken('store-charge', ['shopping:charge']);

    expect($token->accessToken->tokenable->is($client))->toBeTrue();
});

// ── Copy Kredensial (gated password admin) ────────────────────────────

it('stores a reversible encrypted copy of the secret on create that matches the hash', function () {
    Livewire::test(ManageSettings::class)
        ->callAction('createStoreClient', data: ['name' => 'Toko Salin']);

    $client = StoreClient::query()->firstOrFail();

    // Salinan terenkripsi ada & ter-decrypt ke plaintext yang cocok dengan hash auth.
    expect($client->client_secret_encrypted)->not->toBeNull()
        ->and(Hash::check($client->client_secret_encrypted, $client->client_secret))->toBeTrue();
});

it('refreshes the encrypted copy when the secret is regenerated', function () {
    $client = StoreClient::factory()->create();

    Livewire::test(ManageSettings::class)
        ->callTableAction('regenerateSecret', $client);

    $client->refresh();

    expect($client->client_secret_encrypted)->not->toBeNull()
        ->and(Hash::check($client->client_secret_encrypted, $client->client_secret))->toBeTrue();
});

it('reveals and copies the credential to a super admin who confirms their password', function () {
    Livewire::test(ManageSettings::class)
        ->callAction('createStoreClient', data: ['name' => 'Toko Salin']);
    $client = StoreClient::query()->firstOrFail();

    Livewire::test(ManageSettings::class)
        ->callTableAction('copyCredential', $client, data: ['password' => 'password'])
        ->assertHasNoTableActionErrors()
        ->assertDispatched('copy-credential')
        ->assertNotified('Kredensial disalin');
});

it('rejects the copy when the password is wrong, revealing nothing', function () {
    Livewire::test(ManageSettings::class)
        ->callAction('createStoreClient', data: ['name' => 'Toko Salin']);
    $client = StoreClient::query()->firstOrFail();

    Livewire::test(ManageSettings::class)
        ->callTableAction('copyCredential', $client, data: ['password' => 'salah-password'])
        ->assertHasTableActionErrors(['password'])
        ->assertNotDispatched('copy-credential');
});

it('hides the copy action for a client without an encrypted secret (legacy, needs reset first)', function () {
    // Klien lama: hanya hash, belum ada salinan terenkripsi.
    $client = StoreClient::factory()->create(['client_secret_encrypted' => null]);

    Livewire::test(ManageSettings::class)
        ->assertTableActionHidden('copyCredential', $client);
});

it('grants the copy-credential permission to super admin only, not petugas or pengurus', function () {
    expect(asPetugas()->can(ManageSettings::COPY_SECRET_PERMISSION))->toBeFalse()
        ->and(asPengurus()->can(ManageSettings::COPY_SECRET_PERMISSION))->toBeFalse();
});
