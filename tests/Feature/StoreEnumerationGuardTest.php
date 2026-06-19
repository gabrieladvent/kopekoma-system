<?php

use App\Models\StoreClient;
use App\Services\StoreEnumerationGuard;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

beforeEach(function () {
    config()->set('store.lockout.max_failures', 3);
    config()->set('store.lockout.window_minutes', 5);
    config()->set('store.lockout.cooldown_minutes', 15);
});

it('does not lock before reaching the failure threshold', function () {
    $client = StoreClient::factory()->create();
    $guard = app(StoreEnumerationGuard::class);

    $guard->recordFailure($client);
    $guard->recordFailure($client);

    $guard->assertNotLocked($client); // belum kena ambang (3)
})->throwsNoExceptions();

it('locks the client after N consecutive failures and throws 429', function () {
    $client = StoreClient::factory()->create();
    $guard = app(StoreEnumerationGuard::class);

    $guard->recordFailure($client);
    $guard->recordFailure($client);
    $guard->recordFailure($client); // ke-3 → lockout

    expect(fn () => $guard->assertNotLocked($client))
        ->toThrow(ThrottleRequestsException::class);
});

it('clear() resets the failure counter so lockout is avoided', function () {
    $client = StoreClient::factory()->create();
    $guard = app(StoreEnumerationGuard::class);

    $guard->recordFailure($client);
    $guard->recordFailure($client);
    $guard->clear($client);
    $guard->recordFailure($client);
    $guard->recordFailure($client);

    $guard->assertNotLocked($client); // 2 setelah reset, belum kena ambang
})->throwsNoExceptions();
