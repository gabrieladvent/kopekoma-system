<?php

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Exceptions\CannotProcessPayment;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\User;
use App\Services\LoanPaymentService;

/**
 * Helper Titipan Pokok di model Loan (ADR 2026-08-28) — item 1a (saldo turunan),
 * 1b (tagihan efektif), 1c dan 1i (jumlah pelunasan).
 *
 * 1a: patokannya tagihan KONTRAK, bukan efektif — memakai efektif membuat saldo
 * membengkak tiap bulan (kekeliruan v2). Plus pemulihan otomatis setelah
 * pembatalan, pengecualian baris pelunasan, guard status Lunas/Dibatalkan.
 *
 * 1b: titipan hanya memotong POKOK, dibatasi `principal_due` — batas itulah yang
 * menjaga jasa tetap tertagih dan akrual Tabungan Berjangka tetap utuh.
 *
 * 1c/1i: potongan pada pelunasan dibatasi sisa pokok, dan sisa titipan yang tak
 * terpakai dilimpahkan ke Sukarela — tidak hangus saat status jadi Lunas.
 *
 * Sebagian besar berkas ini memakai baris angsuran buatan factory, bukan `pay()`,
 * supaya rumusnya teruji terpisah dari jalur pembayaran.
 */

/** Pinjaman 5.000.000 / 5 bulan; tagihan kontrak 1.050.000 (1.000.000 + 40.000 + 10.000). */
function titipanLoan(array $attributes = []): Loan
{
    return Loan::factory()->create([
        'member_id' => Member::factory()->create()->id,
        'principal_amount' => 5000000,
        'term_months' => 5,
        'monthly_principal' => 1000000,
        'monthly_interest' => 40000,
        'monthly_time_deposit' => 10000,
        ...$attributes,
    ]);
}

/** Satu baris jadwal yang sejalan dengan konstanta titipanLoan(). */
function jadwal(Loan $loan, int $seq = 1): InstallmentSchedule
{
    return InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 40000,
        'time_deposit_due' => 10000,
        'total_due' => 1050000,
    ]);
}

/**
 * Catat satu baris angsuran seperti yang akan ditulis pay() setelah item 1e:
 * `credit_applied = max(0, tagihan kontrak − dibayar)`. Nilainya WAJIB terisi
 * (0 pun boleh, NULL tidak) — baris ber-NULL adalah baris pra-fitur dan sengaja
 * tak dihitung sebagai titipan (R21/OQ-10).
 */
function setor(Loan $loan, int $seq, int|string $amount, array $attributes = []): Installment
{
    $shortfall = bcsub('1050000', (string) $amount, 2);

    return Installment::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'amount_paid' => $amount,
        'credit_applied' => bccomp($shortfall, '0', 2) > 0 ? $shortfall : '0.00',
        'is_settlement' => false,
        ...$attributes,
    ]);
}

/** Baris angsuran gaya lama: kelebihannya sudah dikreditkan ke Sukarela. */
function setorLama(Loan $loan, int $seq, int|string $amount): Installment
{
    return Installment::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'amount_paid' => $amount,
        'credit_applied' => null,
        'is_settlement' => false,
    ]);
}

it('sums the contract bill from the loan constants', function () {
    expect(titipanLoan()->monthlyTotal())->toBe('1050000.00');
});

it('has no titipan on a loan without installments', function () {
    expect(titipanLoan()->overpaymentCredit())->toBe('0.00');
});

it('has no titipan when every installment pays the contract bill exactly', function () {
    $loan = titipanLoan();

    setor($loan, 1, 1050000);
    setor($loan, 2, 1050000);

    expect($loan->overpaymentCredit())->toBe('0.00');
});

it('keeps the overpayment as titipan', function () {
    $loan = titipanLoan();

    setor($loan, 1, 1050000);
    setor($loan, 2, 2100000);

    expect($loan->overpaymentCredit())->toBe('1050000.00');
});

/**
 * Tabel "Contoh mengalir lintas bulan" di Design. Titipan menyusut lewat setoran
 * yang membayar tagihan EFEKTIF, dan saldonya tidak pernah membengkak — inilah
 * bukti bahwa patokannya tagihan kontrak.
 */
