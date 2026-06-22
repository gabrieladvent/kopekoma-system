<?php

use App\Livewire\Savings\Holiday\HolidayRegistrationForm;
use App\Livewire\Savings\Shopping\ShoppingTransactionForm;
use App\Livewire\Savings\Shopping\ShoppingTransactions;
use App\Models\Member;
use App\Models\MemberHolidaySaving;
use App\Models\SavingsDeposit;
use App\Models\ShoppingTransaction;
use Livewire\Livewire;

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
