<?php

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\CannotProcessPayment;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use App\Services\LoanPaymentService;
use App\Services\SavingsBalanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

/**
 * ADR 2026-07-22 (Bayar Angsuran dari Saldo Simpanan) — item 1c.
 * Jalur debit `sukarela` di LoanPaymentService::pay(): otorisasi Pengurus,
 * consent WAJIB, tepat-tagihan, canWithdraw, debit berpasangan ber-atribusi.
 */

/** Pinjaman jangka panjang + N jadwal identik (tagihan 1.007.500/jadwal). */
function savingsTestLoan(string $memberId, int $schedules = 1, float $swp = 10000): array
{
    $loan = Loan::factory()->create([
        'member_id' => $memberId,
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 1000000,
        'swp_amount' => $swp,
        'term_months' => $schedules,
        'monthly_principal' => 1000000,
        'monthly_interest' => 6500,
        'monthly_time_deposit' => 1000,
        'disbursement_method' => 'tunai',
    ]);

    $rows = collect(range(1, $schedules))->map(fn ($seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 6500,
        'time_deposit_due' => 1000,
        'total_due' => 1007500,
    ]));

    return [$loan, $rows];
}
beforeEach(function () {
    $this->service = app(LoanPaymentService::class);
    $this->balances = app(SavingsBalanceService::class);
    $this->member = Member::factory()->create();

    // Permission dibuat inline (wiring ke role Pengurus = item 1e).
    Permission::firstOrCreate(['name' => 'pay_installment_from_savings', 'guard_name' => 'web']);
    $this->pengurus = User::factory()->create();
    $this->pengurus->givePermissionTo('pay_installment_from_savings');

    Storage::fake(config('media-library.disk_name'));
});

/** Isi saldo Sukarela anggota. */
function fundSukarela(string $memberId, int $amount): void
{
    SavingsDeposit::factory()->type('sukarela')->create([
        'member_id' => $memberId,
        'amount' => $amount,
    ]);
}

/** @return array{amount_paid:int, payment_method:string} */
function savingsPayment(): array
{
    return ['amount_paid' => 1007500, 'payment_method' => 'saldo_simpanan'];
}

function consentFile(): UploadedFile
{
    return UploadedFile::fake()->create('consent.pdf', 100, 'application/pdf');
}

it('debits sukarela and records a paired Cair withdrawal with attribution + installment_id', function () {
    [$loan, $rows] = savingsTestLoan($this->member->id, schedules: 2);
    fundSukarela($this->member->id, 2_000_000);

    $inst = $this->service->pay($rows[0], savingsPayment(), $this->pengurus->id, consentFile());

    $debit = SavingsWithdrawal::where('installment_id', $inst->id)->first();

    expect($inst->payment_method)->toBe('saldo_simpanan')
        ->and($debit)->not->toBeNull()
        ->and($debit->savings_type)->toBe('sukarela')
        ->and($debit->amount)->toBe('1007500.00')
        ->and($debit->status)->toBe(WithdrawalStatus::Cair)
        ->and($debit->disbursement_method)->toBe('internal')
        ->and((int) $debit->approved_by)->toBe($this->pengurus->id)
        ->and($debit->approved_at)->not->toBeNull()
        ->and($debit->disbursed_at)->not->toBeNull()
        ->and($debit->related_loan_id)->toBeNull(); // penanda pasangan BUKAN related_loan_id
});

it('reduces sukarela balance by exactly the bill', function () {
    [$loan, $rows] = savingsTestLoan($this->member->id, schedules: 2);
    fundSukarela($this->member->id, 2_000_000);

    $this->service->pay($rows[0], savingsPayment(), $this->pengurus->id, consentFile());

    expect($this->balances->balanceByType($this->member, 'sukarela'))->toBe('992500.00');
});

it('stores the consent proof as media on the installment', function () {
    [$loan, $rows] = savingsTestLoan($this->member->id, schedules: 2);
    fundSukarela($this->member->id, 2_000_000);

    $inst = $this->service->pay($rows[0], savingsPayment(), $this->pengurus->id, consentFile());

    expect($inst->fresh()->hasMedia('bukti'))->toBeTrue();
});

