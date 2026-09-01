<?php

use App\Enums\LoanStatus;
use App\Filament\Resources\LoanResource;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use App\Services\LoanPaymentService;

/**
 * Item 3e (ADR 2026-08-28) — klaim INTI yang sampai kini hanya ada di dokumen:
 * kedua mode menghasilkan Σ uang dan Σ jasa yang **identik**.
 *
 * Ini yang menjaga dua hal sekaligus. Bila mode tutup-sekalian menghasilkan lebih
 * sedikit baris angsuran, jasa tertagih koperasi berkurang **dan** hak Tabungan
 * Berjangka anggota hangus — akrualnya count-based. Fitur ini murni keringanan
 * arus kas; total yang dibayar anggota seumur pinjaman harus sama persis.
 */
function ekuivalensiLoan(): array
{
    $loan = Loan::factory()->create([
        'member_id' => Member::factory()->create()->id,
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 5000000,
        'term_months' => 5,
        'monthly_principal' => 1000000,
        'monthly_interest' => 40000,
        'monthly_time_deposit' => 10000,
    ]);

    $schedules = collect(range(1, 5))->map(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 40000,
        'time_deposit_due' => 10000,
        'total_due' => 1050000,
    ]));

    return [$loan, $schedules];
}

/** Σ uang, jumlah angsuran bersih, Σ jasa, dan Σ tab dari sebuah pinjaman lunas. */
function ringkasan(Loan $loan): array
{
    $rows = Installment::where('loan_id', $loan->id)->where('is_reversal', false)->get();

    $money = $rows->reduce(fn (string $carry, Installment $r) => bcadd($carry, (string) $r->amount_paid, 2), '0.00');

    $count = $rows->where('is_settlement', false)->count();

    return [
        'money' => $money,
        'count' => $count,
        'interest' => bcmul((string) $loan->monthly_interest, (string) $count, 2),
        'time_deposit' => bcmul((string) $loan->monthly_time_deposit, (string) $count, 2),
    ];
}

