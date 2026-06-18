<?php

use App\Filament\Resources\MemberResource\Pages\ViewMember;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Services\SavingsBalanceService;
use App\Services\SavingsMutationService;
use Livewire\Livewire;

beforeEach(function () {
    $this->service = app(SavingsMutationService::class);
});

it('combines deposits (masuk) and cair withdrawals (keluar) into one chronological ledger', function () {
    $member = Member::factory()->create();

    SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $member->id, 'amount' => '100000', 'deposit_date' => '2026-01-10',
    ]);
    SavingsWithdrawal::factory()->type('sukarela')->cair()->create([
        'member_id' => $member->id, 'amount' => '30000', 'withdrawal_date' => '2026-02-15',
    ]);

    $rows = $this->service->ledgerFor($member, newestFirst: false);

    expect($rows)->toHaveCount(2)
        // baris 1: setoran → masuk 100rb, saldo 100rb
        ->and($rows[0]['masuk'])->toBe('100000.00')
        ->and($rows[0]['keluar'])->toBe('0')
        ->and($rows[0]['saldo'])->toBe('100000.00')
        // baris 2: pencairan → keluar 30rb, saldo 70rb
        ->and($rows[1]['keluar'])->toBe('30000.00')
        ->and($rows[1]['masuk'])->toBe('0')
        ->and($rows[1]['saldo'])->toBe('70000.00');
});

it('ignores draft/acc withdrawals (uang belum keluar)', function () {
    $member = Member::factory()->create();
    SavingsDeposit::factory()->type('sukarela')->create(['member_id' => $member->id, 'amount' => '100000']);
    SavingsWithdrawal::factory()->type('sukarela')->status('acc')->create([
        'member_id' => $member->id, 'amount' => '40000',
    ]);

    $rows = $this->service->ledgerFor($member);

    // hanya setoran yang tampil; pencairan acc belum jadi mutasi keluar.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['masuk'])->toBe('100000.00');
});

it('reconciles the final running balance with totalBalance()', function () {
    $member = Member::factory()->create();
    SavingsDeposit::factory()->type('sukarela')->create(['member_id' => $member->id, 'amount' => '250000']);
    SavingsWithdrawal::factory()->type('sukarela')->cair()->create(['member_id' => $member->id, 'amount' => '90000']);

    $chronological = $this->service->ledgerFor($member, newestFirst: false);
    $lastSaldo = end($chronological)['saldo'];

    expect($lastSaldo)->toBe(app(SavingsBalanceService::class)->totalBalance($member));
});

it('renders the mutation ledger on the member view page', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    $deposit = SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $member->id, 'amount' => '100000',
    ]);

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->assertOk()
        ->assertSee('Riwayat Simpanan')
        ->assertSee($deposit->transaction_number);
});

it('treats a deposit reversal as an outgoing line', function () {
    $member = Member::factory()->create();
    $deposit = SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $member->id, 'amount' => '100000',
    ]);
    // baris-lawan reversal: efek -100rb
    SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $member->id, 'amount' => '100000',
        'is_reversal' => true, 'reversal_of_id' => $deposit->id,
    ]);

    $rows = $this->service->ledgerFor($member, newestFirst: false);

    expect($rows[1]['keluar'])->toBe('100000.00') // reversal = keluar
        ->and($rows[1]['saldo'])->toBe('0.00');
});
