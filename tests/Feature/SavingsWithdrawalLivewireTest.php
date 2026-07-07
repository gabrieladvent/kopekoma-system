<?php

use App\Filament\Resources\SavingsWithdrawalResource;
use App\Livewire\Savings\Withdrawal\SavingsWithdrawalForm;
use App\Livewire\Savings\Withdrawal\SavingsWithdrawals;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\MemberHolidaySaving;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use App\Services\SavingsBalanceService;
use Livewire\Livewire;

/**
 * Pasangan refund pelunasan (D2): pinjaman Lunas dengan saldo SWP (loans.swp_amount)
 * + Tab Berjangka (monthly_time_deposit × angsuran terbayar), beserta dua pencairan
 * refund (swp + tabungan_berjangka) ber-related_loan_id sama. Status default draft.
 *
 * @return array{0: Loan, 1: SavingsWithdrawal, 2: SavingsWithdrawal}
 */
function refundPairFor(Member $member, string $status = 'draft', int $swp = 10000, int $tab = 1000): array
{
    $loan = Loan::factory()->create([
        'member_id' => $member->id,
        'swp_amount' => $swp,
        'monthly_time_deposit' => $tab,
        'status' => 'Lunas',
    ]);
    Installment::factory()->create(['loan_id' => $loan->id]); // 1 angsuran → tab balance = $tab

    $make = function (string $type, int $amount) use ($member, $loan, $status): SavingsWithdrawal {
        $factory = SavingsWithdrawal::factory()->type($type);
        $factory = $status === 'cair' ? $factory->cair() : $factory->status($status);

        return $factory->create([
            'member_id' => $member->id,
            'amount' => $amount,
            'related_loan_id' => $loan->id,
            'is_reversal' => false,
        ]);
    };

    return [$loan, $make('swp', $swp), $make('tabungan_berjangka', $tab)];
}

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

    // Baris Hari Raya hanya ditawarkan bila ada registrasi AKTIF utk tahun tsb.
    MemberHolidaySaving::factory()->create([
        'member_id' => $member->id,
        'period_year' => $year,
        'is_active' => true,
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

it('hides the reverse action on an original that has already been reversed', function () {
    asSuperAdmin();
    $member = memberWithWithdrawableBalance(sukarela: 300000);
    $original = SavingsWithdrawal::factory()->type('sukarela')->cair()->create([
        'member_id' => $member->id, 'amount' => 100000, 'is_reversal' => false,
    ]);

    $component = Livewire::test(SavingsWithdrawals::class);

    // Sebelum di-reversal: tombol muncul.
    expect($component->instance()->canReverse($original->fresh()))->toBeTrue();

    $component->call('openReverse', $original->id)
        ->set('reverseReason', 'Salah input nominal pencairan')
        ->call('performReverse')
        ->assertHasNoErrors();

    // Setelah di-reversal: record asli TETAP tampil, tapi tombol Reversal disembunyikan.
    expect($original->fresh()->isReversed())->toBeTrue()
        ->and(Livewire::test(SavingsWithdrawals::class)->instance()->canReverse($original->fresh()))->toBeFalse();
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

// ── Track B (1c): pencairan manual SWP/Tab + saldo sadar-pending (D3) ──

it('builds swp + tabungan berjangka source lines from loan-held balances (1c)', function () {
    asSuperAdmin();
    $member = Member::factory()->create(['status' => 'Aktif']);
    $loan = Loan::factory()->create([
        'member_id' => $member->id, 'swp_amount' => 120000, 'monthly_time_deposit' => 12000,
    ]);
    Installment::factory()->count(3)->create(['loan_id' => $loan->id]); // tab balance = 36000

    $lines = collect(
        Livewire::test(SavingsWithdrawalForm::class)->call('selectMember', $member->id)->get('lines')
    );

    expect($lines->firstWhere('savings_type', 'swp')['balance'])->toBe('120000.00')
        ->and($lines->firstWhere('savings_type', 'tabungan_berjangka')['balance'])->toBe('36000.00');
});

it('reduces the available swp line balance by pending withdrawals (D3)', function () {
    asSuperAdmin();
    $member = Member::factory()->create(['status' => 'Aktif']);
    Loan::factory()->create(['member_id' => $member->id, 'swp_amount' => 120000]);
    // Pencairan PENDING (draft) belum kurangi saldo cair → harus dikurangi di form.
    SavingsWithdrawal::factory()->type('swp')->status('draft')->create([
        'member_id' => $member->id, 'amount' => 50000, 'is_reversal' => false,
    ]);

    $lines = collect(
        Livewire::test(SavingsWithdrawalForm::class)->call('selectMember', $member->id)->get('lines')
    );

    expect($lines->firstWhere('savings_type', 'swp')['balance'])->toBe('70000.00');
});

it('rejects a manual swp line exceeding balance minus pending (D3)', function () {
    asSuperAdmin();
    $member = Member::factory()->create(['status' => 'Aktif']);
    Loan::factory()->create(['member_id' => $member->id, 'swp_amount' => 120000]);
    SavingsWithdrawal::factory()->type('swp')->status('draft')->create([
        'member_id' => $member->id, 'amount' => 50000, 'is_reversal' => false,
    ]);

    Livewire::test(SavingsWithdrawalForm::class)
        ->call('selectMember', $member->id)
        ->tap(function ($c) {
            $lines = collect($c->get('lines'))->map(function (array $line) {
                if ($line['savings_type'] === 'swp') {
                    $line['include'] = true;
                    $line['amount'] = '100000'; // > saldo tersedia 70.000
                }

                return $line;
            })->all();
            $c->set('lines', $lines);
        })
        ->call('save')
        ->assertHasErrors();

    // Tidak ada pencairan baru tercipta.
    expect(SavingsWithdrawal::where('member_id', $member->id)->where('amount', 100000)->exists())->toBeFalse();
});

// ── Track B (1d): grouping + aksi gabungan pasangan refund (D2/D4) ──

it('groups a settled-loan refund pair into a single representative row (D2)', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    [$loan, $swpW] = refundPairFor($member, swp: 10000, tab: 1000);

    expect(SavingsWithdrawalResource::isLoanRefund($swpW))->toBeTrue()
        ->and(SavingsWithdrawalResource::pairTotal($swpW))->toBe('11000.00');

    $shown = SavingsWithdrawalResource::hideSecondaryPairRows(SavingsWithdrawal::query())
        ->where('related_loan_id', $loan->id)
        ->get();

    expect($shown)->toHaveCount(1)
        ->and($shown->first()->savings_type)->toBe('swp');

    Livewire::test(SavingsWithdrawals::class)->assertSee('Pengembalian Pelunasan');
});

it('approves both records of a refund pair in one list action (D2)', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    [, $swpW, $tabW] = refundPairFor($member);

    Livewire::test(SavingsWithdrawals::class)
        ->call('openConfirm', 'approve', $swpW->id)
        ->call('performConfirm');

    expect($swpW->fresh()->status)->toBe('acc')
        ->and($tabW->fresh()->status)->toBe('acc');
});

it('disburses both records of a refund pair and reduces both balances (D2)', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    [, $swpW, $tabW] = refundPairFor($member, swp: 10000, tab: 1000);
    $balances = app(SavingsBalanceService::class);

    Livewire::test(SavingsWithdrawals::class)->call('openConfirm', 'approve', $swpW->id)->call('performConfirm');
    Livewire::test(SavingsWithdrawals::class)->call('openConfirm', 'disburse', $swpW->id)->call('performConfirm');

    expect($swpW->fresh()->status)->toBe('cair')
        ->and($tabW->fresh()->status)->toBe('cair')
        ->and($balances->balanceByType($member, 'swp'))->toBe('0.00')
        ->and($balances->balanceByType($member, 'tabungan_berjangka'))->toBe('0.00');
});

it('rejects both records of a refund pair in one list action (D2)', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    [, $swpW, $tabW] = refundPairFor($member);

    Livewire::test(SavingsWithdrawals::class)
        ->call('openConfirm', 'reject', $swpW->id)
        ->call('performConfirm');

    expect($swpW->fresh()->status)->toBe('ditolak')
        ->and($tabW->fresh()->status)->toBe('ditolak');
});

it('reverses both records of a cair refund pair in one action (D2/D4)', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    [, $swpW, $tabW] = refundPairFor($member, status: 'cair');

    Livewire::test(SavingsWithdrawals::class)
        ->call('openReverse', $swpW->id)
        ->set('reverseReason', 'Pelunasan dibatalkan — tarik kembali refund')
        ->call('performReverse')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('savings_withdrawals', ['reversal_of_id' => $swpW->id, 'is_reversal' => true]);
    $this->assertDatabaseHas('savings_withdrawals', ['reversal_of_id' => $tabW->id, 'is_reversal' => true]);
});
