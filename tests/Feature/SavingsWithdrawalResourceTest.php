<?php

use App\Enums\WithdrawalStatus;
use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\SavingsWithdrawalResource;
use App\Filament\Resources\SavingsWithdrawalResource\Pages\CreateSavingsWithdrawal;
use App\Filament\Resources\SavingsWithdrawalResource\Pages\ListSavingsWithdrawals;
use App\Filament\Resources\SavingsWithdrawalResource\Pages\ViewSavingsWithdrawal;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Services\LoanPaymentService;
use App\Services\SavingsBalanceService;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function memberWithSukarelaBalance(string $amount): Member
{
    $member = Member::factory()->create();
    SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $member->id,
        'amount' => $amount,
    ]);

    return $member;
}

// ── List / create draft ───────────────────────────────────────────────

it('lists withdrawals on the index page', function () {
    asSuperAdmin();
    $rows = SavingsWithdrawal::factory()->count(3)->create();

    Livewire::test(ListSavingsWithdrawals::class)
        ->assertCanSeeTableRecords($rows);
});

it('creates a withdrawal as draft and forces recorded_by to the actor', function () {
    $actor = asSuperAdmin();
    $member = memberWithSukarelaBalance('200000');

    Livewire::test(CreateSavingsWithdrawal::class)
        ->fillForm([
            'member_id' => $member->id,
            'savings_type' => 'sukarela',
            'amount' => '50000',
            'withdrawal_date' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $w = SavingsWithdrawal::where('member_id', $member->id)->first();

    expect($w)->not->toBeNull()
        ->and($w->status)->toBe(WithdrawalStatus::Draft)
        ->and($w->recorded_by)->toBe($actor->id)
        ->and($w->withdrawal_number)->toStartWith('TRK-');
});

it('records the disbursement method on a withdrawal (transfer)', function () {
    asSuperAdmin();
    $member = memberWithSukarelaBalance('200000');

    Livewire::test(CreateSavingsWithdrawal::class)
        ->fillForm([
            'member_id' => $member->id,
            'savings_type' => 'sukarela',
            'amount' => '50000',
            'withdrawal_date' => now()->toDateString(),
            'disbursement_method' => 'transfer',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SavingsWithdrawal::where('member_id', $member->id)->first()->disbursement_method)->toBe('transfer');
});

it('rejects a draft whose amount exceeds the available balance (early validation)', function () {
    asSuperAdmin();
    $member = memberWithSukarelaBalance('30000');

    Livewire::test(CreateSavingsWithdrawal::class)
        ->fillForm([
            'member_id' => $member->id,
            'savings_type' => 'sukarela',
            'amount' => '50000',
            'withdrawal_date' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasFormErrors(['amount']);
});

it('offers swp and tabungan berjangka alongside hari_raya and sukarela', function () {
    asSuperAdmin();

    expect(array_keys(SavingsWithdrawalResource::WITHDRAWAL_TYPES))
        ->toContain('hari_raya')
        ->toContain('sukarela')
        ->toContain('swp')
        ->toContain('tabungan_berjangka');
});

it('subtracts a pending refund draft from the available SWP balance (D3)', function () {
    $actor = asSuperAdmin();
    $member = Member::factory()->create();
    Loan::factory()->create(['member_id' => $member->id, 'swp_amount' => 10000]);

    expect(SavingsWithdrawalResource::availableBalance($member->id, 'swp'))->toBe('10000.00');

    SavingsWithdrawal::create([
        'idempotency_key' => (string) Str::uuid(),
        'member_id' => $member->id,
        'savings_type' => 'swp',
        'amount' => '10000',
        'withdrawal_date' => now()->toDateString(),
        'status' => 'draft',
        'is_reversal' => false,
        'recorded_by' => $actor->id,
    ]);

    expect(SavingsWithdrawalResource::availableBalance($member->id, 'swp'))->toBe('0.00');
});

it('dedupes a double-submit with the same idempotency key (D4)', function () {
    asSuperAdmin();
    $member = memberWithSukarelaBalance('200000');
    $key = (string) Str::uuid();

    $payload = [
        'idempotency_key' => $key,
        'member_id' => $member->id,
        'savings_type' => 'sukarela',
        'amount' => '50000',
        'withdrawal_date' => now()->toDateString(),
    ];

    Livewire::test(CreateSavingsWithdrawal::class)->fillForm($payload)->call('create')->assertHasNoFormErrors();
    Livewire::test(CreateSavingsWithdrawal::class)->fillForm($payload)->call('create')->assertNotified('Pencairan sudah tercatat');

    expect(SavingsWithdrawal::where('idempotency_key', $key)->count())->toBe(1);
});

it('processes the loan-refund pair together on a single transition (D2)', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    $loan = Loan::factory()->create([
        'member_id' => $member->id,
        'principal_amount' => 1000000,
        'swp_amount' => 10000,
        'term_months' => 1,
        'monthly_principal' => 1000000,
        'monthly_interest' => 6500,
        'monthly_time_deposit' => 1000,
    ]);
    $schedule = InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => 1,
        'principal_due' => 1000000,
        'interest_due' => 6500,
        'time_deposit_due' => 1000,
        'total_due' => 1007500,
    ]);
    app(LoanPaymentService::class)->pay($schedule, ['amount_paid' => 1007500], auth()->id());

    $swp = SavingsWithdrawal::where('related_loan_id', $loan->id)->where('savings_type', 'swp')->first();
    $tab = SavingsWithdrawal::where('related_loan_id', $loan->id)->where('savings_type', 'tabungan_berjangka')->first();
    expect($swp->status)->toBe(WithdrawalStatus::Draft)->and($tab->status)->toBe(WithdrawalStatus::Draft);

    // Satu aksi ACC pada salah satu record → keduanya ikut acc.
    SavingsWithdrawalResource::runTransition('approve', $swp->fresh());
    expect($swp->fresh()->status)->toBe(WithdrawalStatus::Acc)->and($tab->fresh()->status)->toBe(WithdrawalStatus::Acc);

    // Satu aksi Cairkan → keduanya cair, saldo SWP & Tab ter-net jadi 0.
    SavingsWithdrawalResource::runTransition('disburse', $swp->fresh());
    expect($swp->fresh()->status)->toBe(WithdrawalStatus::Cair)
        ->and($tab->fresh()->status)->toBe(WithdrawalStatus::Cair)
        ->and(app(SavingsBalanceService::class)->balanceByType($member, 'swp'))->toBe('0.00')
        ->and(app(SavingsBalanceService::class)->balanceByType($member, 'tabungan_berjangka'))->toBe('0.00');
});

it('shows a loan-refund pair as one representative row with the combined total', function () {
    asSuperAdmin();
    $member = Member::factory()->create();
    $loan = Loan::factory()->create([
        'member_id' => $member->id,
        'principal_amount' => 1000000,
        'swp_amount' => 10000,
        'term_months' => 1,
        'monthly_principal' => 1000000,
        'monthly_interest' => 6500,
        'monthly_time_deposit' => 1000,
    ]);
    $schedule = InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => 1,
        'principal_due' => 1000000,
        'interest_due' => 6500,
        'time_deposit_due' => 1000,
        'total_due' => 1007500,
    ]);
    app(LoanPaymentService::class)->pay($schedule, ['amount_paid' => 1007500], auth()->id());

    $swp = SavingsWithdrawal::where('related_loan_id', $loan->id)->where('savings_type', 'swp')->first();
    $tab = SavingsWithdrawal::where('related_loan_id', $loan->id)->where('savings_type', 'tabungan_berjangka')->first();

    // Hanya satu baris representatif (swp) muncul; pasangan tab disembunyikan.
    Livewire::test(ListSavingsWithdrawals::class)
        ->assertCanSeeTableRecords([$swp])
        ->assertCanNotSeeTableRecords([$tab]);

    expect(SavingsWithdrawalResource::pairTotal($swp))->toBe('11000.00')
        ->and(SavingsWithdrawalResource::pairLabel($swp))->toBe('Pengembalian Pelunasan')
        ->and(SavingsWithdrawalResource::pairAmount($swp, 'swp'))->toBe('10000.00')
        ->and(SavingsWithdrawalResource::pairAmount($swp, 'tabungan_berjangka'))->toBe('1000.00');

    // Detail: header Jenis tampil sebagai entri gabungan (bukan "SWP"),
    // dengan rincian SWP + Tabungan Berjangka di dalamnya.
    Livewire::test(ViewSavingsWithdrawal::class, ['record' => $swp->getRouteKey()])
        ->assertSee('Pengembalian Pelunasan')
        ->assertSee('Rincian Pengembalian Pelunasan')
        ->assertSee('Tabungan Berjangka');
});

