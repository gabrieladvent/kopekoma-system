<?php

use App\Livewire\Settings\StoreClients;
use App\Models\StoreClient;
use App\Models\User;
use Livewire\Livewire;

it('creates a store client and shows the credential once', function () {
    asSuperAdmin();

    Livewire::test(StoreClients::class)
        ->set('newName', 'Toko Sejahtera')
        ->call('createClient')
        ->assertHasNoErrors()
        ->assertSet('showCredential', true)
        ->assertSet('credIsNew', true);

    $client = StoreClient::firstWhere('name', 'Toko Sejahtera');
    expect($client)->not->toBeNull()
        ->and($client->client_id)->toStartWith('store_')
        ->and($client->is_active)->toBeTrue();
});

it('requires a name when creating', function () {
    asSuperAdmin();

    Livewire::test(StoreClients::class)
        ->set('newName', '')
        ->call('createClient')
        ->assertHasErrors(['newName' => 'required']);
});

it('regenerates the secret', function () {
    asSuperAdmin();
    $client = StoreClient::factory()->create();
    $oldHash = $client->getAttributes()['client_secret'];

    Livewire::test(StoreClients::class)
        ->call('regenerate', $client->id)
        ->assertSet('showCredential', true);

    expect($client->fresh()->getAttributes()['client_secret'])->not->toBe($oldHash);
});

it('toggles active and refund flags', function () {
    asSuperAdmin();
    $client = StoreClient::factory()->create(['is_active' => true, 'can_refund' => false]);

    Livewire::test(StoreClients::class)
        ->call('toggleActive', $client->id)
        ->call('toggleRefund', $client->id);

    expect($client->fresh()->is_active)->toBeFalse()
        ->and($client->fresh()->can_refund)->toBeTrue();
});

it('deletes a store client', function () {
    asSuperAdmin();
    $client = StoreClient::factory()->create();

    Livewire::test(StoreClients::class)->call('deleteClient', $client->id);

    expect(StoreClient::find($client->id))->toBeNull();
});

it('hides copy credential from users without the permission', function () {
    $this->actingAs(User::factory()->create()); // tanpa permission
    StoreClient::factory()->create(['name' => 'Toko A']);

    Livewire::test(StoreClients::class)
        ->assertSet('canCopy', false)
        ->assertDontSeeHtml('openReveal');
});

it('forbids opening reveal without the permission', function () {
    $this->actingAs(User::factory()->create());
    $client = StoreClient::factory()->create();

    Livewire::test(StoreClients::class)
        ->call('openReveal', $client->id)
        ->assertStatus(403);
});

it('reveals the secret only after correct password (super admin)', function () {
    asSuperAdmin();
    $client = StoreClient::factory()->create();
    $client->update(['client_secret_encrypted' => 'rahasia-asli-123']);

    // Password salah → gagal validasi, tidak menampilkan kredensial.
    Livewire::test(StoreClients::class)
        ->call('openReveal', $client->id)
        ->assertSet('showReveal', true)
        ->set('revealPassword', 'salah')
        ->call('confirmReveal')
        ->assertHasErrors('revealPassword')
        ->assertSet('showCredential', false);

    // Password benar → kredensial ditampilkan.
    Livewire::test(StoreClients::class)
        ->call('openReveal', $client->id)
        ->set('revealPassword', 'password')
        ->call('confirmReveal')
        ->assertHasNoErrors()
        ->assertSet('showCredential', true)
        ->assertSet('credSecret', 'rahasia-asli-123');
});
