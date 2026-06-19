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