// ── Per-record actions: visibility & workflow ─────────────────────────

it('approves then disburses live on one page instance without a reload', function () {
    asSuperAdmin();
    $member = memberWithSukarelaBalance('200000');
    $w = SavingsWithdrawal::factory()->type('sukarela')->status('draft')->create([
        'member_id' => $member->id, 'amount' => '50000',
    ]);

    // Satu instance Livewire dipakai untuk dua transisi berturut-turut: setelah
    // ACC, tombol "Cairkan" harus langsung tampil (record disegarkan, bukan stale).
    Livewire::test(ViewSavingsWithdrawal::class, ['record' => $w->getRouteKey()])
        ->assertActionVisible('approve')
        ->assertActionHidden('disburse')
        ->callAction('approve')
        ->assertNotified('Pencairan disetujui (ACC)')
        ->assertActionHidden('approve')
        ->assertActionVisible('disburse')
        ->callAction('disburse')
        ->assertNotified('Dana dicairkan')
        ->assertActionHidden('disburse');

    expect($w->refresh()->status)->toBe(WithdrawalStatus::Cair);
});

it('hides the disburse action while the withdrawal is still draft', function () {
    asSuperAdmin();
    $w = SavingsWithdrawal::factory()->type('sukarela')->status('draft')->create();

    Livewire::test(ViewSavingsWithdrawal::class, ['record' => $w->getRouteKey()])
        ->assertActionHidden('disburse')
        ->assertActionVisible('approve');
});

