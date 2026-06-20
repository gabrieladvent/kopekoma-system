<?php

use App\Actions\ReverseTransaction;
use App\Filament\Pages\BatchSalaryDeduction;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    asSuperAdmin();
});

/**
 * Satu baris jenis simpanan untuk form setoran multi-jenis (Repeater `lines`).
 *
 * @return array<string, mixed>
 */
function lineFor(string $type, ?string $amount, bool $include = true): array
{
    return [
        'savings_type' => $type,
        'type_label' => SavingsDepositResource::SAVINGS_TYPES[$type],
        'include' => $include,
        'amount' => $amount,
        'idempotency_key' => (string) Str::uuid(),
    ];
}

it('lists deposits on the index page', function () {
    $deposits = SavingsDeposit::factory()->count(3)->create();

    Livewire::test(ListSavingsDeposits::class)
        ->assertCanSeeTableRecords($deposits);
});

it('exposes the batch potong gaji as a header action, not a separate menu (Dok §4.4)', function () {
    test()->seed(RolePermissionSeeder::class);
    asPetugas();

    // "Input kolektif per OPD" diakses lewat tombol di menu Setoran Simpanan...
    Livewire::test(ListSavingsDeposits::class)
        ->assertActionExists('batchSalaryDeduction')
        ->assertActionExists('create');

    // ...dan bukan item navigasi tersendiri.
    expect(BatchSalaryDeduction::shouldRegisterNavigation())->toBeFalse();
});

it('creates a single deposit and forces recorded_by to the actor', function () {
    $actor = asSuperAdmin();
    $member = Member::factory()->create();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'lines' => [lineFor('sukarela', '150000')],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deposit = SavingsDeposit::where('member_id', $member->id)->first();

    expect($deposit)->not->toBeNull()
        ->and($deposit->recorded_by)->toBe($actor->id)
        ->and($deposit->transaction_number)->toStartWith('STR-')
        ->and((float) $deposit->amount)->toBe(150000.0);
});

it('creates several deposits in one process — one row per selected type', function () {
    $member = Member::factory()->create(['mandatory_savings_amount' => 100000]);

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'potong_gaji',
            'deposited_by' => 'bendahara',
            'lines' => [
                lineFor('wajib', '100000'),
                lineFor('sukarela', '50000'),
                lineFor('pokok', '999'), // locked → di-override server
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deposits = SavingsDeposit::where('member_id', $member->id)->get();

    expect($deposits)->toHaveCount(3)
        ->and($deposits->pluck('savings_type')->sort()->values()->all())
        ->toBe(['pokok', 'sukarela', 'wajib'])
        // metadata bersama diterapkan ke semua baris.
        ->and($deposits->pluck('deposit_method')->unique()->all())->toBe(['potong_gaji'])
        ->and((float) $deposits->firstWhere('savings_type', 'pokok')->amount)
        ->toBe((float) app(CooperativeSettings::class)->savings_pokok_amount);
});

it('skips types left unchecked or with zero amount', function () {
    $member = Member::factory()->create();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'lines' => [
                lineFor('wajib', '100000', include: true),
                lineFor('sukarela', '50000', include: false), // tak dicentang → dilewati
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SavingsDeposit::where('member_id', $member->id)->count())->toBe(1)
        ->and(SavingsDeposit::where('member_id', $member->id)->first()->savings_type)->toBe('wajib');
});

it('requires a member', function () {
    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm(['member_id' => null])
        ->call('create')
        ->assertHasFormErrors(['member_id' => 'required']);
});

it('warns when no savings type is selected', function () {
    $member = Member::factory()->create();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'lines' => [lineFor('sukarela', '50000', include: false)],
        ])
        ->call('create')
        ->assertNotified('Tidak ada jenis simpanan dipilih');

    expect(SavingsDeposit::where('member_id', $member->id)->count())->toBe(0);
});

it('auto-tags period_month to the program year for hari_raya deposits', function () {
    $member = Member::factory()->create();
    // Rentang melintasi tahun: pembagian (end_date) 2027 → tahun program 2027.
    MemberHolidaySaving::factory()->range('2026-06-01', '2027-05-31')->create([
        'member_id' => $member->id,
        'monthly_amount' => 70000,
    ]);

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'deposit_date' => '2026-06-18', // di dalam rentang
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'lines' => [lineFor('hari_raya', '1')], // tampered — server overwrite dari registrasi
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deposit = SavingsDeposit::where('member_id', $member->id)
        ->where('savings_type', 'hari_raya')
        ->first();

    expect((float) $deposit->amount)->toBe(70000.0)
        ->and($deposit->period_month->format('Y'))->toBe('2027');
});

it('treats a re-submit with the same line idempotency keys as a no-op', function () {
    $member = Member::factory()->create();
    $line = lineFor('wajib', '200000'); // kunci tetap

    $payload = [
        'member_id' => $member->id,
        'deposit_date' => now()->toDateString(),
        'deposit_method' => 'setor_sendiri',
        'deposited_by' => 'anggota',
        'lines' => [$line],
    ];

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm($payload)
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm($payload)
        ->call('create')
        ->assertNotified('Transaksi sudah tercatat');

    expect(SavingsDeposit::where('idempotency_key', $line['idempotency_key'])->count())->toBe(1);
});

it('redirects to the index after creating', function () {
    $member = Member::factory()->create();

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'lines' => [lineFor('sukarela', '75000')],
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
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'lines' => [lineFor('pokok', '999')], // tampered — harus diabaikan
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
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'lines' => [lineFor('wajib_belanja', '1')],
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
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'lines' => [lineFor('wajib', '70000')], // override diperbolehkan
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
            'deposit_date' => now()->toDateString(),
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'lines' => [lineFor('sukarela', '10000')],
        ])
        ->call('create')
        ->assertHasFormErrors();

    expect(SavingsDeposit::where('member_id', $member->id)->count())->toBe(0);
});