it('drains the titipan across the following months without inflating', function () {
    $loan = titipanLoan();

    setor($loan, 1, 1050000);
    expect($loan->overpaymentCredit())->toBe('0.00');

    setor($loan, 2, 3000000);
    expect($loan->overpaymentCredit())->toBe('1950000.00');

    setor($loan, 3, 50000);
    expect($loan->overpaymentCredit())->toBe('950000.00');

    setor($loan, 4, 100000);
    expect($loan->overpaymentCredit())->toBe('0.00');

    setor($loan, 5, 1050000);
    expect($loan->overpaymentCredit())->toBe('0.00');
});

it('gives the titipan back when the deposit that created it is reversed', function () {
    $loan = titipanLoan();

    $overpaid = setor($loan, 2, 2100000);

    expect($loan->overpaymentCredit())->toBe('1050000.00');

    setor($loan, 2, 2100000, ['is_reversal' => true, 'reversal_of_id' => $overpaid->id]);

    expect($loan->overpaymentCredit())->toBe('0.00');
});

it('gives the titipan back when the deposit that consumed it is reversed', function () {
    $loan = titipanLoan();

    setor($loan, 2, 2100000);

    // Tagihan efektif #3 = 1.050.000 − min(titipan, principal_due 1.000.000) =
    // 50.000. Titipan hanya memotong POKOK, jadi jasa + tab tetap tertagih dan
    // sisa 50.000 tetap mengendap.
    $consumed = setor($loan, 3, 50000);

    expect($loan->overpaymentCredit())->toBe('50000.00');

    setor($loan, 3, 50000, ['is_reversal' => true, 'reversal_of_id' => $consumed->id]);

    expect($loan->overpaymentCredit())->toBe('1050000.00');
});

/**
 * Bukan floor ke 0: pembatalan yang menghapus titipan yang SUDAH terpakai harus
 * terlihat negatif, karena justru itu sinyal yang dipakai guard reverse() (1j).
 */
it('reports a negative balance when a reversal would erase spent titipan', function () {
    $loan = titipanLoan();

    $overpaid = setor($loan, 2, 2100000);
    setor($loan, 3, 50000);

    setor($loan, 2, 2100000, ['is_reversal' => true, 'reversal_of_id' => $overpaid->id]);

    expect($loan->overpaymentCredit())->toBe('-1000000.00');
});

it('ignores settlement rows when deriving the titipan', function () {
    $loan = titipanLoan();

    setor($loan, 2, 2100000);

    Installment::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => null,
        'schedule_id' => null,
        'is_settlement' => true,
        'amount_paid' => 3040000,
    ]);

    expect($loan->overpaymentCredit())->toBe('1050000.00');
});

it('reports zero titipan on a loan that is already Lunas', function () {
    $loan = titipanLoan(['status' => LoanStatus::Lunas]);

    setor($loan, 2, 2100000);

    expect($loan->overpaymentCredit())->toBe('0.00');
});

it('reports zero titipan on a loan that is Dibatalkan', function () {
    $loan = titipanLoan(['status' => LoanStatus::Dibatalkan]);

    setor($loan, 2, 2100000);

    expect($loan->overpaymentCredit())->toBe('0.00');
});

/**
 * R21/OQ-10 — kelebihan bayar LAMA sudah diserahkan ke anggota sebagai Simpanan
 * Sukarela. Menghitungnya lagi sebagai titipan berarti membayar keringanan yang
 * sama dua kali, dan koperasi yang menanggung selisihnya.
 */
it('does not count a pre-feature overpayment as titipan', function () {
    $loan = titipanLoan();

    setorLama($loan, 1, 2100000);

    expect($loan->overpaymentCredit())->toBe('0.00')
        ->and($loan->effectiveBill(jadwal($loan, 2)))->toBe('1050000.00');
});

it('still counts new rows on a loan that also has pre-feature rows', function () {
    $loan = titipanLoan();

    setorLama($loan, 1, 2100000); // kelebihan lama — sudah jadi Sukarela
    setor($loan, 2, 2100000);     // kelebihan baru — titipan

    expect($loan->overpaymentCredit())->toBe('1050000.00');
});