it('shows reversal only for a cair withdrawal', function () {
    asSuperAdmin();
    $cair = SavingsWithdrawal::factory()->type('sukarela')->cair()->create();
    $draft = SavingsWithdrawal::factory()->type('sukarela')->status('draft')->create();

    Livewire::test(ViewSavingsWithdrawal::class, ['record' => $cair->getRouteKey()])
        ->assertActionVisible('reverse');

    Livewire::test(ViewSavingsWithdrawal::class, ['record' => $draft->getRouteKey()])
        ->assertActionHidden('reverse');
});

it('writes an activity log when a withdrawal is disbursed', function () {
    asSuperAdmin();
    $member = memberWithSukarelaBalance('200000');
    $w = SavingsWithdrawal::factory()->type('sukarela')->status('acc')->create([
        'member_id' => $member->id, 'amount' => '50000',
    ]);

    Livewire::test(ViewSavingsWithdrawal::class, ['record' => $w->getRouteKey()])
        ->callAction('disburse');

    expect(
        Activity::where('subject_type', SavingsWithdrawal::class)
            ->where('subject_id', $w->getKey())
            ->where('event', 'disbursed')
            ->exists()
    )->toBeTrue();
});

it('records each transition in the audit trail with a readable label', function () {
    asSuperAdmin();
    $member = memberWithSukarelaBalance('200000');
    $w = SavingsWithdrawal::factory()->type('sukarela')->status('acc')->create([
        'member_id' => $member->id, 'amount' => '50000',
    ]);

    Livewire::test(ViewSavingsWithdrawal::class, ['record' => $w->getRouteKey()])
        ->callAction('disburse');

    // Event 'disbursed' tercatat sebagai aktivitas subjek (muncul di tab Audit Trail)…
    expect($w->activities()->where('event', 'disbursed')->exists())->toBeTrue();

    // …dan punya label Indonesia yang terbaca, bukan string mentah.
    expect(ActivityResource::activityEventLabel('disbursed'))->toBe('Dicairkan')
        ->and(ActivityResource::activityEventLabel('approved'))->toBe('Disetujui (ACC)')
        ->and(ActivityResource::activityEventLabel('reversal'))->toBe('Reversal');
});

it('exposes the four workflow pages', function () {
    expect(array_keys(SavingsWithdrawalResource::getPages()))
        ->toBe(['index', 'create', 'edit', 'view']);
});

// ── RBAC (D7) ─────────────────────────────────────────────────────────

it('grants create + reverse to petugas but withholds approve/disburse (D7)', function () {
    $petugas = asPetugas();

    expect($petugas->can('create_savings::withdrawal'))->toBeTrue()
        ->and($petugas->can('update_savings::withdrawal'))->toBeTrue()
        ->and($petugas->can('reverse_savings::withdrawal'))->toBeTrue()
        ->and($petugas->can('approve_savings::withdrawal'))->toBeFalse()
        ->and($petugas->can('disburse_savings::withdrawal'))->toBeFalse();
});

it('grants approve + disburse + reverse to pengurus (D7)', function () {
    $pengurus = asPengurus();

    expect($pengurus->can('approve_savings::withdrawal'))->toBeTrue()
        ->and($pengurus->can('disburse_savings::withdrawal'))->toBeTrue()
        ->and($pengurus->can('reverse_savings::withdrawal'))->toBeTrue()
        ->and($pengurus->can('create_savings::withdrawal'))->toBeTrue();
});

it('hides approve/disburse actions from petugas on the view page', function () {
    asPetugas();
    $w = SavingsWithdrawal::factory()->type('sukarela')->status('draft')->create();

    Livewire::test(ViewSavingsWithdrawal::class, ['record' => $w->getRouteKey()])
        ->assertActionHidden('approve');
});