it('offers hari_raya only when the deposit date falls inside an active range', function () {
    $registered = Member::factory()->create();
    MemberHolidaySaving::factory()->range('2026-01-01', '2026-12-31')->create(['member_id' => $registered->id]);

    $unregistered = Member::factory()->create();

    $inRange = '2026-06-18';
    $outOfRange = '2027-06-18';

    expect(SavingsDepositResource::savingsTypeOptions($registered->id, $inRange))->toHaveKey('hari_raya')
        ->and(SavingsDepositResource::savingsTypeOptions($registered->id, $outOfRange))->not->toHaveKey('hari_raya')
        ->and(SavingsDepositResource::savingsTypeOptions($registered->id, null))->not->toHaveKey('hari_raya')
        ->and(SavingsDepositResource::savingsTypeOptions($unregistered->id, $inRange))->not->toHaveKey('hari_raya')
        ->and(SavingsDepositResource::savingsTypeOptions(null, $inRange))->not->toHaveKey('hari_raya');
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
            'deposit_date' => now()->toDateString(),
            'period_month' => '2026-06-01',
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'lines' => [lineFor('hari_raya', '1')], // tampered
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deposit = SavingsDeposit::where('member_id', $member->id)->where('savings_type', 'hari_raya')->first();
    expect((float) $deposit->amount)->toBe(85000.0);
});

it('rejects a tampered hari_raya line when the deposit date is outside the active range', function () {
    $member = Member::factory()->create();
    // Pengumpulan baru mulai 20 Jun 2026 → setoran sebelum itu di luar rentang.
    MemberHolidaySaving::factory()->range('2026-06-20', '2026-12-31')->create(['member_id' => $member->id]);

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'deposit_date' => '2026-01-10', // sebelum start_date, masih ≤ hari ini
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'lines' => [lineFor('hari_raya', '1')],
        ])
        ->call('create')
        ->assertNotified('Hari Raya tidak valid');

    expect(SavingsDeposit::where('member_id', $member->id)->count())->toBe(0);
});

// ── Aturan 1x per periode + pokok sekali seumur keanggotaan ───────────

it('hides a savings type already deposited for the period, but offers it again next period', function () {
    $member = Member::factory()->create(['mandatory_savings_amount' => 100000]);
    SavingsDeposit::factory()->create([
        'member_id' => $member->id,
        'savings_type' => 'wajib',
        'amount' => 100000,
        'period_month' => '2026-06-01',
        'is_reversal' => false,
    ]);

    $juni = collect(SavingsDepositResource::buildLines($member->id, '2026-06-10', '2026-06-01'))->pluck('savings_type');
    $juli = collect(SavingsDepositResource::buildLines($member->id, '2026-07-10', '2026-07-01'))->pluck('savings_type');

    expect($juni)->not->toContain('wajib')
        ->and($juli)->toContain('wajib');

    expect(SavingsDepositResource::hiddenTypeLabels($member->id, '2026-06-10', '2026-06-01'))
        ->toContain('Simpanan Wajib');
});

it('hides simpanan pokok once it has been paid (pokok_paid flag synced)', function () {
    $member = Member::factory()->create();
    $period = now()->startOfMonth()->toDateString();

    expect(collect(SavingsDepositResource::buildLines($member->id, now()->toDateString(), $period))->pluck('savings_type'))
        ->toContain('pokok');

    SavingsDeposit::factory()->create([
        'member_id' => $member->id,
        'savings_type' => 'pokok',
        'amount' => 250000,
        'is_reversal' => false,
    ]);

    expect($member->fresh()->pokok_paid)->toBeTrue()
        ->and(collect(SavingsDepositResource::buildLines($member->id, now()->toDateString(), $period))->pluck('savings_type'))
        ->not->toContain('pokok');
});

it('skips a type already deposited for the period on submit, without duplicating', function () {
    $member = Member::factory()->create(['mandatory_savings_amount' => 100000]);
    SavingsDeposit::factory()->create([
        'member_id' => $member->id,
        'savings_type' => 'wajib',
        'amount' => 100000,
        'period_month' => '2026-06-01',
        'is_reversal' => false,
    ]);

    Livewire::test(CreateSavingsDeposit::class)
        ->fillForm([
            'member_id' => $member->id,
            'deposit_date' => '2026-06-10',
            'period_month' => '2026-06-01',
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'lines' => [lineFor('wajib', '100000')],
        ])
        ->call('create')
        ->assertNotified('Transaksi sudah tercatat');

    expect(SavingsDeposit::where('member_id', $member->id)->where('savings_type', 'wajib')->count())->toBe(1);
});

// ── Slip setoran PDF (2c) ─────────────────────────────────────────────

it('streams the deposit slip as a PDF download named by transaction number', function () {
    $deposit = SavingsDeposit::factory()->create();

    $response = SavingsDepositResource::printSlip($deposit);

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('content-disposition'))
        ->toContain('slip-setoran-'.$deposit->transaction_number);
});

it('exposes the print slip action on the view page and table', function () {
    $deposit = SavingsDeposit::factory()->create();

    Livewire::test(ViewSavingsDeposit::class, ['record' => $deposit->getRouteKey()])
        ->assertActionExists('printSlip');

    Livewire::test(ListSavingsDeposits::class)
        ->assertTableActionExists('printSlip');
});
