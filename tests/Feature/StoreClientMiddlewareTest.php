<?php

use App\Http\Middleware\EnsureActiveStoreClient;
use App\Models\StoreClient;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    Route::middleware(['auth:sanctum', 'store.client'])
        ->get('/_test/store-guard', fn () => response()->json(['ok' => true]));
});

it('allows an active StoreClient token through the guard', function () {
    $client = StoreClient::factory()->create();
    $token = $client->createToken('store-charge', ['shopping:charge'])->plainTextToken;

    $this->withToken($token)->getJson('/_test/store-guard')
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('does not authenticate a User-tokenable token on store routes (User lacks HasApiTokens → 401)', function () {
    $user = User::factory()->create();

    // User pakai session Filament, bukan token API. Walau PAT dibuat manual,
    // Sanctum menolak di lapisan auth karena User tak pakai HasApiTokens.
    $plain = Str::random(40);
    $pat = new PersonalAccessToken;
    $pat->forceFill([
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
        'name' => 'web',
        'token' => hash('sha256', $plain),
        'abilities' => ['*'],
    ])->save();

    $this->withToken($pat->id.'|'.$plain)->getJson('/_test/store-guard')->assertStatus(401);
});

it('guard 403 response uses the envelope shape', function () {
    $client = StoreClient::factory()->create();
    $token = $client->createToken('store-charge', ['shopping:charge'])->plainTextToken;
    $client->update(['is_active' => false]);

    $this->withToken($token)->getJson('/_test/store-guard')
        ->assertStatus(403)
        ->assertJsonPath('response_code', 403)
        ->assertJsonStructure(['response_code', 'response_message']);
});

it('guard returns 403 for an authenticated non-StoreClient tokenable', function () {
    // Defense-in-depth: bila kelak ada model lain ber-HasApiTokens, guard tetap
    // menolak tokenable selain StoreClient.
    $request = Request::create('/_test/store-guard', 'GET');
    $request->setUserResolver(fn () => User::factory()->create());

    $response = (new EnsureActiveStoreClient)->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
});

it('rejects a token belonging to a deactivated StoreClient', function () {
    $client = StoreClient::factory()->create();
    $token = $client->createToken('store-charge', ['shopping:charge'])->plainTextToken;

    $client->update(['is_active' => false]);

    $this->withToken($token)->getJson('/_test/store-guard')->assertStatus(403);
});
