<?php

use App\Models\StoreClient;
use Database\Factories\StoreClientFactory;
use Laravel\Sanctum\PersonalAccessToken;

it('issues a bearer token for valid active client credentials', function () {
    $client = StoreClient::factory()->create(['client_id' => 'store_acme']);

    $response = $this->postJson('/api/v1/store/token', [
        'client_id' => 'store_acme',
        'client_secret' => StoreClientFactory::DEFAULT_SECRET,
    ]);

    $response->assertOk()
        ->assertJsonPath('response_code', 200)
        ->assertJsonStructure(['response_code', 'response_message', 'response_data' => ['access_token', 'token_type', 'expires_in']])
        ->assertJsonPath('response_data.token_type', 'Bearer');

    $token = PersonalAccessToken::query()->firstOrFail();
    expect($token->tokenable->is($client))->toBeTrue()
        ->and($token->can('shopping:charge'))->toBeTrue()
        ->and($token->expires_at)->not->toBeNull();
});

it('rejects wrong secret with 401 and issues no token', function () {
    StoreClient::factory()->create(['client_id' => 'store_acme']);

    $this->postJson('/api/v1/store/token', [
        'client_id' => 'store_acme',
        'client_secret' => 'wrong-secret',
    ])->assertStatus(401);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('rejects inactive client with 401', function () {
    StoreClient::factory()->inactive()->create(['client_id' => 'store_dead']);

    $this->postJson('/api/v1/store/token', [
        'client_id' => 'store_dead',
        'client_secret' => StoreClientFactory::DEFAULT_SECRET,
    ])->assertStatus(401);
});

it('rejects unknown client_id with 401 (no enumeration signal)', function () {
    $this->postJson('/api/v1/store/token', [
        'client_id' => 'store_ghost',
        'client_secret' => StoreClientFactory::DEFAULT_SECRET,
    ])->assertStatus(401);
});

it('validates required credentials', function () {
    $this->postJson('/api/v1/store/token', [])
        ->assertStatus(422)
        ->assertJsonPath('response_code', 422)
        ->assertJsonStructure(['response_code', 'response_message']);
});

it('throttles brute-force attempts on the token endpoint', function () {
    StoreClient::factory()->create(['client_id' => 'store_acme']);

    $limit = (int) config('store.rate_limit.token_per_minute');

    for ($i = 0; $i < $limit; $i++) {
        $this->postJson('/api/v1/store/token', [
            'client_id' => 'store_acme',
            'client_secret' => 'wrong',
        ])->assertStatus(401);
    }

    // Permintaan ke-(limit+1) dalam jendela yang sama harus diblok 429.
    $this->postJson('/api/v1/store/token', [
        'client_id' => 'store_acme',
        'client_secret' => 'wrong',
    ])->assertStatus(429);
});
