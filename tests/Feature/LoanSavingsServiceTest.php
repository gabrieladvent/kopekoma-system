<?php

use App\Enums\LoanStatus;
use App\Livewire\Savings\MemberSavingsDetail;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use App\Services\LoanPaymentService;
use App\Services\SavingsBalanceService;
use App\Services\WithdrawalWorkflow;
use Livewire\Livewire;

/**
 * SWP & Tabungan Berjangka sebagai simpanan sungguhan.
 *
 * Menggantikan keputusan D8 (ADR 2026-06-19): pelunasan tak lagi menerbitkan
 * pengembalian otomatis. Keduanya kini punya baris setoran sendiri, tetap di
 * jenisnya saat pinjaman lunas, dan ditarik anggota lewat penarikan biasa.
 *
 * Yang dikunci di sini: pintu masuknya benar, riwayatnya ada, pembalikannya
 * berpasangan, dan saldonya tak lagi hilang sendiri saat lunas.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->member = Member::factory()->create(['status' => 'Aktif']);
    $this->balances = app(SavingsBalanceService::class);
});

/** Pinjaman 3.000.000 / 3 bulan — tagihan 1.012.000, SWP 30.000, tab 12.000. */
function loanWithSavings(string $memberId, int $userId): array
{
    $loan = Loan::factory()->create([
        'member_id' => $memberId,
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 3000000,
        'term_months' => 3,
        'monthly_principal' => 1000000,
        'monthly_interest' => 0,
        'monthly_time_deposit' => 12000,
        'swp_amount' => 30000,
        'recorded_by' => $userId,
    ]);

    $schedules = collect(range(1, 3))->map(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 0,
        'time_deposit_due' => 12000,
        'total_due' => 1012000,
    ]));

    return [$loan, $schedules];
}

it('creates the swp deposit when the loan is disbursed', function () {
    [$loan] = loanWithSavings($this->member->id, $this->user->id);

    $deposit = SavingsDeposit::where('member_id', $this->member->id)
        ->where('savings_type', 'swp')->sole();

    expect($deposit->amount)->toBe('30000.00')
        ->and($deposit->reference_number)->toBe($loan->loan_number)
        ->and($deposit->transaction_number)->not->toBeEmpty()
        ->and($this->balances->balanceByType($this->member, 'swp'))->toBe('30000.00');
});

it('creates one tabungan berjangka deposit per installment paid', function () {
    [, $schedules] = loanWithSavings($this->member->id, $this->user->id);

    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1012000], $this->user->id);

    $deposit = SavingsDeposit::where('member_id', $this->member->id)
        ->where('savings_type', 'tabungan_berjangka')->sole();

    expect($deposit->amount)->toBe('12000.00')
        ->and($deposit->reference_number)->toStartWith('ANG-')
        ->and($this->balances->balanceByType($this->member, 'tabungan_berjangka'))->toBe('12000.00');
});

/**
 * Inti perubahan: pinjaman lunas TIDAK memindahkan apa pun. Tak ada draft
 * pencairan, tak ada pengalihan ke Sukarela — uangnya tetap di jenisnya.
 */
it('leaves both balances untouched when the loan is paid off', function () {
    [$loan, $schedules] = loanWithSavings($this->member->id, $this->user->id);

    $payments = app(LoanPaymentService::class);

    foreach ($schedules as $schedule) {
        $payments->pay($schedule->fresh(), ['amount_paid' => 1012000], $this->user->id);
    }

    expect($loan->fresh()->status)->toBe(LoanStatus::Lunas)
        ->and($this->balances->balanceByType($this->member, 'swp'))->toBe('30000.00')
        ->and($this->balances->balanceByType($this->member, 'tabungan_berjangka'))->toBe('36000.00')
        ->and($this->balances->balanceByType($this->member, 'sukarela'))->toBe('0.00')
        ->and(SavingsWithdrawal::where('member_id', $this->member->id)->count())->toBe(0);
});