it('is unaffected by pre-feature rows that paid the bill exactly', function () {
    $loan = titipanLoan();

    setorLama($loan, 1, 1050000);
    setorLama($loan, 2, 1050000);

    expect($loan->overpaymentCredit())->toBe('0.00');
});

it('bills the full contract amount when there is no titipan', function () {
    $loan = titipanLoan();

    expect($loan->effectiveBill(jadwal($loan, 2)))->toBe('1050000.00');
});

it('deducts the titipan from the bill, capped at the principal component', function () {
    $loan = titipanLoan();

    setor($loan, 1, 2100000); // titipan 1.050.000 > pokok 1.000.000

    // Dipotong 1.000.000, bukan 1.050.000 — jasa 40.000 + tab 10.000 tetap tertagih.
    expect($loan->effectiveBill(jadwal($loan, 2)))->toBe('50000.00');
});

it('deducts the whole titipan when it is smaller than the principal component', function () {
    $loan = titipanLoan();

    setor($loan, 1, 1450000); // titipan 400.000

    expect($loan->effectiveBill(jadwal($loan, 2)))->toBe('650000.00');
});

/**
 * Batas `principal_due` adalah yang menjaga jasa koperasi tetap tertagih dan hak
 * Tabungan Berjangka anggota tidak hangus — berapa pun besar titipannya.
 */
it('never lets the titipan eat the interest and time deposit components', function () {
    $loan = titipanLoan();

    setor($loan, 1, 6300000); // titipan 5.250.000 — jauh di atas satu tagihan

    expect($loan->effectiveBill(jadwal($loan, 2)))->toBe('50000.00');
});

it('does not raise the bill above contract when the balance is negative', function () {
    $loan = titipanLoan();

    $overpaid = setor($loan, 2, 2100000);
    setor($loan, 3, 50000);
    setor($loan, 2, 2100000, ['is_reversal' => true, 'reversal_of_id' => $overpaid->id]);

    expect($loan->overpaymentCredit())->toBe('-1000000.00')
        ->and($loan->effectiveBill(jadwal($loan, 4)))->toBe('1050000.00');
});

it('bills the full contract amount once the loan is Lunas', function () {
    $loan = titipanLoan();

    setor($loan, 1, 2100000);
    $loan->update(['status' => LoanStatus::Lunas]);

    expect($loan->effectiveBill(jadwal($loan, 2)))->toBe('1050000.00');
});

it('refuses a schedule that belongs to another loan', function () {
    $loan = titipanLoan();
    $other = titipanLoan();

    $loan->effectiveBill(jadwal($other, 1));
})->throws(InvalidArgumentException::class, 'bukan milik pinjaman ini');

it('charges remaining principal plus one interest as payoff when there is no titipan', function () {
    $loan = titipanLoan();

    setor($loan, 1, 1050000);
    setor($loan, 2, 1050000);

    // sisa pokok 3.000.000 + 1× jasa 40.000; jasa 3 bulan sisa dibebaskan.
    expect($loan->payoffAmount())->toBe('3040000.00');
});

it('reduces the payoff by the titipan', function () {
    $loan = titipanLoan();

    setor($loan, 1, 1050000);
    setor($loan, 2, 2100000); // titipan 1.050.000

    expect($loan->payoffAmount())->toBe('1990000.00');
});

/**
 * Batasnya sisa pokok, sejalan dengan batas `principal_due` di effectiveBill():
 * Titipan POKOK tak pernah menggerus jasa. Sisa titipan di atas batas itu tidak
 * hangus — dilimpahkan ke Sukarela saat pinjaman jadi Lunas (item 1h).
 */
it('never lets the titipan eat the single interest charge on a payoff', function () {
    $loan = titipanLoan();

    setor($loan, 1, 6300000); // titipan 5.250.000, sisa pokok 4.000.000

    expect($loan->payoffAmount())->toBe('40000.00');
});

it('does not raise the payoff when the balance is negative', function () {
    $loan = titipanLoan();

    $overpaid = setor($loan, 2, 2100000);
    setor($loan, 3, 50000);
    setor($loan, 2, 2100000, ['is_reversal' => true, 'reversal_of_id' => $overpaid->id]);

    // netCount 1 → sisa pokok 4.000.000; saldo −1.000.000 tidak boleh menambah tagihan.
    expect($loan->overpaymentCredit())->toBe('-1000000.00')
        ->and($loan->payoffAmount())->toBe('4040000.00');
});

