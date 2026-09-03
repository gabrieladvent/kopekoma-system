<?php

use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\ShoppingTransaction;
use App\Models\User;
use App\Services\LoanPaymentService;
use App\Services\SavingsBalanceService;

/**
 * Item 1a/1d (ADR Modul Simpanan, D1) — saldo computed-on-read net-of-reversal.
 */
beforeEach(function () {
    $this->service = app(SavingsBalanceService::class);
    $this->member = Member::factory()->create();
});

it('computes balance per type: deposits add, cair withdrawals subtract', function () {
    SavingsDeposit::factory()->type('pokok')->create(['member_id' => $this->member->id, 'amount' => 100000]);
    SavingsWithdrawal::factory()->type('pokok')->cair()->create(['member_id' => $this->member->id, 'amount' => 30000]);

    expect($this->service->balanceByType($this->member, 'pokok'))->toBe('70000.00');
});

it('does not subtract draft/acc withdrawals from balance', function () {
    SavingsDeposit::factory()->type('pokok')->create(['member_id' => $this->member->id, 'amount' => 100000]);
    SavingsWithdrawal::factory()->type('pokok')->status('draft')->create(['member_id' => $this->member->id, 'amount' => 50000]);
    SavingsWithdrawal::factory()->type('pokok')->status('acc')->create(['member_id' => $this->member->id, 'amount' => 20000]);

    expect($this->service->balanceByType($this->member, 'pokok'))->toBe('100000.00');
});

it('nets a deposit and its reversal to zero', function () {
    SavingsDeposit::factory()->type('sukarela')->create(['member_id' => $this->member->id, 'amount' => 80000]);
    // baris reversal: amount positif, is_reversal true → CASE mengurangi.
    SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $this->member->id,
        'amount' => 80000,
        'is_reversal' => true,
    ]);

    expect($this->service->balanceByType($this->member, 'sukarela'))->toBe('0.00');
});

it('scopes Hari Raya balance per period_year, both sides consistent', function () {
    SavingsDeposit::factory()->holiday(2026)->create(['member_id' => $this->member->id, 'amount' => 100000]);
    SavingsDeposit::factory()->holiday(2025)->create(['member_id' => $this->member->id, 'amount' => 40000]);
    SavingsWithdrawal::factory()->holiday(2026)->cair()->create(['member_id' => $this->member->id, 'amount' => 30000]);

    expect($this->service->holidayBalance($this->member, 2026))->toBe('70000.00')
        ->and($this->service->holidayBalance($this->member, 2025))->toBe('40000.00')
        // tahun tanpa deposit = 0
        ->and($this->service->holidayBalance($this->member, 2024))->toBe('0.00');
});

it('computes two-sided shopping balance: deposits minus usage', function () {
    SavingsDeposit::factory()->type('wajib_belanja')->create(['member_id' => $this->member->id, 'amount' => 100000]);
    ShoppingTransaction::factory()->create(['member_id' => $this->member->id, 'amount' => 25000]);

    expect($this->service->shoppingBalance($this->member))->toBe('75000.00')
        ->and($this->service->balanceByType($this->member, 'wajib_belanja'))->toBe('75000.00');
});

/**
 * SWP & Tabungan Berjangka kini simpanan sungguhan — saldonya `Σ setoran −
 * Σ penarikan cair`, rumus yang sama dengan pokok/wajib/sukarela. Dua rumus
 * khusus lama (`SUM(loans.swp_amount)` dan `monthly_time_deposit × jumlah
 * angsuran terbayar`) sudah dicabut; yang tersisa hanya pintu masuknya.
 */
it('computes swp balance like any other savings type', function () {
    // Pintu masuknya pencairan pinjaman — setorannya lahir bersama pinjamannya.
    Loan::factory()->create(['member_id' => $this->member->id, 'swp_amount' => 120000]);

    expect($this->service->balanceByType($this->member, 'swp'))->toBe('120000.00');

    // Dan riwayatnya ada — bukan cuma angka hasil hitungan.
    $deposit = SavingsDeposit::where('member_id', $this->member->id)
        ->where('savings_type', 'swp')->sole();

    expect($deposit->amount)->toBe('120000.00')
        ->and($deposit->transaction_number)->not->toBeEmpty();

    SavingsWithdrawal::factory()->type('swp')->cair()->create([
        'member_id' => $this->member->id, 'amount' => 120000,
    ]);

    expect($this->service->balanceByType($this->member, 'swp'))->toBe('0.00');
});

