<?php

use App\Models\StoreClient;
use Database\Factories\StoreClientFactory;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

it('hashes client_secret and verifies via Hash::check', function () {
    $client = StoreClient::factory()->create();

    expect($client->client_secret)->not->toBe(StoreClientFactory::DEFAULT_SECRET)
        ->and(Hash::check(StoreClientFactory::DEFAULT_SECRET, $client->client_secret))->toBeTrue();
});

it('hides client_secret from array/json serialization', function () {
    $client = StoreClient::factory()->create();

    expect($client->toArray())->not->toHaveKey('client_secret');
});

it('casts is_active to boolean', function () {
    $client = StoreClient::factory()->inactive()->create();

    expect($client->is_active)->toBeFalse();
});

it('issues a Sanctum token scoped to ability with the StoreClient as tokenable', function () {
    $client = StoreClient::factory()->create();

    $token = $client->createToken('store-charge', ['shopping:charge'], now()->addHour());

    expect($token->accessToken->can('shopping:charge'))->toBeTrue()
        ->and($token->accessToken->cant('shopping:refund'))->toBeTrue();

    $stored = PersonalAccessToken::query()->firstOrFail();
    expect($stored->tokenable_type)->toBe(StoreClient::class)
        ->and($stored->tokenable_id)->toBe($client->id)
        ->and($stored->tokenable->is($client))->toBeTrue()
        ->and($stored->expires_at)->not->toBeNull();
});
