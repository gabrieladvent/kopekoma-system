<?php

use App\Livewire\Savings\Deposit\BatchSalaryDeduction;
use App\Livewire\Savings\Deposit\SavingsDepositForm;
use App\Livewire\Savings\Deposit\SavingsDeposits;
use App\Livewire\Savings\Holiday\HolidayRegistrationForm;
use App\Livewire\Savings\Shopping\ShoppingTransactionForm;
use App\Livewire\Savings\Shopping\ShoppingTransactions;
use App\Models\Agency;
use App\Models\Member;
use App\Models\MemberHolidaySaving;
use App\Models\SavingsDeposit;
use App\Models\ShoppingTransaction;
use App\Models\User;
use App\Services\BatchSalaryDeductionService;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('renders all savings index + create pages', function () {
    asSuperAdmin();

    $this->get(route('savings.holiday'))->assertOk()->assertSeeLivewire('savings.holiday.holiday-registrations');
    $this->get(route('savings.holiday.create'))->assertOk();
    $this->get(route('savings.shopping'))->assertOk()->assertSeeLivewire('savings.shopping.shopping-transactions');
    $this->get(route('savings.shopping.create'))->assertOk();
    $this->get(route('savings.balances'))->assertOk()->assertSeeLivewire('savings.member-balances');
});

it('renders holiday detail + edit', function () {
    asSuperAdmin();
    $holiday = MemberHolidaySaving::factory()->create();

    $this->get(route('savings.holiday.show', $holiday))->assertOk();
    $this->get(route('savings.holiday.edit', $holiday))->assertOk();
});

it('creates a holiday registration via the form and derives period_year', function () {
    asSuperAdmin();
    $member = Member::factory()->create();

    Livewire::test(HolidayRegistrationForm::class)
        ->set('member_id', $member->id)
        ->set('start_date', '2026-01-01')
        ->set('end_date', '2026-12-31')
        ->set('monthly_amount', 100000)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('member_holiday_savings', [
        'member_id' => $member->id,
        'period_year' => 2026,
        'monthly_amount' => 100000,
    ]);
});

it('rejects duplicate holiday registration in the same program year', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    MemberHolidaySaving::factory()->create([
        'member_id' => $member->id,
        'period_year' => 2026,
        'end_date' => '2026-12-31',
    ]);

    Livewire::test(HolidayRegistrationForm::class)
        ->set('member_id', $member->id)
        ->set('start_date', '2026-01-01')
        ->set('end_date', '2026-12-31')
        ->set('monthly_amount', 50000)
        ->call('save')
        ->assertHasErrors('member_id');
});

it('records shopping usage and blocks amounts over the balance', function () {
    asSuperAdmin();
    $member = Member::factory()->create(['status' => 'Aktif']);
    SavingsDeposit::factory()->type('wajib_belanja')->create([
        'member_id' => $member->id,
        'amount' => 200000,
    ]);

    // Melebihi saldo → ditolak.
    Livewire::test(ShoppingTransactionForm::class)
        ->set('member_id', $member->id)
        ->set('amount', 500000)
        ->set('transaction_date', now()->toDateString())
        ->call('save')
        ->assertHasErrors('amount');

    // Dalam saldo → tercatat.
    Livewire::test(ShoppingTransactionForm::class)
        ->set('member_id', $member->id)
        ->set('amount', 150000)
        ->set('transaction_date', now()->toDateString())
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('shopping_transactions', [
        'member_id' => $member->id,
        'amount' => 150000,
        'source' => 'manual',
    ]);
});

it('renders the member savings ledger detail with mutations', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    SavingsDeposit::factory()->type('wajib')->create([
        'member_id' => $member->id,
        'amount' => 250000,
    ]);

    $this->get(route('savings.balances.show', $member))
        ->assertOk()
        ->assertSee('Buku Mutasi Simpanan')
        ->assertSee($member->full_name);
});

it('blocks the balance pages for users without permission', function () {
    asPetugas(); // tidak punya view_any_member::savings::balance

    $this->get(route('savings.balances'))->assertForbidden();
});

