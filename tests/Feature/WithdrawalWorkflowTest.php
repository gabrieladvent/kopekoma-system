<?php

use App\Actions\ReverseTransaction;
use App\Exceptions\CannotProcessWithdrawal;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Services\SavingsBalanceService;
use App\Services\WithdrawalWorkflow;

beforeEach(function () {
    $this->workflow = app(WithdrawalWorkflow::class);
    $this->balances = app(SavingsBalanceService::class);
});

/**
 * Buat anggota dengan saldo sukarela tertentu (lewat setoran nyata).
 */
function memberWithSukarela(string $amount): Member
{
    $member = Member::factory()->create();
    SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $member->id,
        'amount' => $amount,
    ]);

    return $member;
}

// ── State machine (D10) ───────────────────────────────────────────────

it('approves a draft withdrawal (draft → acc) and records the approver', function () {
    $actor = asSuperAdmin();
    $member = memberWithSukarela('100000');
    $w = SavingsWithdrawal::factory()->type('sukarela')->status('draft')->create([
        'member_id' => $member->id,
        'amount' => '50000',
    ]);

    $result = $this->workflow->approve($w, $actor->id);

    expect($result->status)->toBe('acc')
        ->and($result->approved_by)->toBe($actor->id)
        ->and($result->approved_at)->not->toBeNull();
});

it('disburses an approved withdrawal (acc → cair) and reduces the balance', function () {
    $member = memberWithSukarela('100000');
    $w = SavingsWithdrawal::factory()->type('sukarela')->status('acc')->create([
        'member_id' => $member->id,
        'amount' => '40000',
    ]);

    expect($this->balances->balanceByType($member, 'sukarela'))->toBe('100000.00');

    $result = $this->workflow->disburse($w);

    expect($result->status)->toBe('cair')
        ->and($result->disbursed_at)->not->toBeNull()
        ->and($this->balances->balanceByType($member, 'sukarela'))->toBe('60000.00');
});

it('does not reduce the balance while still draft or acc', function () {
    $member = memberWithSukarela('100000');
    SavingsWithdrawal::factory()->type('sukarela')->status('draft')->create([
        'member_id' => $member->id, 'amount' => '30000',
    ]);
    SavingsWithdrawal::factory()->type('sukarela')->status('acc')->create([
        'member_id' => $member->id, 'amount' => '30000',
    ]);

    expect($this->balances->balanceByType($member, 'sukarela'))->toBe('100000.00');
});

it('rejects a withdrawal (draft → ditolak) without touching the balance', function () {
    $member = memberWithSukarela('100000');
    $w = SavingsWithdrawal::factory()->type('sukarela')->status('draft')->create([
        'member_id' => $member->id, 'amount' => '50000',
    ]);

    $result = $this->workflow->reject($w);

    expect($result->status)->toBe('ditolak')
        ->and($this->balances->balanceByType($member, 'sukarela'))->toBe('100000.00');
});

it('forbids illegal transitions: cannot disburse a draft directly', function () {
    $member = memberWithSukarela('100000');
    $w = SavingsWithdrawal::factory()->type('sukarela')->status('draft')->create([
        'member_id' => $member->id, 'amount' => '50000',
    ]);

    expect(fn () => $this->workflow->disburse($w))
        ->toThrow(CannotProcessWithdrawal::class);
});

it('forbids reopening a terminal status: cannot approve a ditolak', function () {
    $member = memberWithSukarela('100000');
    $w = SavingsWithdrawal::factory()->type('sukarela')->status('ditolak')->create([
        'member_id' => $member->id, 'amount' => '50000',
    ]);

    expect(fn () => $this->workflow->approve($w))
        ->toThrow(CannotProcessWithdrawal::class);
});

it('forbids re-disbursing a cair withdrawal (cair is terminal)', function () {
    $member = memberWithSukarela('100000');
    $w = SavingsWithdrawal::factory()->type('sukarela')->cair()->create([
        'member_id' => $member->id, 'amount' => '50000',
    ]);

    expect(fn () => $this->workflow->disburse($w))
        ->toThrow(CannotProcessWithdrawal::class);
});