it('computes tabungan_berjangka from its own deposit rows', function () {
    $loan = Loan::factory()->create([
        'member_id' => $this->member->id,
        'principal_amount' => 3000000,
        'term_months' => 3,
        'monthly_principal' => 1000000,
        'monthly_interest' => 0,
        'monthly_time_deposit' => 12000,
        'swp_amount' => 0,
    ]);

    $schedules = collect(range(1, 3))->map(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 0,
        'time_deposit_due' => 12000,
        'total_due' => 1012000,
    ]));

    $payments = app(LoanPaymentService::class);
    $user = User::factory()->create();

    foreach ($schedules as $schedule) {
        $payments->pay($schedule->fresh(), ['amount_paid' => 1012000], $user->id);
    }

    expect($this->service->balanceByType($this->member, 'tabungan_berjangka'))->toBe('36000.00')
        ->and(SavingsDeposit::where('member_id', $this->member->id)
            ->where('savings_type', 'tabungan_berjangka')->count())->toBe(3);

    SavingsWithdrawal::factory()->type('tabungan_berjangka')->cair()->create([
        'member_id' => $this->member->id, 'amount' => 36000,
    ]);

    expect($this->service->balanceByType($this->member, 'tabungan_berjangka'))->toBe('0.00');
});

/** Pinjaman lunas TIDAK lagi mengembalikan apa pun — uangnya tetap di jenisnya. */
it('keeps swp and tabungan berjangka in place once the loan is paid off', function () {
    $loan = Loan::factory()->create([
        'member_id' => $this->member->id,
        'principal_amount' => 1000000,
        'term_months' => 1,
        'monthly_principal' => 1000000,
        'monthly_interest' => 0,
        'monthly_time_deposit' => 12000,
        'swp_amount' => 10000,
    ]);

    $schedule = InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => 1,
        'principal_due' => 1000000,
        'interest_due' => 0,
        'time_deposit_due' => 12000,
        'total_due' => 1012000,
    ]);

    app(LoanPaymentService::class)->pay($schedule, ['amount_paid' => 1012000], User::factory()->create()->id);

    expect($loan->fresh()->status->value)->toBe('Lunas')
        ->and($this->service->balanceByType($this->member, 'swp'))->toBe('10000.00')
        ->and($this->service->balanceByType($this->member, 'tabungan_berjangka'))->toBe('12000.00')
        // Tak ada draft pencairan yang terbit sendiri.
        ->and(SavingsWithdrawal::where('member_id', $this->member->id)->count())->toBe(0);
});

it('requires a year for hari_raya via balanceByType', function () {
    expect(fn () => $this->service->balanceByType($this->member, 'hari_raya'))
        ->toThrow(InvalidArgumentException::class);
});

it('gates canWithdraw on balance and positive amount', function () {
    SavingsDeposit::factory()->type('pokok')->create(['member_id' => $this->member->id, 'amount' => 70000]);

    expect($this->service->canWithdraw($this->member, 'pokok', '70000'))->toBeTrue()
        ->and($this->service->canWithdraw($this->member, 'pokok', '70000.01'))->toBeFalse()
        ->and($this->service->canWithdraw($this->member, 'pokok', '0'))->toBeFalse();
});

it('gates canSpendShopping on shopping balance', function () {
    SavingsDeposit::factory()->type('wajib_belanja')->create(['member_id' => $this->member->id, 'amount' => 75000]);

    expect($this->service->canSpendShopping($this->member, '75000'))->toBeTrue()
        ->and($this->service->canSpendShopping($this->member, '80000'))->toBeFalse();
});

it('returns all balances grouped, including hari_raya per year', function () {
    SavingsDeposit::factory()->type('pokok')->create(['member_id' => $this->member->id, 'amount' => 100000]);
    SavingsDeposit::factory()->type('wajib_belanja')->create(['member_id' => $this->member->id, 'amount' => 50000]);
    ShoppingTransaction::factory()->create(['member_id' => $this->member->id, 'amount' => 20000]);
    SavingsDeposit::factory()->holiday(2026)->create(['member_id' => $this->member->id, 'amount' => 60000]);

    $all = $this->service->allBalances($this->member);

    expect($all['pokok'])->toBe('100000.00')
        ->and($all['wajib_belanja'])->toBe('30000.00')
        ->and($all['hari_raya'])->toBe([2026 => '60000.00']);
});
