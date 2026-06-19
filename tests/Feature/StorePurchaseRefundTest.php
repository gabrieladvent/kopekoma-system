<?php

use App\Models\ShoppingTransaction;
use App\Models\StoreClient;
use App\Services\SavingsBalanceService;
use Database\Factories\StoreClientFactory;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @return array{0: StoreClient, 1: string} klien (can_refund) + token ber-ability refund
 */
function refundClientWithToken(): array
{
    $client = StoreClient::factory()->canRefund()->create();
    $token = $client->createToken('store', ['shopping:charge', 'shopping:refund'])->plainTextToken;

    return [$client, $token];
}

function storeApiCharge(StoreClient $client, string $memberId, int $amount): ShoppingTransaction
{
    return ShoppingTransaction::create([
        'idempotency_key' => (string) Str::uuid(),
        'member_id' => $memberId,
        'amount' => $amount,
        'transaction_date' => now()->toDateString(),
        'source' => 'store_api',
        'store_client_id' => $client->id,
    ]);
}

it('refunds origin store transaction (201), restores balance, reversal keeps store_client_id', function () {
    $member = activeMemberWithBalance('3202000000000001', 100_000);
    [$client, $token] = refundClientWithToken();
    $tx = storeApiCharge($client, $member->id, 40_000);

    expect(app(SavingsBalanceService::class)->shoppingBalance($member->fresh()))->toBe('60000.00');

    $this->withToken($token)
        ->postJson("/api/v1/store/purchases/{$tx->transaction_number}/refund", ['reason' => 'barang dikembalikan'])
        ->assertStatus(201)
        ->assertJsonPath('refunded', true);

    $reversal = ShoppingTransaction::query()->where('reversal_of_id', $tx->id)->firstOrFail();
    expect((string) $reversal->store_client_id)->toBe((string) $client->id)
        ->and(app(SavingsBalanceService::class)->shoppingBalance($member->fresh()))->toBe('100000.00');
});

it('returns 404 when refunding a transaction owned by another store', function () {
    $member = activeMemberWithBalance('3202000000000002', 100_000);
    [$owner] = refundClientWithToken();
    $tx = storeApiCharge($owner, $member->id, 40_000);

    [, $otherToken] = refundClientWithToken(); // klien berbeda

    $this->withToken($otherToken)
        ->postJson("/api/v1/store/purchases/{$tx->transaction_number}/refund", ['reason' => 'coba refund'])
        ->assertStatus(404)->assertJsonPath('code', 'TRANSACTION_NOT_FOUND');
});

it('rejects a charge-only token (no shopping:refund ability) with 403', function () {
    $member = activeMemberWithBalance('3202000000000003', 100_000);
    [$client] = refundClientWithToken();
    $tx = storeApiCharge($client, $member->id, 40_000);

    $chargeOnly = $client->createToken('charge-only', ['shopping:charge'])->plainTextToken;

    $this->withToken($chargeOnly)
        ->postJson("/api/v1/store/purchases/{$tx->transaction_number}/refund", ['reason' => 'barang dikembalikan'])
        ->assertStatus(403);
});

it('is idempotent: a second refund returns 200 with a single reversal row', function () {
    $member = activeMemberWithBalance('3202000000000004', 100_000);
    [$client, $token] = refundClientWithToken();
    $tx = storeApiCharge($client, $member->id, 40_000);

    $this->withToken($token)
        ->postJson("/api/v1/store/purchases/{$tx->transaction_number}/refund", ['reason' => 'barang dikembalikan'])
        ->assertStatus(201);

    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson("/api/v1/store/purchases/{$tx->transaction_number}/refund", ['reason' => 'barang dikembalikan'])
        ->assertStatus(200)->assertJsonPath('refunded', true);

    expect(ShoppingTransaction::query()->where('reversal_of_id', $tx->id)->count())->toBe(1)
        ->and(app(SavingsBalanceService::class)->shoppingBalance($member->fresh()))->toBe('100000.00');
});

it('requires a reason (422)', function () {
    $member = activeMemberWithBalance('3202000000000005', 100_000);
    [$client, $token] = refundClientWithToken();
    $tx = storeApiCharge($client, $member->id, 40_000);

    $this->withToken($token)
        ->postJson("/api/v1/store/purchases/{$tx->transaction_number}/refund", ['reason' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors('reason');
});

it('token endpoint grants shopping:refund only to can_refund clients', function () {
    StoreClient::factory()->canRefund()->create(['client_id' => 'store_ref']);

    $token = $this->postJson('/api/v1/store/token', ['client_id' => 'store_ref', 'client_secret' => StoreClientFactory::DEFAULT_SECRET])
        ->json('access_token');

    // Ability refund ada untuk klien can_refund.
    [$id] = explode('|', $token);
    expect(PersonalAccessToken::find($id)->can('shopping:refund'))->toBeTrue();

    StoreClient::factory()->create(['client_id' => 'store_noref']);
    $token2 = $this->postJson('/api/v1/store/token', ['client_id' => 'store_noref', 'client_secret' => StoreClientFactory::DEFAULT_SECRET])
        ->json('access_token');
    [$id2] = explode('|', $token2);
    expect(PersonalAccessToken::find($id2)->can('shopping:refund'))->toBeFalse();
});
