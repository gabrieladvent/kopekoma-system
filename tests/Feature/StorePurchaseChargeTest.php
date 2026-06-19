<?php

use App\Models\ShoppingTransaction;
use App\Services\SavingsBalanceService;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

it('charges, returns 201 with only {transaction_number, charged}, and reduces balance', function () {
    $member = activeMemberWithBalance('3201000000000001', 100_000);
    [$client, $token] = clientWithToken();

    $response = $this->withToken($token)->postJson('/api/v1/store/purchases', [
        'nik' => '3201000000000001',
        'amount' => 40_000,
    ], chargeHeaders());

    $response->assertStatus(201)
        ->assertJsonPath('response_code', 201)
        ->assertJsonStructure(['response_code', 'response_message', 'response_data' => ['transaction_number', 'charged']])
        ->assertJsonPath('response_data.charged', true)
        ->assertJsonMissingPath('response_data.new_balance')
        ->assertJsonMissingPath('response_data.nik');

    $tx = ShoppingTransaction::query()->firstOrFail();
    expect($tx->source)->toBe('store_api')
        ->and((string) $tx->store_client_id)->toBe((string) $client->id)
        ->and($tx->recorded_by)->toBeNull()
        ->and(app(SavingsBalanceService::class)->shoppingBalance($member->fresh()))->toBe('60000.00');
});

it('rejects insufficient balance with 422 INSUFFICIENT_BALANCE', function () {
    activeMemberWithBalance('3201000000000002', 30_000);
    [, $token] = clientWithToken();

    $this->withToken($token)->postJson('/api/v1/store/purchases', [
        'nik' => '3201000000000002',
        'amount' => 50_000,
    ], chargeHeaders())->assertStatus(422)->assertJsonPath('response_code', 422)
        ->assertJsonPath('response_message', 'Nominal pemakaian (Rp 50000) melebihi saldo Wajib Belanja (Rp 30000.00).');
});

it('rejects amount above per-transaction plafon with 422 AMOUNT_EXCEEDS_LIMIT', function () {
    config()->set('store.max_charge_per_tx', '2000000');
    activeMemberWithBalance('3201000000000003', 5_000_000);
    [, $token] = clientWithToken();

    $this->withToken($token)->postJson('/api/v1/store/purchases', [
        'nik' => '3201000000000003',
        'amount' => 3_000_000,
    ], chargeHeaders())->assertStatus(422)->assertJsonPath('response_code', 422)
        ->assertJsonPath('response_message', 'Nominal melebihi plafon per transaksi.');
});

it('requires Idempotency-Key header (422)', function () {
    activeMemberWithBalance('3201000000000004', 100_000);
    [, $token] = clientWithToken();

    $this->withToken($token)->postJson('/api/v1/store/purchases', [
        'nik' => '3201000000000004',
        'amount' => 40_000,
    ])->assertStatus(422)->assertJsonPath('response_code', 422)
        ->assertJsonPath('response_message', 'Header Idempotency-Key wajib diisi.');
});

it('is idempotent: same key + same payload returns 200 without double-charging', function () {
    $member = activeMemberWithBalance('3201000000000005', 100_000);
    [, $token] = clientWithToken();
    $headers = chargeHeaders();
    $payload = ['nik' => '3201000000000005', 'amount' => 40_000];

    $this->withToken($token)->postJson('/api/v1/store/purchases', $payload, $headers)->assertStatus(201);
    $this->withToken($token)->postJson('/api/v1/store/purchases', $payload, $headers)->assertStatus(200);

    expect(ShoppingTransaction::query()->count())->toBe(1)
        ->and(app(SavingsBalanceService::class)->shoppingBalance($member->fresh()))->toBe('60000.00');
});

it('returns 409 PAYLOAD_MISMATCH for same key + different payload', function () {
    activeMemberWithBalance('3201000000000006', 100_000);
    [, $token] = clientWithToken();
    $headers = chargeHeaders();

    $this->withToken($token)->postJson('/api/v1/store/purchases', [
        'nik' => '3201000000000006', 'amount' => 40_000,
    ], $headers)->assertStatus(201);

    $this->withToken($token)->postJson('/api/v1/store/purchases', [
        'nik' => '3201000000000006', 'amount' => 55_000,
    ], $headers)->assertStatus(409)->assertJsonPath('response_code', 409)
        ->assertJsonPath('response_message', 'Idempotency-Key dipakai ulang dengan payload berbeda.');
});

it('returns generic 409 when another client reuses the same key (no cross-merchant leak)', function () {
    activeMemberWithBalance('3201000000000007', 100_000);
    [, $tokenA] = clientWithToken();
    [, $tokenB] = clientWithToken();
    $key = (string) Str::uuid();

    $this->withToken($tokenA)->postJson('/api/v1/store/purchases', [
        'nik' => '3201000000000007', 'amount' => 40_000,
    ], ['Idempotency-Key' => $key])->assertStatus(201);

    // Isolasi guard antar-request (di produksi tiap request fresh; di test
    // satu app instance memoize user Sanctum).
    $this->app['auth']->forgetGuards();

    // Klien B pakai key sama → 409 generik, tak bocorkan transaksi klien A.
    $this->withToken($tokenB)->postJson('/api/v1/store/purchases', [
        'nik' => '3201000000000007', 'amount' => 40_000,
    ], ['Idempotency-Key' => $key])
        ->assertStatus(409)
        ->assertJsonPath('response_code', 409)
        ->assertJsonPath('response_message', 'Idempotency-Key sudah dipakai.')
        ->assertJsonMissingPath('response_data');
});

it('logs store_charge activity with store_client_id, no NIK, null causer', function () {
    $member = activeMemberWithBalance('3201000000000008', 100_000);
    [$client, $token] = clientWithToken();

    $this->withToken($token)->postJson('/api/v1/store/purchases', [
        'nik' => '3201000000000008', 'amount' => 40_000,
    ], chargeHeaders())->assertStatus(201);

    $activity = Activity::query()->where('event', 'store_charge')->firstOrFail();
    $props = $activity->properties->toArray();

    expect($activity->causer_id)->toBeNull()
        ->and($props)->toHaveKey('store_client_id', $client->id)
        ->and($props)->toHaveKey('member_id', $member->id)
        ->and(json_encode($props))->not->toContain('3201000000000008'); // NIK tak ikut tercatat

    // Tak ada activity (termasuk auto-log "created" model) yang memasang causer
    // di jalur API — causer_id bigint tak bisa menampung UUID StoreClient (D6).
    expect(Activity::query()->whereNotNull('causer_id')->count())->toBe(0);
});