it('allows pengurus to reverse a shopping transaction from the list', function () {
    asSuperAdmin();
    $member = Member::factory()->create(['status' => 'Aktif']);
    SavingsDeposit::factory()->type('wajib_belanja')->create([
        'member_id' => $member->id,
        'amount' => 300000,
    ]);
    $trx = ShoppingTransaction::factory()->create([
        'member_id' => $member->id,
        'amount' => 100000,
        'is_reversal' => false,
    ]);

    Livewire::test(ShoppingTransactions::class)
        ->call('openReverse', $trx->id)
        ->set('reverseReason', 'Salah input nominal')
        ->call('performReverse')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('shopping_transactions', [
        'reversal_of_id' => $trx->id,
        'is_reversal' => true,
    ]);
});

it('renders the savings deposit index + create pages', function () {
    asSuperAdmin();

    $this->get(route('savings.deposits'))->assertOk()->assertSeeLivewire('savings.deposit.savings-deposits');
    $this->get(route('savings.deposits.create'))->assertOk()->assertSeeLivewire('savings.deposit.savings-deposit-form');
});

it('builds deposit lines after selecting a member and excludes already-deposited types', function () {
    asSuperAdmin();
    $member = Member::factory()->create();

    // Pokok sudah disetor → tak boleh muncul lagi (sekali seumur keanggotaan).
    SavingsDeposit::factory()->type('pokok')->create([
        'member_id' => $member->id,
        'amount' => 50000,
    ]);

    $component = Livewire::test(SavingsDepositForm::class)
        ->call('selectMember', $member->id);

    $types = collect($component->get('lines'))->pluck('savings_type');

    expect($types)->not->toContain('pokok')      // sudah disetor
        ->and($types)->not->toContain('hari_raya') // tak ada program aktif
        ->and($types)->toContain('wajib')
        ->and($types)->toContain('sukarela');
});

it('records multiple savings deposits in one submit', function () {
    asSuperAdmin();
    $member = Member::factory()->create();

    Livewire::test(SavingsDepositForm::class)
        ->call('selectMember', $member->id)
        ->set('period_month', now()->format('Y-m'))
        ->set('deposit_date', now()->toDateString())
        ->tap(function ($c) {
            // Centang wajib + pokok dengan nominal yang sesuai.
            $lines = collect($c->get('lines'))->map(function (array $line) {
                if (in_array($line['savings_type'], ['wajib', 'pokok'], true)) {
                    $line['include'] = true;
                }
                if ($line['savings_type'] === 'wajib') {
                    $line['amount'] = '75000';
                }

                return $line;
            })->all();
            $c->set('lines', $lines);
        })
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('savings_deposits', [
        'member_id' => $member->id,
        'savings_type' => 'wajib',
        'amount' => 75000,
    ]);
    $this->assertDatabaseHas('savings_deposits', [
        'member_id' => $member->id,
        'savings_type' => 'pokok',
        'amount' => 50000, // nominal terkunci dari settings
    ]);
});

it('allows pengurus to reverse a savings deposit from the list', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    $deposit = SavingsDeposit::factory()->type('wajib')->create([
        'member_id' => $member->id,
        'amount' => 120000,
        'is_reversal' => false,
    ]);

    Livewire::test(SavingsDeposits::class)
        ->call('openReverse', $deposit->id)
        ->set('reverseReason', 'Salah input nominal')
        ->call('performReverse')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('savings_deposits', [
        'reversal_of_id' => $deposit->id,
        'is_reversal' => true,
    ]);
});

it('blocks the deposit pages for users without permission', function () {
    // User tanpa role → tak punya view_any_savings::deposit.
    $this->actingAs(User::factory()->create());

    $this->get(route('savings.deposits'))->assertForbidden();
});

// ── Batch Potong Gaji per OPD ─────────────────────────────────────────

it('renders the batch salary deduction page', function () {
    asSuperAdmin();

    $this->get(route('savings.deposits.batch'))
        ->assertOk()
        ->assertSeeLivewire('savings.deposit.batch-salary-deduction');
});

