<?php

use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\SavingsWithdrawalResource;
use App\Filament\Resources\SavingsWithdrawalResource\Pages\CreateSavingsWithdrawal;
use App\Filament\Resources\SavingsWithdrawalResource\Pages\ListSavingsWithdrawals;
use App\Filament\Resources\SavingsWithdrawalResource\Pages\ViewSavingsWithdrawal;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
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
        ->and($w->status)->toBe('draft')
        ->and($w->recorded_by)->toBe($actor->id)
        ->and($w->withdrawal_number)->toStartWith('TRK-');
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

it('only offers hari_raya and sukarela as withdrawal types (D8)', function () {
    asSuperAdmin();

    expect(array_keys(SavingsWithdrawalResource::WITHDRAWAL_TYPES))
        ->toBe(['hari_raya', 'sukarela']);
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

    expect($w->refresh()->status)->toBe('cair');
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
