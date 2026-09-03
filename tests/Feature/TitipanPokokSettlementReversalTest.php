<?php

use App\Enums\LoanStatus;
use App\Exceptions\CannotReverseTransaction;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use App\Services\LoanPaymentService;
use Spatie\Activitylog\Models\Activity;

/**
 * Lubang yang ditemukan security review atas ADR 2026-08-28 — guard pembatalan
 * (item 1j) buta terhadap Titipan Pokok yang sudah dimakan Pelunasan Dipercepat.
 *
 * Rangkaiannya: anggota bertitipan melunasi dipercepat → potongan titipan
 * mengecilkan jumlah pelunasan → pinjaman Lunas. Petugas lalu membatalkan
 * setoran yang MEMBUAT titipan itu, membiarkan baris pelunasannya berdiri.
 * `overpaymentCredit()` mengecualikan baris pelunasan, jadi saldo baris biasa
 * kembali 0 dan guard tak melihat apa pun — pembatalan lolos, potongan pada
 * pelunasan sudah terlanjur diterima, dan koperasi menanggung selisihnya.
 *
 * Yang membuatnya berbahaya bukan cuma uangnya: jejak `pembatalan_angsuran`
 * mencatat `credit_before = credit_after = 0.00`, jadi pemeriksa yang membuka
 * log justru melihat transaksi yang tampak tak menggerakkan apa pun.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(LoanPaymentService::class);

    $this->loan = Loan::factory()->create([
        'member_id' => Member::factory()->create()->id,
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 5000000,
        'term_months' => 5,
        'monthly_principal' => 1000000,
        'monthly_interest' => 40000,
        'monthly_time_deposit' => 10000,
    ]);

    $this->schedules = collect(range(1, 5))->map(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $this->loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 40000,
        'time_deposit_due' => 10000,
        'total_due' => 1050000,
    ]));
});

/**
 * Setoran 2.100.000 di angsuran #1 → titipan 1.050.000, lalu pelunasan.
 * Mengembalikan baris setoran yang membuat titipan.
 */
function setorLaluLunasi(): Installment
{
    $overpayment = test()->service->pay(
        test()->schedules[0],
        ['amount_paid' => 2100000],
        test()->user->id,
    );

    $loan = test()->loan->fresh();

    test()->service->settleEarly($loan, ['amount_paid' => $loan->payoffAmount()], test()->user->id);

    return $overpayment;
}

it('discounts the payoff by the titipan and records it on the settlement row', function () {
    $overpayment = setorLaluLunasi();

    $settlement = Installment::where('loan_id', $this->loan->id)
        ->where('is_settlement', true)
        ->firstOrFail();

    // Sisa pokok setelah 1 angsuran = 4.000.000; payoff kontrak = 4.040.000;
    // titipan 1.050.000 dipotong penuh (masih di bawah sisa pokok).
    expect($settlement->amount_paid)->toBe('2990000.00')
        ->and($settlement->credit_applied)->toBe('1050000.00')
        ->and($this->loan->fresh()->settlementCreditApplied())->toBe('1050000.00')
        ->and($overpayment->fresh()->credit_applied)->toBe('0.00');
});

it('refuses to reverse the payment whose titipan the settlement already spent', function () {
    $overpayment = setorLaluLunasi();

    expect(fn () => $this->service->reverse($overpayment, 'salah input', $this->user->id))
        ->toThrow(CannotReverseTransaction::class);

    // Ditolak = seluruh transaksi dibatalkan: tak ada baris pembalik yang
    // tertinggal, pinjaman tetap Lunas, dan tak ada sisa pokok yang muncul lagi.
    expect(Installment::where('loan_id', $this->loan->id)->where('is_reversal', true)->count())->toBe(0)
        ->and($this->loan->fresh()->status)->toBe(LoanStatus::Lunas)
        ->and($this->loan->fresh()->remainingPrincipal())->toBe('0.00');
});

it('names the settlement row as the blocker, not an ordinary installment', function () {
    $overpayment = setorLaluLunasi();

    $settlement = Installment::where('loan_id', $this->loan->id)
        ->where('is_settlement', true)
        ->firstOrFail();

    try {
        $this->service->reverse($overpayment, 'salah input', $this->user->id);
    } catch (CannotReverseTransaction $e) {
        expect($e->getMessage())->toContain($settlement->installment_number);
    }
});

/** Jejak penolakan wajib menyebut titipan yang tertahan — itu inti sebabnya. */
it('logs the titipan held by the settlement when it refuses', function () {
    $overpayment = setorLaluLunasi();

    try {
        $this->service->reverse($overpayment, 'salah input', $this->user->id);
    } catch (CannotReverseTransaction) {
        // ditolak sesuai harapan
    }

    $log = Activity::where('event', 'pembatalan_ditolak')->latest('id')->firstOrFail();

    expect($log->properties['attributes']['credit_in_settlement'])->toBe('1050000.00')
        ->and($log->properties['attributes']['credit_after'])->toBe('-1050000.00');
});

/**
 * Urutan yang BENAR tidak boleh ikut terhalang: batalkan pelunasannya dulu,
 * lalu setorannya. Guard yang menolak keduanya sama saja dengan mengunci
 * pinjaman selamanya.
 */
it('allows the reversal once the settlement itself has been reversed', function () {
    $overpayment = setorLaluLunasi();

    $settlement = Installment::where('loan_id', $this->loan->id)
        ->where('is_settlement', true)
        ->firstOrFail();

    $this->service->reverse($settlement, 'batalkan pelunasan', $this->user->id);

    $loan = $this->loan->fresh();

    expect($loan->status)->toBe(LoanStatus::Cair)
        ->and($loan->settlementCreditApplied())->toBe('0.00')
        ->and($loan->overpaymentCredit())->toBe('1050000.00');

    $this->service->reverse($overpayment->fresh(), 'salah input', $this->user->id);

    expect($this->loan->fresh()->overpaymentCredit())->toBe('0.00');
});

/** Pinjaman tanpa pelunasan sama sekali tidak boleh terkena pengurangan apa pun. */
it('leaves an ordinary reversal untouched when no settlement exists', function () {
    $overpayment = $this->service->pay($this->schedules[0], ['amount_paid' => 2100000], $this->user->id);

    $loan = $this->loan->fresh();

    expect($loan->settlementCreditApplied())->toBe('0.00')
        ->and($loan->overpaymentCreditNetOfSettlement())->toBe($loan->overpaymentCredit());

    $this->service->reverse($overpayment, 'salah input', $this->user->id);

    expect($this->loan->fresh()->overpaymentCredit())->toBe('0.00');
});