/** Anggota mengambilnya sendiri lewat penarikan biasa — gerbangnya tetap ada. */
it('lets the member withdraw them through the ordinary withdrawal flow', function () {
    [, $schedules] = loanWithSavings($this->member->id, $this->user->id);

    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1012000], $this->user->id);

    $withdrawal = SavingsWithdrawal::factory()->type('swp')->status('draft')->create([
        'member_id' => $this->member->id,
        'amount' => 30000,
        'is_reversal' => false,
    ]);

    $workflow = app(WithdrawalWorkflow::class);
    $workflow->disburse($workflow->approve($withdrawal, $this->user->id), $this->user->id);

    expect($this->balances->balanceByType($this->member, 'swp'))->toBe('0.00')
        ->and($this->balances->balanceByType($this->member, 'tabungan_berjangka'))->toBe('12000.00');
});

it('reverses the tabungan berjangka deposit when the installment is reversed', function () {
    [, $schedules] = loanWithSavings($this->member->id, $this->user->id);

    $installment = app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1012000], $this->user->id);

    app(LoanPaymentService::class)->reverse($installment, 'salah input', $this->user->id);

    expect($this->balances->balanceByType($this->member, 'tabungan_berjangka'))->toBe('0.00')
        // Baris asli TIDAK dihapus — ia dinetralkan baris-lawan, jadi riwayatnya utuh.
        ->and(SavingsDeposit::where('member_id', $this->member->id)
            ->where('savings_type', 'tabungan_berjangka')->count())->toBe(2);
});

it('reverses the swp deposit when the loan is cancelled', function () {
    [$loan] = loanWithSavings($this->member->id, $this->user->id);

    expect($this->balances->balanceByType($this->member, 'swp'))->toBe('30000.00');

    $loan->update(['status' => LoanStatus::Dibatalkan]);

    expect($this->balances->balanceByType($this->member, 'swp'))->toBe('0.00')
        ->and(SavingsDeposit::where('member_id', $this->member->id)
            ->where('savings_type', 'swp')->count())->toBe(2);
});

/** Membatalkan dua kali tak boleh menumpuk dua baris-lawan atas satu setoran. */
it('does not stack reversals when the loan is cancelled twice', function () {
    [$loan] = loanWithSavings($this->member->id, $this->user->id);

    $loan->update(['status' => LoanStatus::Dibatalkan]);
    $loan->fresh()->update(['status' => LoanStatus::Dibatalkan]);

    expect(SavingsDeposit::where('member_id', $this->member->id)
        ->where('savings_type', 'swp')->count())->toBe(2)
        ->and($this->balances->balanceByType($this->member, 'swp'))->toBe('0.00');
});

/** Pelunasan dipercepat tak mengakru tabungan bulan sisa — barisnya pun tak ada. */
it('accrues no tabungan berjangka for the settlement row', function () {
    [$loan, $schedules] = loanWithSavings($this->member->id, $this->user->id);

    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1012000], $this->user->id);

    $fresh = $loan->fresh();
    app(LoanPaymentService::class)->settleEarly($fresh, ['amount_paid' => $fresh->payoffAmount()], $this->user->id);

    expect($loan->fresh()->status)->toBe(LoanStatus::Lunas)
        // Satu angsuran biasa dibayar → satu baris tabungan, bukan tiga.
        ->and($this->balances->balanceByType($this->member, 'tabungan_berjangka'))->toBe('12000.00')
        ->and($this->balances->balanceByType($this->member, 'swp'))->toBe('30000.00');
});

/** Riwayatnya terlihat di buku mutasi anggota — itulah "history" yang diminta. */
it('shows both in the member savings ledger with their real origin', function () {
    asSuperAdmin();

    [, $schedules] = loanWithSavings($this->member->id, $this->user->id);
    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1012000], $this->user->id);

    $ledger = collect(
        Livewire::test(MemberSavingsDetail::class, ['member' => $this->member])->viewData('ledger')
    );

    expect($ledger->firstWhere('type', 'swp')['description'])->toBe('Potongan SWP saat pencairan pinjaman')
        ->and($ledger->firstWhere('type', 'tabungan_berjangka')['description'])->toBe('Tabungan Berjangka dari angsuran');
});

/** Keduanya kini ikut total saldo anggota — mereka memang simpanan miliknya. */
it('counts both in the member total balance', function () {
    [, $schedules] = loanWithSavings($this->member->id, $this->user->id);

    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1012000], $this->user->id);

    expect($this->balances->totalBalance($this->member))->toBe('42000.00');
});