it('loads active members of the chosen OPD with prefilled wajib when agency chosen', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Aktif', 'mandatory_savings_amount' => 75000]);
    Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Keluar']); // tak aktif → tak dimuat

    $component = Livewire::test(BatchSalaryDeduction::class)->set('agency_id', $agency->id);

    $rows = $component->get('rows');

    expect($rows)->toHaveCount(1)->and($rows[0]['include'])->toBeTrue();

    $wajib = collect($rows[0]['lines'])->firstWhere('savings_type', 'wajib');

    expect($wajib['amount'])->toBe('75000')->and($wajib['include'])->toBeTrue();
});

it('processes the batch creating deposits only for included members', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $a = Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Aktif', 'mandatory_savings_amount' => 50000]);
    $b = Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Aktif', 'mandatory_savings_amount' => 50000]);

    $component = Livewire::test(BatchSalaryDeduction::class)
        ->set('agency_id', $agency->id)
        ->set('period_month', '2026-06');

    $idxB = collect($component->get('rows'))->search(fn (array $r): bool => $r['member_id'] === $b->id);

    $component->set("rows.$idxB.include", false)
        ->call('process')
        ->assertRedirect(route('savings.deposits'));

    expect(SavingsDeposit::where('savings_type', 'wajib')->where('member_id', $a->id)->exists())->toBeTrue()
        ->and(SavingsDeposit::where('member_id', $b->id)->exists())->toBeFalse();
});

it('locks a member type already deposited for the period and never duplicates it', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $m = Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Aktif', 'mandatory_savings_amount' => 50000]);
    SavingsDeposit::factory()->create([
        'member_id' => $m->id,
        'savings_type' => 'wajib',
        'amount' => 50000,
        'period_month' => '2026-06-01',
        'is_reversal' => false,
    ]);

    $component = Livewire::test(BatchSalaryDeduction::class)
        ->set('agency_id', $agency->id)
        ->set('period_month', '2026-06');

    $wajib = collect($component->get('rows')[0]['lines'])->firstWhere('savings_type', 'wajib');

    expect($wajib['done'])->toBeTrue()->and($wajib['include'])->toBeFalse();

    // Walau dipaksa centang, baris done di-skip & service tetap tak menduplikasi.
    $component->set('rows.0.lines.0.include', true)->call('process');

    expect(SavingsDeposit::where('member_id', $m->id)->where('savings_type', 'wajib')->count())->toBe(1);
});

it('exports the batch recap as a CSV download and logs the export activity', function () {
    asSuperAdmin();
    $agency = Agency::factory()->create();
    $m = Member::factory()->create(['agency_id' => $agency->id, 'status' => 'Aktif', 'mandatory_savings_amount' => 50000]);
    app(BatchSalaryDeductionService::class)->run($agency, '2026-06-01', [['member_id' => $m->id, 'amount' => '50000']]);

    $response = $this->get(route('savings.deposits.batch.export', [
        'agency_id' => $agency->id,
        'period_month' => '2026-06',
    ]));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    // Isi CSV memuat baris anggota (bukan hanya header).
    $csv = $response->streamedContent();
    expect($csv)->toContain('No. Transaksi')->toContain($m->member_number);

    $export = Activity::where('event', 'export')->first();
    expect($export)->not->toBeNull()->and($export->properties['rows'])->toBe(1);
});

it('forbids the recap export for users without the export permission', function () {
    asPetugas(); // punya akses batch tapi TIDAK punya export_savings_recap
    $agency = Agency::factory()->create();

    $this->get(route('savings.deposits.batch.export', [
        'agency_id' => $agency->id,
        'period_month' => '2026-06',
    ]))->assertForbidden();
});

it('denies the batch page to a user without the access permission', function () {
    asPetugas(); // seed roles
    $this->actingAs(User::factory()->create()); // tanpa role/permission

    $this->get(route('savings.deposits.batch'))->assertForbidden();
});
