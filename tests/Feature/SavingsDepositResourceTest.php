<?php

use App\Actions\ReverseTransaction;
use App\Filament\Resources\RelationManagers\AuditTrailRelationManager;
use App\Filament\Resources\SavingsDepositResource;
use App\Filament\Resources\SavingsDepositResource\Pages\CreateSavingsDeposit;
use App\Filament\Resources\SavingsDepositResource\Pages\ListSavingsDeposits;
use App\Filament\Resources\SavingsDepositResource\Pages\ViewSavingsDeposit;
use App\Models\Member;
use App\Models\MemberHolidaySaving;
use App\Models\SavingsDeposit;
use App\Models\User;
use App\Services\SavingsBalanceService;
use App\Settings\CooperativeSettings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    asSuperAdmin();
});

it('lists deposits on the index page', function () {
    $deposits = SavingsDeposit::factory()->count(3)->create();

    Livewire::test(ListSavingsDeposits::class)
        ->assertCanSeeTableRecords($deposits);
});

it('creates a single deposit and forces recorded_by to the actor', function () {
    $actor = asSuperAdmin();
    $member = Member::factory()->create();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'savings_type' => 'sukarela', // editable amount
            'amount' => '150000',
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deposit = SavingsDeposit::where('member_id', $member->id)->first();

    expect($deposit)->not->toBeNull()
        ->and($deposit->recorded_by)->toBe($actor->id)
        ->and($deposit->transaction_number)->toStartWith('STR-')
        ->and((float) $deposit->amount)->toBe(150000.0);
});

it('requires member, savings type, and amount', function () {
    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => null,
            'savings_type' => null,
            'amount' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'member_id' => 'required',
            'savings_type' => 'required',
            'amount' => 'required',
        ]);
});

it('requires period_month for hari_raya deposits', function () {
    $member = Member::factory()->create();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'savings_type' => 'hari_raya',
            'amount' => '50000',
            'deposit_date' => now()->toDateString(),
            'period_month' => null,
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
        ])
        ->call('create')
        ->assertHasFormErrors(['period_month' => 'required']);
});

it('dedupes a double-submit with the same idempotency key and identical payload (D4)', function () {
    $member = Member::factory()->create();
    $key = (string) Str::uuid();

    $payload = [
        'idempotency_key' => $key,
        'member_id' => $member->id,
        'savings_type' => 'wajib',
        'amount' => '200000',
        'deposit_date' => now()->toDateString(),
        'deposit_method' => 'setor_sendiri',
        'deposited_by' => 'anggota',
    ];

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm($payload)
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm($payload)
        ->call('create')
        ->assertNotified('Transaksi sudah tercatat');

    expect(SavingsDeposit::where('idempotency_key', $key)->count())->toBe(1);
});

it('warns on the same key with a different payload, without creating a row (D4)', function () {
    $member = Member::factory()->create();
    $key = (string) Str::uuid();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'idempotency_key' => $key,
            'member_id' => $member->id,
            'savings_type' => 'wajib',
            'amount' => '200000',
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'idempotency_key' => $key,
            'member_id' => $member->id,
            'savings_type' => 'wajib',
            'amount' => '999000',
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
        ])
        ->call('create')
        ->assertNotified('Submission duplikat dengan data berbeda');

    expect(SavingsDeposit::where('idempotency_key', $key)->count())->toBe(1);
});

it('redirects to the index after creating', function () {
    $member = Member::factory()->create();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'savings_type' => 'sukarela',
            'amount' => '75000',
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
        ])
        ->call('create')
        ->assertRedirect(SavingsDepositResource::getUrl('index'));
});

it('renders the view page with infolist', function () {
    $deposit = SavingsDeposit::factory()->create(['amount' => 123456]);

    Livewire::test(ViewSavingsDeposit::class, ['record' => $deposit->getKey()])
        ->assertOk()
        ->assertSee($deposit->transaction_number);
});

it('writes an activity log when a deposit is created', function () {
    $deposit = SavingsDeposit::factory()->create();

    expect(
        Activity::where('subject_type', SavingsDeposit::class)
            ->where('subject_id', $deposit->getKey())
            ->where('event', 'created')
            ->exists()
    )->toBeTrue();
});

it('registers the audit trail relation manager', function () {
    expect(SavingsDepositResource::getRelations())
        ->toContain(AuditTrailRelationManager::class);
});

it('does not expose an edit page (deposits are immutable, D3)', function () {
    expect(array_keys(SavingsDepositResource::getPages()))
        ->toBe(['index', 'create', 'view']);
});

it('grants create to petugas and reverse to both petugas and pengurus (D7)', function () {
    $petugas = asPetugas();
    expect($petugas->can('create_savings::deposit'))->toBeTrue()
        ->and($petugas->can('reverse_savings::deposit'))->toBeTrue();

    $pengurus = asPengurus();
    expect($pengurus->can('create_savings::deposit'))->toBeTrue()
        ->and($pengurus->can('reverse_savings::deposit'))->toBeTrue();
});

