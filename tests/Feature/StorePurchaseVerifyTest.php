<?php

use App\Http\Resources\VerifyResource;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\ShoppingTransaction;

it('returns balance only when amount is omitted', function () {
    activeMemberWithBalance('3201234567890001', 100_000);

    $response = $this->withToken(storeToken())->postJson('/api/v1/store/purchases/verify', [
        'nik' => '3201234567890001',
    ]);

    $response->assertOk()
        ->assertExactJson([
            'response_code' => 200,
            'response_message' => 'Pengecekan saldo berhasil.',
            'response_data' => ['balance' => '100000.00'],
        ]);
});

it('returns balance and affordable:true when amount fits', function () {
    activeMemberWithBalance('3201234567890009', 100_000);

    $this->withToken(storeToken())->postJson('/api/v1/store/purchases/verify', [
        'nik' => '3201234567890009',
        'amount' => 50_000,
    ])->assertOk()
        ->assertJsonPath('response_data.balance', '100000.00')
        ->assertJsonPath('response_data.affordable', true);
});

it('returns affordable:false when balance is insufficient', function () {
    activeMemberWithBalance('3201234567890002', 30_000);

    $this->withToken(storeToken())->postJson('/api/v1/store/purchases/verify', [
        'nik' => '3201234567890002',
        'amount' => 50_000,
    ])->assertOk()
        ->assertJsonPath('response_data.balance', '30000.00')
        ->assertJsonPath('response_data.affordable', false);
});

it('writes no transaction (read-only)', function () {
    activeMemberWithBalance('3201234567890003', 100_000);

    $this->withToken(storeToken())->postJson('/api/v1/store/purchases/verify', [
        'nik' => '3201234567890003',
        'amount' => 50_000,
    ])->assertOk();

    expect(ShoppingTransaction::query()->count())->toBe(0);
});

it('returns generic 404 for unknown NIK', function () {
    $this->withToken(storeToken())->postJson('/api/v1/store/purchases/verify', [
        'nik' => '9999999999999999',
        'amount' => 50_000,
    ])->assertStatus(404)->assertJsonPath('response_code', 404)->assertJsonMissingPath('response_data');
});

it('returns generic 404 for an inactive member (same shape as not found)', function () {
    $member = Member::factory()->create(['nik' => '3201234567890004', 'status' => 'Non-Aktif']);
    SavingsDeposit::factory()->type('wajib_belanja')->create(['member_id' => $member->id, 'amount' => 100_000]);

    $this->withToken(storeToken())->postJson('/api/v1/store/purchases/verify', [
        'nik' => '3201234567890004',
        'amount' => 50_000,
    ])->assertStatus(404)->assertJsonPath('response_code', 404);
});

it('rejects request without a token (401)', function () {
    $this->postJson('/api/v1/store/purchases/verify', [
        'nik' => '3201234567890001',
        'amount' => 50_000,
    ])->assertStatus(401)
        ->assertJsonPath('response_code', 401)
        ->assertJsonStructure(['response_code', 'response_message']);
});

it('rejects a token lacking shopping:charge ability (403)', function () {
    activeMemberWithBalance('3201234567890005', 100_000);

    $this->withToken(storeToken(abilities: []))->postJson('/api/v1/store/purchases/verify', [
        'nik' => '3201234567890005',
        'amount' => 50_000,
    ])->assertStatus(403);
});

it('validates nik and amount', function () {
    $this->withToken(storeToken())->postJson('/api/v1/store/purchases/verify', [
        'nik' => '123',
        'amount' => 0,
    ])->assertStatus(422)->assertJsonPath('response_code', 422)->assertJsonStructure(['response_code', 'response_message']);
});

it('VerifyResource whitelists balance (+affordable) and drops identity PII', function () {
    $withAmount = (new VerifyResource([
        'balance' => '100000.00',
        'affordable' => true,
        'nik' => '3201234567890001',
        'name' => 'Budi',
        'member_number' => 'KM-1',
    ]))->toArray(request());

    expect($withAmount)->toBe(['balance' => '100000.00', 'affordable' => true]);

    // Tanpa amount (affordable null) → hanya balance.
    $balanceOnly = (new VerifyResource([
        'balance' => '100000.00',
        'affordable' => null,
    ]))->toArray(request());

    expect($balanceOnly)->toBe(['balance' => '100000.00']);
});
