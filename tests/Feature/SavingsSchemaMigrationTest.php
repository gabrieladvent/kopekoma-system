<?php

use App\Models\Member;
use App\Models\ShoppingTransaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Item 0 (ADR Modul Simpanan) — migrasi aditif.
 * Memverifikasi kolom workflow/idempotency baru ada + guard single-reversal
 * (unique reversal_of_id) menolak reversal kedua atas transaksi asli yang sama.
 */
it('adds the withdrawal workflow columns with draft default', function () {
    expect(Schema::hasColumns('savings_withdrawals', [
        'status', 'approved_by', 'approved_at', 'disbursed_at', 'period_year',
    ]))->toBeTrue();

    $member = Member::factory()->create();
    $id = (string) Str::uuid();

    DB::table('savings_withdrawals')->insert([
        'id' => $id,
        'withdrawal_number' => 'TRK-2026-000001',
        'idempotency_key' => (string) Str::uuid(),
        'member_id' => $member->id,
        'savings_type' => 'sukarela',
        'amount' => 10000,
        'withdrawal_date' => now()->toDateString(),
        'recorded_by' => auth()->id() ?? User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('savings_withdrawals')->where('id', $id)->value('status'))->toBe('draft');
});

it('adds idempotency_key and transaction_number to shopping_transactions', function () {
    expect(Schema::hasColumns('shopping_transactions', [
        'idempotency_key', 'transaction_number',
    ]))->toBeTrue();
});

it('rejects a second reversal pointing at the same original transaction', function () {
    $member = Member::factory()->create();

    $original = ShoppingTransaction::create([
        'member_id' => $member->id,
        'amount' => 50000,
        'transaction_date' => now()->toDateString(),
        'source' => 'manual',
    ]);

    $makeReversal = fn () => ShoppingTransaction::create([
        'member_id' => $member->id,
        'amount' => 50000,
        'transaction_date' => now()->toDateString(),
        'source' => 'manual',
        'is_reversal' => true,
        'reversal_of_id' => $original->id,
    ]);

    $makeReversal();

    expect($makeReversal)->toThrow(UniqueConstraintViolationException::class);
});

it('still allows many non-reversal rows (null reversal_of_id) under the unique guard', function () {
    $member = Member::factory()->create();

    collect(range(1, 3))->each(fn () => ShoppingTransaction::create([
        'member_id' => $member->id,
        'amount' => 1000,
        'transaction_date' => now()->toDateString(),
        'source' => 'manual',
    ]));

    expect(ShoppingTransaction::whereNull('reversal_of_id')->count())->toBe(3);
});
