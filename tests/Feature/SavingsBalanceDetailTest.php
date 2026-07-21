<?php

use App\Filament\Resources\MemberResource\Pages\ViewMember;
use App\Filament\Resources\MemberSavingsBalanceResource\Pages\ListMemberSavingsBalances;
use App\Filament\Widgets\SavingsCashInflowChart;
use App\Filament\Widgets\SavingsStatsOverview;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Services\SavingsBalanceService;
use Livewire\Livewire;

beforeEach(function () {
    asSuperAdmin();
});

// ── Ringkasan saldo per anggota (View Anggota) ───────────────────────

it('shows the savings summary with the correct total on the member view', function () {
    $member = Member::factory()->create();
    SavingsDeposit::factory()->type('pokok')->create(['member_id' => $member->id, 'amount' => 150000]);
    SavingsDeposit::factory()->type('sukarela')->create(['member_id' => $member->id, 'amount' => 200000]);
    // Reversal sukarela → net sukarela 0, total = pokok 150rb.
    SavingsDeposit::factory()->type('sukarela')->create(['member_id' => $member->id, 'amount' => 200000, 'is_reversal' => true]);

    expect(app(SavingsBalanceService::class)->totalBalance($member))->toBe('150000.00');

    Livewire::test(ViewMember::class, ['record' => $member->getKey()])
        ->assertOk()
        ->assertSee('Ringkasan Simpanan');
});

it('shows both incoming and outgoing transactions in the member savings passbook', function () {
    $member = Member::factory()->create();
    $setoran = SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $member->id, 'amount' => '100000', 'deposit_date' => '2026-01-10',
    ]);
    $pencairan = SavingsWithdrawal::factory()->type('sukarela')->cair()->create([
        'member_id' => $member->id, 'amount' => '30000', 'withdrawal_date' => '2026-02-15',
    ]);

    // Buku tabungan menampilkan uang masuk DAN uang keluar di satu tempat,
    // plus baris Total (ringkasan) dengan saldo saat ini (100rb − 30rb = 70rb).
    Livewire::test(ViewMember::class, ['record' => $member->getKey()])
        ->assertOk()
        ->assertSee('Riwayat Simpanan')
        ->assertSee($setoran->transaction_number)   // masuk
        ->assertSee($pencairan->withdrawal_number)  // keluar
        ->assertSee('Total')                        // footer ringkasan
        ->assertSee('Rp 70.000');                   // saldo saat ini
});

// ── Tabel saldo semua anggota ────────────────────────────────────────

it('renders the member savings balance list with active members', function () {
    $active = Member::factory()->create(['status' => 'Aktif']);
    $left = Member::factory()->create(['status' => 'Keluar']);
    SavingsDeposit::factory()->type('pokok')->create(['member_id' => $active->id, 'amount' => 100000]);

    // Default filter status = Aktif → anggota keluar tak tampil.
    Livewire::test(ListMemberSavingsBalances::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$left]);
});

// ── Dashboard widgets ────────────────────────────────────────────────

it('computes the savings stats overview', function () {
    $member = Member::factory()->create(['status' => 'Aktif']);
    SavingsDeposit::factory()->type('pokok')->create([
        'member_id' => $member->id,
        'amount' => 500000,
        'deposit_date' => now()->toDateString(),
    ]);

    Livewire::test(SavingsStatsOverview::class)
        ->assertOk()
        ->assertSee('Total Simpanan')
        ->assertSee('Rp 500.000');
});

it('renders the cash inflow chart', function () {
    Livewire::test(SavingsCashInflowChart::class)->assertOk();
});