/**
 * R2: rumus payoff dulunya ditulis ulang di settleEarly() dan di validasi batch.
 * Yang dikunci di sini — jalur eksekusi benar-benar menegakkan angka yang sama
 * dengan payoffAmount(), termasuk potongan titipan.
 */
it('lets settleEarly accept exactly the titipan-reduced payoff', function () {
    $loan = titipanLoan();

    $schedules = collect(range(1, 5))->map(fn (int $seq) => jadwal($loan, $seq));

    setor($loan, 1, 2100000); // titipan 1.050.000
    $schedules[0]->update(['status' => InstallmentScheduleStatus::Terbayar]);

    $payoff = $loan->payoffAmount();

    expect($payoff)->toBe('2990000.00');

    $user = User::factory()->create();
    $service = app(LoanPaymentService::class);

    expect(fn () => $service->settleEarly($loan, ['amount_paid' => '2989999'], $user->id))
        ->toThrow(CannotProcessPayment::class);

    $settlement = $service->settleEarly($loan, ['amount_paid' => $payoff], $user->id);

    expect($settlement->is_settlement)->toBeTrue()
        ->and($loan->fresh()->status)->toBe(LoanStatus::Lunas);
});

/**
 * Item 1i / 3i — jejak audit tak boleh putus justru di transaksi terbesar, dan
 * titipannya tidak boleh tertagih dua kali: sudah dipotong dari payoff, jadi
 * `credit_applied` hanya MENCATAT potongan yang sama, bukan menambah tagihan.
 */
it('writes credit_applied on the settlement row', function () {
    $loan = titipanLoan();

    collect(range(1, 5))->each(fn (int $seq) => jadwal($loan, $seq));

    setor($loan, 1, 2100000); // titipan 1.050.000
    $loan->schedules()->where('installment_seq', 1)
        ->update(['status' => InstallmentScheduleStatus::Terbayar]);

    $payoff = $loan->payoffAmount();
    $settlement = app(LoanPaymentService::class)
        ->settleEarly($loan, ['amount_paid' => $payoff], User::factory()->create()->id);

    expect($payoff)->toBe('2990000.00')
        ->and($settlement->credit_applied)->toBe('1050000.00')
        ->and($settlement->amount_paid)->toBe('2990000.00');

    // 4.040.000 kontraktual − 1.050.000 titipan = 2.990.000. Tak ada tagihan ganda.
    expect(bcadd($settlement->amount_paid, $settlement->credit_applied, 2))->toBe('4040000.00');
});

/**
 * Titipan yang melebihi sisa pokok tidak terpakai seluruhnya oleh pelunasan —
 * sisanya WAJIB dilimpahkan ke Sukarela, bukan hangus saat status jadi Lunas.
 */
it('moves the unused titipan to sukarela when settling early', function () {
    $loan = titipanLoan();

    collect(range(1, 5))->each(fn (int $seq) => jadwal($loan, $seq));

    // Titipan 5.250.000, sisa pokok 4.000.000 → 1.250.000 tak terpakai.
    setor($loan, 1, 6300000);
    $loan->schedules()->where('installment_seq', 1)
        ->update(['status' => InstallmentScheduleStatus::Terbayar]);

    expect($loan->payoffAmount())->toBe('40000.00')
        ->and($loan->payoffCreditApplied())->toBe('4000000.00');

    $settlement = app(LoanPaymentService::class)
        ->settleEarly($loan, ['amount_paid' => '40000'], User::factory()->create()->id);

    $deposit = SavingsDeposit::where('member_id', $loan->member_id)
        ->where('savings_type', 'sukarela')->first();

    expect($settlement->credit_applied)->toBe('4000000.00')
        ->and($deposit)->not->toBeNull()
        ->and($deposit->amount)->toBe('1250000.00')
        ->and($loan->fresh()->status)->toBe(LoanStatus::Lunas)
        ->and($loan->fresh()->overpaymentCredit())->toBe('0.00');
});
