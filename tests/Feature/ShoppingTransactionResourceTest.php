<?php

use App\Filament\Resources\ShoppingTransactionResource;
use App\Filament\Resources\ShoppingTransactionResource\Pages\CreateShoppingTransaction;
use App\Filament\Resources\ShoppingTransactionResource\Pages\ListShoppingTransactions;
use App\Filament\Resources\ShoppingTransactionResource\Pages\ViewShoppingTransaction;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\ShoppingTransaction;
use App\Services\SavingsBalanceService;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Anggota dengan saldo Wajib Belanja (lewat setoran wajib_belanja nyata).
 */
function memberWithShoppingBalance(string $amount): Member
{
    $member = Member::factory()->create();
    SavingsDeposit::factory()->type('wajib_belanja')->create([
        'member_id' => $member->id,
        'amount' => $amount,
    ]);

    return $member;
}

it('lists shopping transactions on the index page', function () {
    asSuperAdmin();
    $rows = ShoppingTransaction::factory()->count(3)->create();

    Livewire::test(ListShoppingTransactions::class)
        ->assertCanSeeTableRecords($rows);
});

it('records a usage, forces recorded_by and source, and reduces the shopping balance', function () {
    $actor = asSuperAdmin();
    $member = memberWithShoppingBalance('100000');

    Livewire::test(CreateShoppingTransaction::class)
        ->fillForm([
            'member_id' => $member->id,
            'amount' => '40000',
            'transaction_date' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tx = ShoppingTransaction::where('member_id', $member->id)->first();

    expect($tx)->not->toBeNull()
        ->and($tx->recorded_by)->toBe($actor->id)
        ->and($tx->source)->toBe('manual')
        ->and($tx->transaction_number)->toStartWith('BLJ-')
        ->and(app(SavingsBalanceService::class)->shoppingBalance($member))->toBe('60000.00');
});

it('rejects a usage that exceeds the shopping balance (D6)', function () {
    asSuperAdmin();
    $member = memberWithShoppingBalance('30000');

    Livewire::test(CreateShoppingTransaction::class)
        ->fillForm([
            'member_id' => $member->id,
            'amount' => '50000',
            'transaction_date' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasFormErrors(['amount']);

    expect(ShoppingTransaction::where('member_id', $member->id)->exists())->toBeFalse();
});

it('dedupes a double-submit with the same idempotency key (D4)', function () {
    asSuperAdmin();
    $member = memberWithShoppingBalance('100000');
    $key = (string) Str::uuid();

    $payload = [
        'idempotency_key' => $key,
        'member_id' => $member->id,
        'amount' => '40000',
        'transaction_date' => now()->toDateString(),
    ];

    Livewire::test(CreateShoppingTransaction::class)->fillForm($payload)->call('create')->assertHasNoFormErrors();
    Livewire::test(CreateShoppingTransaction::class)->fillForm($payload)->call('create')->assertNotified('Pemakaian sudah tercatat');

    expect(ShoppingTransaction::where('idempotency_key', $key)->count())->toBe(1);
});

it('reverses a usage from the table action, restoring the shopping balance', function () {
    asSuperAdmin();
    $member = memberWithShoppingBalance('100000');
    $tx = ShoppingTransaction::factory()->create(['member_id' => $member->id, 'amount' => '40000']);

    expect(app(SavingsBalanceService::class)->shoppingBalance($member))->toBe('60000.00');

    Livewire::test(ListShoppingTransactions::class)
        ->callTableAction('reverse', $tx, data: ['reason' => 'koreksi salah input']);

    expect(app(SavingsBalanceService::class)->shoppingBalance($member))->toBe('100000.00')
        ->and(ShoppingTransaction::where('reversal_of_id', $tx->id)->exists())->toBeTrue();
});

it('hides the reversal action for a row that is itself a reversal', function () {
    asSuperAdmin();
    $original = ShoppingTransaction::factory()->create();
    $reversal = ShoppingTransaction::factory()->create([
        'is_reversal' => true,
        'reversal_of_id' => $original->id,
    ]);

    expect(ShoppingTransactionResource::canReverse($reversal))->toBeFalse()
        ->and(ShoppingTransactionResource::canReverse($original))->toBeTrue();
});

it('writes an activity log when a usage is created', function () {
    asSuperAdmin();
    $tx = ShoppingTransaction::factory()->create();

    expect(
        Activity::where('subject_type', ShoppingTransaction::class)
            ->where('subject_id', $tx->getKey())
            ->where('event', 'created')
            ->exists()
    )->toBeTrue();
});

it('does not expose an edit page (usage is immutable, D6)', function () {
    expect(array_keys(ShoppingTransactionResource::getPages()))
        ->toBe(['index', 'create', 'view']);
});

it('renders the view page with infolist', function () {
    asSuperAdmin();
    $tx = ShoppingTransaction::factory()->create(['amount' => 33000]);

    Livewire::test(ViewShoppingTransaction::class, ['record' => $tx->getKey()])
        ->assertOk()
        ->assertSee($tx->transaction_number);
});

// ── RBAC (D7) ─────────────────────────────────────────────────────────

it('grants create + reverse to petugas (D7)', function () {
    $petugas = asPetugas();

    expect($petugas->can('create_shopping::transaction'))->toBeTrue()
        ->and($petugas->can('reverse_shopping::transaction'))->toBeTrue();
});