it('reverses a deposit from the table action, netting balance to zero (2b)', function () {
    asPetugas();
    $deposit = SavingsDeposit::factory()->type('sukarela')->create(['amount' => 100000]);

    Livewire::test(ListSavingsDeposits::class)
        ->callTableAction('reverse', $deposit, data: ['reason' => 'salah input nominal'])
        ->assertHasNoTableActionErrors();

    $reversal = SavingsDeposit::where('reversal_of_id', $deposit->getKey())->first();

    expect($reversal)->not->toBeNull()
        ->and($reversal->is_reversal)->toBeTrue()
        ->and(app(SavingsBalanceService::class)->balanceByType($deposit->member, 'sukarela'))->toBe('0.00');
});

it('requires a reason of at least 5 characters for reversal (2b)', function () {
    asPetugas();
    $deposit = SavingsDeposit::factory()->type('sukarela')->create();

    Livewire::test(ListSavingsDeposits::class)
        ->callTableAction('reverse', $deposit, data: ['reason' => 'no'])
        ->assertHasTableActionErrors(['reason']);

    expect(SavingsDeposit::where('reversal_of_id', $deposit->getKey())->exists())->toBeFalse();
});

it('hides the reversal action for a row that is itself a reversal (2b)', function () {
    asPetugas();
    $deposit = SavingsDeposit::factory()->type('sukarela')->create();
    app(ReverseTransaction::class)($deposit, 'koreksi awal');

    $reversalRow = SavingsDeposit::where('reversal_of_id', $deposit->getKey())->first();

    Livewire::test(ListSavingsDeposits::class)
        ->assertTableActionHidden('reverse', $reversalRow);
});

it('hides the reversal action from a user with view but no reverse permission (D7)', function () {
    test()->seed(RolePermissionSeeder::class);
    $user = User::factory()->create();
    // view access only — boleh lihat halaman, tapi tak boleh reverse.
    $user->givePermissionTo(['view_any_savings::deposit', 'view_savings::deposit']);
    test()->actingAs($user);

    $deposit = SavingsDeposit::factory()->type('sukarela')->create();

    Livewire::test(ListSavingsDeposits::class)
        ->assertTableActionHidden('reverse', $deposit);
});

// ── Settings-aware nominal per jenis ───────────────────────────────────────

it('locks the pokok amount to the cooperative setting, ignoring tampered input', function () {
    $member = Member::factory()->create();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'savings_type' => 'pokok',
            'amount' => '999', // tampered — harus diabaikan
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deposit = SavingsDeposit::where('member_id', $member->id)->where('savings_type', 'pokok')->first();
    expect((float) $deposit->amount)->toBe((float) app(CooperativeSettings::class)->savings_pokok_amount);
});

it('locks the wajib_belanja amount to the cooperative setting', function () {
    $member = Member::factory()->create();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'savings_type' => 'wajib_belanja',
            'amount' => '1',
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deposit = SavingsDeposit::where('member_id', $member->id)->where('savings_type', 'wajib_belanja')->first();
    expect((float) $deposit->amount)->toBe((float) app(CooperativeSettings::class)->savings_wajib_belanja_amount);
});

it('allows overriding the wajib amount (prefilled from grade snapshot, editable)', function () {
    $member = Member::factory()->create(['mandatory_savings_amount' => 50000]);

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'savings_type' => 'wajib',
            'amount' => '70000', // override diperbolehkan
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deposit = SavingsDeposit::where('member_id', $member->id)->where('savings_type', 'wajib')->first();
    expect((float) $deposit->amount)->toBe(70000.0);
});

it('rejects a sukarela amount below the configured minimum', function () {
    $settings = app(CooperativeSettings::class);
    $settings->savings_sukarela_min = 50000;
    $settings->save();
    app()->forgetInstance(CooperativeSettings::class);

    $member = Member::factory()->create();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'savings_type' => 'sukarela',
            'amount' => '10000',
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
        ])
        ->call('create')
        ->assertHasFormErrors(['amount']);
});

it('offers hari_raya only when the member has an active registration', function () {
    $registered = Member::factory()->create();
    MemberHolidaySaving::factory()->year((int) now()->year)->create(['member_id' => $registered->id]);

    $unregistered = Member::factory()->create();

    expect(SavingsDepositResource::savingsTypeOptions($registered->id))->toHaveKey('hari_raya')
        ->and(SavingsDepositResource::savingsTypeOptions($unregistered->id))->not->toHaveKey('hari_raya')
        ->and(SavingsDepositResource::savingsTypeOptions(null))->not->toHaveKey('hari_raya');
});

it('locks the hari_raya amount to the active registration monthly_amount', function () {
    $member = Member::factory()->create();
    MemberHolidaySaving::factory()->year(2026)->create([
        'member_id' => $member->id,
        'monthly_amount' => 85000,
    ]);

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'savings_type' => 'hari_raya',
            'amount' => '1', // tampered
            'deposit_date' => now()->toDateString(),
            'period_month' => '2026-06-01',
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deposit = SavingsDeposit::where('member_id', $member->id)->where('savings_type', 'hari_raya')->first();
    expect((float) $deposit->amount)->toBe(85000.0);
});

it('rejects a hari_raya deposit for a year without an active registration', function () {
    $member = Member::factory()->create();
    MemberHolidaySaving::factory()->year(2026)->create(['member_id' => $member->id]);

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'savings_type' => 'hari_raya',
            'amount' => '1',
            'deposit_date' => now()->toDateString(),
            'period_month' => '2025-06-01', // tahun tanpa registrasi
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
        ])
        ->call('create')
        ->assertHasFormErrors(['period_month']);
});