it('charges the same money, interest and time deposit in both modes', function () {
    $user = User::factory()->create();
    $service = app(LoanPaymentService::class);

    // Jalur A — bawaan Titipan Pokok. Setor 2× tagihan di #2, sisanya mengalir.
    [$loanA, $schedulesA] = ekuivalensiLoan();

    $service->pay($schedulesA[0], ['amount_paid' => 1050000], $user->id);
    $service->pay($schedulesA[1]->fresh(), ['amount_paid' => 2100000], $user->id);
    // Tagihan efektif menyusut: #3 = 50.000, #4 = 1.000.000, #5 penuh.
    $service->pay($schedulesA[2]->fresh(), ['amount_paid' => 50000], $user->id);
    $service->pay($schedulesA[3]->fresh(), ['amount_paid' => 1000000], $user->id);
    $service->pay($schedulesA[4]->fresh(), ['amount_paid' => 1050000], $user->id);

    // Jalur B — tutup sekalian. Setoran yang sama persis di #2.
    [$loanB, $schedulesB] = ekuivalensiLoan();

    $service->pay($schedulesB[0], ['amount_paid' => 1050000], $user->id);
    $service->pay(
        $schedulesB[1]->fresh(),
        ['amount_paid' => 2100000, 'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN],
        $user->id,
    );
    $service->pay($schedulesB[3]->fresh(), ['amount_paid' => 1050000], $user->id);
    $service->pay($schedulesB[4]->fresh(), ['amount_paid' => 1050000], $user->id);

    $a = ringkasan($loanA->fresh());
    $b = ringkasan($loanB->fresh());

    expect($loanA->fresh()->status)->toBe(LoanStatus::Lunas)
        ->and($loanB->fresh()->status)->toBe(LoanStatus::Lunas)
        // Total uang seumur pinjaman sama persis — fitur ini tak menghemat apa pun.
        ->and($a['money'])->toBe('5250000.00')
        ->and($b['money'])->toBe($a['money'])
        // Jumlah baris angsuran sama → jasa tertagih koperasi tak berkurang.
        ->and($a['count'])->toBe(5)
        ->and($b['count'])->toBe($a['count'])
        ->and($b['interest'])->toBe($a['interest'])
        // Akrual Tabungan Berjangka anggota tidak hangus.
        ->and($b['time_deposit'])->toBe($a['time_deposit']);
});

/** Pembanding: pinjaman tanpa kelebihan bayar sama sekali harus identik juga. */
it('matches a loan that never overpaid at all', function () {
    $user = User::factory()->create();
    $service = app(LoanPaymentService::class);

    [$loan, $schedules] = ekuivalensiLoan();

    foreach ($schedules as $schedule) {
        $service->pay($schedule->fresh(), ['amount_paid' => 1050000], $user->id);
    }

    expect(ringkasan($loan->fresh()))->toBe([
        'money' => '5250000.00',
        'count' => 5,
        'interest' => '200000.00',
        'time_deposit' => '50000.00',
    ]);
});

/**
 * Akrual Tabungan Berjangka dibaca dari mesinnya sendiri (`scopeSignedTimeDeposit`),
 * bukan dari hitungan ulang di test — agar yang diuji benar-benar perilaku sistem.
 */
it('accrues identical time deposit through the real scope in both modes', function () {
    $user = User::factory()->create();
    $service = app(LoanPaymentService::class);

    [$loanA, $schedulesA] = ekuivalensiLoan();
    $service->pay($schedulesA[0], ['amount_paid' => 2100000], $user->id);

    [$loanB, $schedulesB] = ekuivalensiLoan();
    $service->pay(
        $schedulesB[0],
        ['amount_paid' => 2100000, 'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN],
        $user->id,
    );

    $tab = fn (Loan $loan): string => bcadd((string) Installment::query()
        ->where('installments.loan_id', $loan->id)
        ->signedTimeDeposit()
        ->value('net'), '0', 2);

    // Titipan: 1 baris → 10.000. Tutup sekalian: 2 baris → 20.000. Berbeda di
    // titik ini karena jumlah angsuran yang DITUTUP memang berbeda — bukan karena
    // salah satu mode menggugurkan akrual. Ekuivalensinya berlaku saat pinjaman
    // selesai, dan itu yang diuji test pertama.
    expect($tab($loanA))->toBe('10000.00')
        ->and($tab($loanB))->toBe('20000.00');
});

/**
 * Item 3p (R18) — invariant "Dibatalkan ⇒ titipan 0" hari ini aman **secara
 * konstruksi**: `canCorrect()` melarang pembatalan pinjaman yang sudah punya
 * angsuran, jadi pinjaman Dibatalkan tak pernah punya titipan.
 *
 * Ketergantungan itu menanggung beban. Pembatalan menghapus seluruh jadwal, jadi
 * bila guard ini dilonggarkan suatu saat, titipan pada pinjaman Dibatalkan jadi
 * tak terlihat dan tak bisa diambil siapa pun — tak ada jadwal untuk memakannya,
 * tak ada pelimpahan. Test ini yang menahan pintunya.
 */
it('refuses to cancel a loan that already has installments', function () {
    asSuperAdmin();

    [$loan, $schedules] = ekuivalensiLoan();

    expect(LoanResource::canCorrect($loan))->toBeTrue();

    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 2100000], auth()->id());

    expect($loan->fresh()->overpaymentCredit())->toBe('1050000.00')
        ->and(LoanResource::canCorrect($loan->fresh()))->toBeFalse();
});

/**
 * Item 3r (R21) — `overpaymentCredit()` hanya menghitung baris ber-`credit_applied`
 * non-NULL. Jalur pembuat baris yang lupa mengisinya membuat titipan menguap
 * diam-diam. Hanya ada dua pembuat baris, dan keduanya dikunci di sini.
 */
it('always fills credit_applied on every row it creates', function () {
    $user = User::factory()->create();
    $service = app(LoanPaymentService::class);

    [$loan, $schedules] = ekuivalensiLoan();

    // Pembuat #1 — pay(), termasuk sesi multi-baris.
    $service->pay(
        $schedules[0],
        ['amount_paid' => 3000000, 'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN],
        $user->id,
    );

    // Pembuat #2 — settleEarly().
    $service->settleEarly($loan->fresh(), ['amount_paid' => $loan->fresh()->payoffAmount()], $user->id);

    $rows = Installment::where('loan_id', $loan->id)->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row->credit_applied)->not->toBeNull(
            "Baris {$row->installment_number} tak mengisi credit_applied — ia akan hilang dari saldo titipan."
        );
    }
});
