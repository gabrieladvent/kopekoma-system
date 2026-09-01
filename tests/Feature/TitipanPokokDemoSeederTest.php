<?php

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Exceptions\CannotReverseTransaction;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Services\LoanPaymentService;
use App\Services\SavingsBalanceService;
use Database\Seeders\TitipanPokokDemoSeeder;

/**
 * Penjaga data uji coba (ADR 2026-08-28).
 *
 * Dokumen UAT `docs/uat/titipan-pokok.md` menyebut ANGKA-ANGKA konkret sebagai
 * hasil yang diharapkan penguji. Bila seeder-nya bergeser tanpa dokumennya ikut
 * bergeser, penguji akan melaporkan "tidak sesuai" atas sistem yang sebenarnya
 * benar — dan kepercayaan pada seluruh daftar itu ikut hilang. Test ini yang
 * menahan keduanya tetap sejalan.
 */
beforeEach(function () {
    $this->seed(TitipanPokokDemoSeeder::class);
});

function demoLoan(string $memberName): Loan
{
    $member = Member::where('full_name', $memberName)->firstOrFail();

    return Loan::where('member_id', $member->id)->firstOrFail();
}

it('builds six loans in the states the UAT document describes', function () {
    expect(Member::where('nik', 'like', '33099%')->count())->toBe(6);
});

it('T1 — bersih, dua angsuran terbayar, titipan nol', function () {
    $loan = demoLoan('Rahayu Kusumaningrum');

    expect($loan->overpaymentCredit())->toBe('0.00')
        ->and($loan->status)->toBe(LoanStatus::Cair)
        ->and(Installment::where('loan_id', $loan->id)->count())->toBe(2);

    $next = $loan->schedules()->where('status', InstallmentScheduleStatus::BelumBayar)
        ->orderBy('installment_seq')->firstOrFail();

    expect($next->installment_seq)->toBe(3)
        ->and($loan->effectiveBill($next))->toBe('1090000.00');
});

it('T2 — titipan 1.090.000 dan tagihan efektif berikutnya 90.000', function () {
    $loan = demoLoan('Yusuf Maulana');

    $next = $loan->schedules()->where('status', InstallmentScheduleStatus::BelumBayar)
        ->orderBy('installment_seq')->firstOrFail();

    expect($loan->overpaymentCredit())->toBe('1090000.00')
        ->and($next->installment_seq)->toBe(3)
        ->and($loan->effectiveBill($next))->toBe('90000.00');
});

it('T3 — satu setoran menutup dua angsuran dengan satu kunci sesi', function () {
    $loan = demoLoan('Wahyu Nugroho');

    $rows = Installment::where('loan_id', $loan->id)->orderBy('installment_seq')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('session_key')->unique())->toHaveCount(1)
        ->and($rows[0]->amount_paid)->toBe('1090000.00')
        ->and($rows[1]->amount_paid)->toBe('1090000.00')
        ->and($loan->overpaymentCredit())->toBe('0.00');
});

it('T4 — titipan sudah terpakai, pembatalan setoran pertama tertolak', function () {
    $loan = demoLoan('Ratna Dewi Anggraini');

    expect($loan->overpaymentCredit())->toBe('90000.00');

    $first = Installment::where('loan_id', $loan->id)->where('installment_seq', 1)->firstOrFail();
    $second = Installment::where('loan_id', $loan->id)->where('installment_seq', 2)->firstOrFail();

    expect(fn () => app(LoanPaymentService::class)
        ->reverse($first, 'uji coba', $first->recorded_by))
        ->toThrow(CannotReverseTransaction::class, $second->installment_number);
});

it('T5 — lunas via pelunasan; titipan terbagi antara payoff dan sukarela', function () {
    $loan = demoLoan('Hesti Prabaningrum');

    $settlement = Installment::where('loan_id', $loan->id)->where('is_settlement', true)->firstOrFail();

    expect($loan->status)->toBe(LoanStatus::Lunas)
        ->and($loan->settlementCreditApplied())->toBe('1000000.00')
        // payoff = sisa pokok 1.000.000 + jasa 78.000 − titipan terpakai 1.000.000.
        ->and($settlement->amount_paid)->toBe('78000.00')
        ->and($settlement->credit_applied)->toBe('1000000.00');

    // Sisa titipan yang tak terpakai dilimpahkan ke Simpanan Sukarela.
    $sukarela = SavingsDeposit::where('member_id', $loan->member_id)
        ->where('savings_type', 'sukarela')
        ->where('is_reversal', false)
        ->sum('amount');

    expect($sukarela)->toEqual(1000000);
});

it('T6 — payoff di layar batch sudah dikurangi titipan', function () {
    $loan = demoLoan('Bagus Prakoso');

    expect($loan->overpaymentCredit())->toBe('1090000.00')
        ->and($loan->settledPrincipal())->toBe('8000000.00')
        // 8.000.000 + 78.000 − 1.090.000.
        ->and($loan->payoffAmount())->toBe('6988000.00');
});

it('reports the same aggregate the laporan page will show', function () {
    $loans = Loan::where('status', LoanStatus::Cair)->get();

    $total = array_reduce(
        Loan::overpaymentCredits($loans),
        fn (string $carry, string $credit): string => bccomp($credit, '0', 2) > 0 ? bcadd($carry, $credit, 2) : $carry,
        '0.00'
    );

    // T2 + T4 + T6 = 1.090.000 + 90.000 + 1.090.000.
    expect($total)->toBe('2270000.00');
});

it('refuses to run twice on top of itself', function () {
    $before = Member::where('nik', 'like', '33099%')->count();

    $this->seed(TitipanPokokDemoSeeder::class);

    expect(Member::where('nik', 'like', '33099%')->count())->toBe($before);
});

it('leaves every schedule of a paid-off loan closed', function () {
    $loan = demoLoan('Hesti Prabaningrum');

    expect(InstallmentSchedule::where('loan_id', $loan->id)
        ->where('status', InstallmentScheduleStatus::BelumBayar)->count())->toBe(0);
});

/**
 * Angka SWP & Tabungan Berjangka yang disebut §9 dokumen UAT. Dikunci di sini
 * karena penguji membacanya sebagai hasil yang diharapkan — seeder yang bergeser
 * tanpa dokumennya ikut bergeser membuat penguji melaporkan "tidak sesuai" atas
 * sistem yang benar.
 */
it('matches the SWP and tabungan berjangka figures the UAT document states', function () {
    $balances = app(SavingsBalanceService::class);

    $expected = [
        'Rahayu Kusumaningrum' => '24000.00',
        'Yusuf Maulana' => '24000.00',
        'Wahyu Nugroho' => '24000.00',
        'Ratna Dewi Anggraini' => '24000.00',
        // 11 angsuran biasa; baris pelunasan TIDAK mengakru.
        'Hesti Prabaningrum' => '132000.00',
        'Bagus Prakoso' => '48000.00',
    ];

    foreach ($expected as $name => $timeDeposit) {
        $member = Member::where('full_name', $name)->firstOrFail();

        expect($balances->balanceByType($member, 'swp'))->toBe('120000.00', "SWP {$name}")
            ->and($balances->balanceByType($member, 'tabungan_berjangka'))->toBe($timeDeposit, "Tab. Berjangka {$name}");
    }
});

/** Pinjaman lunas tak menerbitkan pencairan apa pun (§9.2). */
it('issues no withdrawal for the paid-off demo loan', function () {
    $member = Member::where('full_name', 'Hesti Prabaningrum')->firstOrFail();

    expect(SavingsWithdrawal::where('member_id', $member->id)->count())->toBe(0);
});
