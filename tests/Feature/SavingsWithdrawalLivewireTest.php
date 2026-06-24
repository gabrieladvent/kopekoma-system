<?php

use App\Livewire\Savings\Withdrawal\SavingsWithdrawalForm;
use App\Livewire\Savings\Withdrawal\SavingsWithdrawals;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use Livewire\Livewire;

/** Anggota dengan saldo sukarela + hari_raya (tahun tertentu) terisi. */
function memberWithWithdrawableBalance(int $sukarela = 200000, int $holiday = 150000, int $year = 2025): Member
{
    $member = Member::factory()->create(['status' => 'Aktif']);

    SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $member->id,
        'amount' => $sukarela,
        'is_reversal' => false,
    ]);

    SavingsDeposit::factory()->type('hari_raya')->create([
        'member_id' => $member->id,
        'amount' => $holiday,
        'period_month' => "{$year}-06-01",
        'is_reversal' => false,
    ]);

    return $member;
}

it('renders the withdrawal index + create pages', function () {
    asSuperAdmin();

    $this->get(route('savings.withdrawals'))->assertOk()->assertSeeLivewire('savings.withdrawal.savings-withdrawals');
    $this->get(route('savings.withdrawals.create'))->assertOk()->assertSeeLivewire('savings.withdrawal.savings-withdrawal-form');
});

it('builds withdrawal lines from balances — sukarela + one line per holiday year, skips empty', function () {
    asSuperAdmin();
    $member = memberWithWithdrawableBalance(sukarela: 200000, holiday: 150000, year: 2025);

    $component = Livewire::test(SavingsWithdrawalForm::class)->call('selectMember', $member->id);

    $lines = collect($component->get('lines'));

    expect($lines)->toHaveCount(2)
        ->and($lines->firstWhere('savings_type', 'sukarela')['balance'])->toBe('200000.00')
        ->and($lines->firstWhere('savings_type', 'hari_raya')['period_year'])->toBe(2025);
});

it('shows no lines when the member has no withdrawable balance', function () {
    asSuperAdmin();
    $member = Member::factory()->create(['status' => 'Aktif']);
    // Hanya wajib (tak bisa dicairkan) → tak ada baris.
    SavingsDeposit::factory()->type('wajib')->create(['member_id' => $member->id, 'amount' => 100000]);

    $component = Livewire::test(SavingsWithdrawalForm::class)->call('selectMember', $member->id);

    expect($component->get('lines'))->toBeEmpty();
});

it('creates one draft withdrawal record per selected source in a single submit', function () {
    asSuperAdmin();
    $member = memberWithWithdrawableBalance(sukarela: 200000, holiday: 150000, year: 2025);

    Livewire::test(SavingsWithdrawalForm::class)
        ->call('selectMember', $member->id)
        ->set('withdrawal_date', now()->toDateString())
        ->tap(function ($c) {
            $lines = collect($c->get('lines'))->map(function (array $line) {
                $line['include'] = true;
                $line['amount'] = $line['savings_type'] === 'sukarela' ? '120000' : '100000';

                return $line;
            })->all();
            $c->set('lines', $lines);
        })
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('savings.withdrawals'));

    expect(SavingsWithdrawal::where('member_id', $member->id)->count())->toBe(2);

    $this->assertDatabaseHas('savings_withdrawals', [
        'member_id' => $member->id, 'savings_type' => 'sukarela', 'amount' => 120000, 'status' => 'draft', 'period_year' => null,
    ]);
    $this->assertDatabaseHas('savings_withdrawals', [
        'member_id' => $member->id, 'savings_type' => 'hari_raya', 'amount' => 100000, 'status' => 'draft', 'period_year' => 2025,
    ]);
});

it('rejects a line amount exceeding the available balance', function () {
    asSuperAdmin();
    $member = memberWithWithdrawableBalance(sukarela: 200000, holiday: 150000, year: 2025);

    Livewire::test(SavingsWithdrawalForm::class)
        ->call('selectMember', $member->id)
        ->tap(function ($c) {
            $lines = collect($c->get('lines'))->map(function (array $line) {
                if ($line['savings_type'] === 'sukarela') {
                    $line['include'] = true;
                    $line['amount'] = '500000'; // > saldo 200rb
                }

                return $line;
            })->all();
            $c->set('lines', $lines);
        })
        ->call('save')
        ->assertHasErrors('lines.0.amount');

    expect(SavingsWithdrawal::where('member_id', $member->id)->exists())->toBeFalse();
});

it('runs the approve → disburse workflow from the list', function () {
    asSuperAdmin();
    $member = memberWithWithdrawableBalance(sukarela: 200000);
    $withdrawal = SavingsWithdrawal::factory()->type('sukarela')->create([
        'member_id' => $member->id, 'amount' => 100000, 'status' => 'draft',
    ]);

    Livewire::test(SavingsWithdrawals::class)
        ->call('openConfirm', 'approve', $withdrawal->id)
        ->call('performConfirm');

    expect($withdrawal->fresh()->status)->toBe('acc');

    Livewire::test(SavingsWithdrawals::class)
        ->call('openConfirm', 'disburse', $withdrawal->id)
        ->call('performConfirm');

    expect($withdrawal->fresh()->status)->toBe('cair')
        ->and($withdrawal->fresh()->disbursed_at)->not->toBeNull();
});

it('rejects a draft withdrawal as final', function () {
    asSuperAdmin();
    $member = memberWithWithdrawableBalance();
    $withdrawal = SavingsWithdrawal::factory()->type('sukarela')->create([
        'member_id' => $member->id, 'amount' => 50000, 'status' => 'draft',
    ]);

    Livewire::test(SavingsWithdrawals::class)
        ->call('openConfirm', 'reject', $withdrawal->id)
        ->call('performConfirm');

    expect($withdrawal->fresh()->status)->toBe('ditolak');
});

it('allows reversing a disbursed withdrawal from the list', function () {
    asSuperAdmin();
    $member = memberWithWithdrawableBalance(sukarela: 300000);
    $withdrawal = SavingsWithdrawal::factory()->type('sukarela')->cair()->create([
        'member_id' => $member->id, 'amount' => 100000, 'is_reversal' => false,
    ]);

    Livewire::test(SavingsWithdrawals::class)
        ->call('openReverse', $withdrawal->id)
        ->set('reverseReason', 'Salah input nominal pencairan')
        ->call('performReverse')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('savings_withdrawals', [
        'reversal_of_id' => $withdrawal->id, 'is_reversal' => true,
    ]);
});

it('renders the withdrawal detail page', function () {
    asSuperAdmin();
    $member = memberWithWithdrawableBalance();
    $withdrawal = SavingsWithdrawal::factory()->type('sukarela')->create(['member_id' => $member->id]);

    $this->get(route('savings.withdrawals.show', $withdrawal))
        ->assertOk()
        ->assertSee($withdrawal->withdrawal_number)
        ->assertSee('Alur Pencairan');
});

it('blocks the withdrawal pages for users without permission', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('savings.withdrawals'))->assertForbidden();
    $this->get(route('savings.withdrawals.create'))->assertForbidden();
});