it('rejects the debit when sukarela balance is insufficient (atomic, no records)', function () {
    [$loan, $rows] = savingsTestLoan($this->member->id, schedules: 2);
    fundSukarela($this->member->id, 100_000); // < tagihan 1.007.500

    expect(fn () => $this->service->pay($rows[0], savingsPayment(), $this->pengurus->id, consentFile()))
        ->toThrow(CannotProcessPayment::class);

    expect(Installment::where('loan_id', $loan->id)->exists())->toBeFalse()
        ->and(SavingsWithdrawal::where('member_id', $this->member->id)->exists())->toBeFalse()
        ->and($rows[0]->fresh()->status)->toBe(InstallmentScheduleStatus::BelumBayar);
});

it('rejects the debit when the consent proof is missing', function () {
    [$loan, $rows] = savingsTestLoan($this->member->id, schedules: 2);
    fundSukarela($this->member->id, 2_000_000);

    expect(fn () => $this->service->pay($rows[0], savingsPayment(), $this->pengurus->id, null))
        ->toThrow(CannotProcessPayment::class);

    expect(Installment::where('loan_id', $loan->id)->exists())->toBeFalse();
});

it('rejects overpayment from savings (must equal the bill exactly)', function () {
    [$loan, $rows] = savingsTestLoan($this->member->id, schedules: 2);
    fundSukarela($this->member->id, 2_000_000);

    expect(fn () => $this->service->pay(
        $rows[0],
        ['amount_paid' => 1_100_000, 'payment_method' => 'saldo_simpanan'],
        $this->pengurus->id,
        consentFile(),
    ))->toThrow(CannotProcessPayment::class);

    expect(SavingsWithdrawal::where('member_id', $this->member->id)->exists())->toBeFalse();
});

it('denies a user lacking pay_installment_from_savings (403), no records', function () {
    [$loan, $rows] = savingsTestLoan($this->member->id, schedules: 2);
    fundSukarela($this->member->id, 2_000_000);
    $petugas = User::factory()->create(); // tanpa permission

    expect(fn () => $this->service->pay($rows[0], savingsPayment(), $petugas->id, consentFile()))
        ->toThrow(AuthorizationException::class);

    expect(Installment::where('loan_id', $loan->id)->exists())->toBeFalse()
        ->and(SavingsWithdrawal::where('member_id', $this->member->id)->exists())->toBeFalse();
});

it('logs the debit_simpanan_angsuran event with attribution', function () {
    [$loan, $rows] = savingsTestLoan($this->member->id, schedules: 2);
    fundSukarela($this->member->id, 2_000_000);

    $inst = $this->service->pay($rows[0], savingsPayment(), $this->pengurus->id, consentFile());

    $log = Activity::where('event', 'debit_simpanan_angsuran')->first();

    expect($log)->not->toBeNull()
        ->and($log->properties['loan_id'])->toBe($loan->id)
        ->and($log->properties['savings_type'])->toBe('sukarela')
        ->and((int) $log->properties['approved_by'])->toBe($this->pengurus->id);
});

it('does NOT treat the paired debit as a pelunasan refund: final payment still creates SWP + Tab refunds', function () {
    // Bayar angsuran terakhir dari sukarela → pinjaman Lunas. Refund SWP/Tab
    // (via related_loan_id) tetap dibuat; debit (via installment_id) tak ter-hasActiveRefund.
    [$loan, $rows] = savingsTestLoan($this->member->id, schedules: 1, swp: 10000);
    fundSukarela($this->member->id, 2_000_000);

    $inst = $this->service->pay($rows[0], savingsPayment(), $this->pengurus->id, consentFile());

    expect($loan->fresh()->status)->toBe(LoanStatus::Lunas);

    $swp = SavingsWithdrawal::where('related_loan_id', $loan->id)->where('savings_type', 'swp')->first();
    $tab = SavingsWithdrawal::where('related_loan_id', $loan->id)->where('savings_type', 'tabungan_berjangka')->first();
    $debit = SavingsWithdrawal::where('installment_id', $inst->id)->first();

    expect($swp)->not->toBeNull()
        ->and($swp->status)->toBe(WithdrawalStatus::Draft)
        ->and($tab)->not->toBeNull()
        ->and($debit)->not->toBeNull()
        ->and($debit->related_loan_id)->toBeNull();
});