// ── Balance guards (D1/D10) ───────────────────────────────────────────

it('refuses to disburse when the balance is insufficient', function () {
    $member = memberWithSukarela('30000');
    $w = SavingsWithdrawal::factory()->type('sukarela')->status('acc')->create([
        'member_id' => $member->id, 'amount' => '50000',
    ]);

    expect(fn () => $this->workflow->disburse($w))
        ->toThrow(CannotProcessWithdrawal::class);

    expect($w->refresh()->status)->toBe('acc'); // tidak berubah
});

it('prevents over-draw across two sequential disbursements (serialize guard logic)', function () {
    $member = memberWithSukarela('100000');
    $a = SavingsWithdrawal::factory()->type('sukarela')->status('acc')->create([
        'member_id' => $member->id, 'amount' => '60000',
    ]);
    $b = SavingsWithdrawal::factory()->type('sukarela')->status('acc')->create([
        'member_id' => $member->id, 'amount' => '60000',
    ]);

    $this->workflow->disburse($a); // sisa 40000

    expect(fn () => $this->workflow->disburse($b)) // 60000 > 40000
        ->toThrow(CannotProcessWithdrawal::class);

    expect($this->balances->balanceByType($member, 'sukarela'))->toBe('40000.00');
});

// ── Hari Raya per-tahun (D1) ──────────────────────────────────────────

it('disburses a hari_raya withdrawal scoped to its program year', function () {
    $member = Member::factory()->create();
    SavingsDeposit::factory()->type('hari_raya')->create([
        'member_id' => $member->id,
        'amount' => '70000',
        'period_month' => '2026-01-01',
    ]);

    $w = SavingsWithdrawal::factory()->holiday(2026)->status('acc')->create([
        'member_id' => $member->id, 'amount' => '70000',
    ]);

    $this->workflow->disburse($w);

    expect($this->balances->holidayBalance($member, 2026))->toBe('0.00');
});

it('refuses a hari_raya disbursement charged against a year with no balance', function () {
    $member = Member::factory()->create();
    SavingsDeposit::factory()->type('hari_raya')->create([
        'member_id' => $member->id, 'amount' => '70000', 'period_month' => '2026-01-01',
    ]);

    // Tagihan ke tahun 2027 yang tak punya saldo.
    $w = SavingsWithdrawal::factory()->holiday(2027)->status('acc')->create([
        'member_id' => $member->id, 'amount' => '70000',
    ]);

    expect(fn () => $this->workflow->disburse($w))
        ->toThrow(CannotProcessWithdrawal::class);
});

it('restores the hari_raya year balance after reversing a cair withdrawal', function () {
    $actor = asSuperAdmin(); // reversal mencatat causer → butuh aktor login
    $member = Member::factory()->create();
    SavingsDeposit::factory()->type('hari_raya')->create([
        'member_id' => $member->id, 'amount' => '70000', 'period_month' => '2026-01-01',
    ]);
    $w = SavingsWithdrawal::factory()->holiday(2026)->cair()->create([
        'member_id' => $member->id, 'amount' => '70000',
    ]);

    expect($this->balances->holidayBalance($member, 2026))->toBe('0.00');

    app(ReverseTransaction::class)($w, 'koreksi salah input pencairan');

    // Baris-lawan mengembalikan saldo tahun 2026 ke nilai semula (reverseClone
    // menyalin period_year), bukan ke tahun lain.
    expect($this->balances->holidayBalance($member, 2026))->toBe('70000.00');
});

// ── Whitelist jenis (D8) ──────────────────────────────────────────────

it('refuses to process an unsupported savings type', function () {
    $member = Member::factory()->create();
    $w = SavingsWithdrawal::factory()->type('pokok')->status('acc')->create([
        'member_id' => $member->id, 'amount' => '10000',
    ]);

    expect(fn () => $this->workflow->disburse($w))
        ->toThrow(CannotProcessWithdrawal::class);
});
