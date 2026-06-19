<?php

use App\Actions\RecordShoppingUsage;
use App\Exceptions\CannotSpendShopping;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\ShoppingTransaction;
use App\Services\SavingsBalanceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Member dengan saldo Wajib Belanja terisi sebesar $balance.
 */
function recordUsageTestMember(int $balance): Member
{
    $member = Member::factory()->create();
    SavingsDeposit::factory()->type('wajib_belanja')->create([
        'member_id' => $member->id,
        'amount' => $balance,
    ]);

    return $member;
}

function recordUsagePayload(Member $member, int $amount): array
{
    return [
        'idempotency_key' => (string) Str::uuid(),
        'member_id' => $member->id,
        'amount' => $amount,
        'transaction_date' => now()->toDateString(),
        'source' => 'manual',
    ];
}

it('records usage and reduces shopping balance', function () {
    $member = recordUsageTestMember(100_000);

    $tx = app(RecordShoppingUsage::class)(recordUsagePayload($member, 30_000));

    expect($tx)->toBeInstanceOf(ShoppingTransaction::class)
        ->and($tx->source)->toBe('manual')
        ->and(app(SavingsBalanceService::class)->shoppingBalance($member->fresh()))->toBe('70000.00');
});

it('rejects usage exceeding balance with CannotSpendShopping', function () {
    $member = recordUsageTestMember(50_000);

    app(RecordShoppingUsage::class)(recordUsagePayload($member, 60_000));
})->throws(CannotSpendShopping::class);

it('rejects non-positive amount', function () {
    $member = recordUsageTestMember(50_000);

    app(RecordShoppingUsage::class)(recordUsagePayload($member, 0));
})->throws(CannotSpendShopping::class);

it('re-checks authoritative balance: second usage that overspends remainder is rejected', function () {
    $member = recordUsageTestMember(100_000);

    app(RecordShoppingUsage::class)(recordUsagePayload($member, 80_000));

    // sisa 20rb — pemakaian 30rb harus ditolak oleh re-cek di dalam lock
    expect(fn () => app(RecordShoppingUsage::class)(recordUsagePayload($member, 30_000)))
        ->toThrow(CannotSpendShopping::class);

    expect(ShoppingTransaction::query()->where('member_id', $member->id)->count())->toBe(1);
});

it('lets idempotency key collision propagate as UniqueConstraintViolationException', function () {
    $member = recordUsageTestMember(100_000);
    $payload = recordUsagePayload($member, 10_000);

    app(RecordShoppingUsage::class)($payload);

    // key sama → backstop unique(idempotency_key) melempar, dibiarkan menjalar ke pemanggil
    expect(fn () => app(RecordShoppingUsage::class)($payload))
        ->toThrow(UniqueConstraintViolationException::class);
});
