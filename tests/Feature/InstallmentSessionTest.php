<?php

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Exceptions\CannotProcessPayment;
use App\Exceptions\CannotReverseTransaction;
use App\Livewire\Loan\Installment\InstallmentDetail;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use App\Services\LoanPaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Item 1e (ADR 2026-08-28) — `pay()` menulis N baris lewat `allocate()`.
 * Yang dikunci: penanda sesi, kunci idempotensi TURUNAN (bukan acak), dan
 * `credit_applied` terisi di setiap baris.
 */
beforeEach(function () {
    $this->service = app(LoanPaymentService::class);
    $this->user = User::factory()->create();

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

it('writes a single row in the default titipan mode', function () {
    $inst = $this->service->pay($this->schedules[0], ['amount_paid' => 2100000], $this->user->id);

    expect(Installment::where('loan_id', $this->loan->id)->count())->toBe(1)
        ->and($inst->amount_paid)->toBe('2100000.00')
        ->and($inst->credit_applied)->toBe('0.00')
        ->and($this->loan->fresh()->overpaymentCredit())->toBe('1050000.00')
        ->and($this->schedules[1]->fresh()->status)->toBe(InstallmentScheduleStatus::BelumBayar);
});

it('closes two installments in one session when asked', function () {
    $inst = $this->service->pay(
        $this->schedules[0],
        ['amount_paid' => 2100000, 'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN],
        $this->user->id,
    );

    $rows = Installment::where('loan_id', $this->loan->id)->orderBy('installment_seq')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('amount_paid')->all())->toBe(['1050000.00', '1050000.00'])
        ->and($rows->pluck('installment_seq')->all())->toBe([1, 2])
        ->and($this->loan->fresh()->overpaymentCredit())->toBe('0.00')
        ->and($this->schedules[1]->fresh()->status)->toBe(InstallmentScheduleStatus::Terbayar);

    // Nilai kembalian tetap baris untuk jadwal yang diminta — kontrak lama utuh.
    expect($inst->installment_seq)->toBe(1);
});

it('marks every row of a session with the same session key', function () {
    $this->service->pay(
        $this->schedules[0],
        ['amount_paid' => 3000000, 'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN],
        $this->user->id,
    );

    $rows = Installment::where('loan_id', $this->loan->id)->orderBy('installment_seq')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('session_key')->unique())->toHaveCount(1)
        ->and($rows[0]->session_key)->not->toBeNull();
});

/**
 * Kunci baris DITURUNKAN dari kunci sesi, bukan diacak — itu yang membuat klik
 * simpan kedua menabrak indeks unik alih-alih membuat sesi kedua.
 */
it('derives each row idempotency key from the session key', function () {
    $session = (string) Str::uuid();

    $this->service->pay(
        $this->schedules[0],
        [
            'amount_paid' => 3000000,
            'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN,
            'idempotency_key' => $session,
        ],
        $this->user->id,
    );

    $rows = Installment::where('loan_id', $this->loan->id)->orderBy('installment_seq')->get();

    expect($rows->pluck('idempotency_key')->all())->toBe([$session.'-1', $session.'-2'])
        ->and($rows->pluck('session_key')->all())->toBe([$session, $session]);
});

it('rejects a repeated submit of the same session', function () {
    $session = (string) Str::uuid();

    $input = [
        'amount_paid' => 3000000,
        'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN,
        'idempotency_key' => $session,
    ];

    $this->service->pay($this->schedules[0], $input, $this->user->id);

    // Dua lapis menjaga klik ganda, dan yang pertama menang: jadwalnya sudah
    // Terbayar sehingga allocate() menolak sebelum baris apa pun dibuat. Indeks
    // unik atas kunci turunan adalah lapis kedua, untuk klik yang benar-benar
    // bersamaan — diuji terpisah di InstallmentCreditColumnsTest.
    expect(fn () => $this->service->pay($this->schedules[0]->fresh(), $input, $this->user->id))
        ->toThrow(CannotProcessPayment::class);

    expect(Installment::where('loan_id', $this->loan->id)->count())->toBe(2);
});

it('refuses to write a second session under the same derived keys', function () {
    $session = (string) Str::uuid();

    $this->service->pay(
        $this->schedules[0],
        ['amount_paid' => 1050000, 'idempotency_key' => $session],
        $this->user->id,
    );

    // Jadwal berbeda, tapi kunci sesi diulang → kunci baris bertabrakan.
    expect(fn () => $this->service->pay(
        $this->schedules[1]->fresh(),
        ['amount_paid' => 1050000, 'idempotency_key' => $session],
        $this->user->id,
    ))->toThrow(UniqueConstraintViolationException::class);

    expect(Installment::where('loan_id', $this->loan->id)->count())->toBe(1);
});

it('fills credit_applied on every row of a session', function () {
    // Titipan 1.950.000 dulu, lalu satu sesi yang memakainya di dua angsuran.
    $this->service->pay($this->schedules[0], ['amount_paid' => 3000000], $this->user->id);

    $this->service->pay(
        $this->schedules[1]->fresh(),
        ['amount_paid' => 150000, 'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN],
        $this->user->id,
    );

    $rows = Installment::where('loan_id', $this->loan->id)->orderBy('installment_seq')->get();

    // Setiap baris WAJIB ber-credit_applied non-NULL: NULL berarti "baris
    // pra-fitur" dan tersaring keluar dari saldo titipan (R21).
    foreach ($rows as $row) {
        expect($row->credit_applied)->not->toBeNull();
    }

    // #1 tak memakai titipan; #2 bayar 50.000 (pakai 1.000.000); #3 bayar
    // 100.000 (pakai 950.000).
    expect($rows->pluck('credit_applied')->all())->toBe(['0.00', '1000000.00', '950000.00']);
});

/**
 * Item 3o — bukti wajib melekat di SEMUA baris. Kegagalannya khas: `addMedia()`
 * memindahkan berkas sumber, jadi baris kedua kehilangan lampirannya (R17).
 */
it('attaches the payment proof to every row of a session', function () {
    Storage::fake(config('media-library.disk_name'));

    $this->service->pay(
        $this->schedules[0],
        ['amount_paid' => 2100000, 'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN],
        $this->user->id,
        UploadedFile::fake()->image('bukti-setoran.jpg'),
    );

    $rows = Installment::where('loan_id', $this->loan->id)->orderBy('installment_seq')->get();

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect($row->getMedia('bukti'))->toHaveCount(1)
            ->and($row->getFirstMedia('bukti')->file_name)->toBe('bukti-setoran.jpg');
    }
});

/**
 * Item 1j / 3h — guard presisi pembatalan. Titipan yang sudah TERPAKAI tak boleh
 * ditarik kembali lewat pembatalan setoran yang membuatnya: saldonya jadi minus
 * dan koperasi menanggung selisihnya.
 */
it('refuses a reversal that would push the titipan negative, naming the blocker', function () {
    $overpaid = $this->service->pay($this->schedules[0], ['amount_paid' => 2100000], $this->user->id);

    // #2 memakai 1.000.000 titipan; sisa 50.000.
    $consumer = $this->service->pay($this->schedules[1]->fresh(), ['amount_paid' => 50000], $this->user->id);

    expect($this->loan->fresh()->overpaymentCredit())->toBe('50000.00');

    expect(fn () => $this->service->reverse($overpaid, 'salah input nominal', $this->user->id))
        ->toThrow(CannotReverseTransaction::class, $consumer->installment_number);

    // Ditolak berarti TIDAK ada yang berubah — transaksinya rollback penuh.
    expect($this->loan->fresh()->overpaymentCredit())->toBe('50000.00')
        ->and(Installment::where('loan_id', $this->loan->id)->where('is_reversal', true)->count())->toBe(0);
});

it('allows the reversal once the blocking installment is reversed first', function () {
    $overpaid = $this->service->pay($this->schedules[0], ['amount_paid' => 2100000], $this->user->id);
    $consumer = $this->service->pay($this->schedules[1]->fresh(), ['amount_paid' => 50000], $this->user->id);

    $this->service->reverse($consumer, 'urutan pembatalan benar', $this->user->id);

    expect($this->loan->fresh()->overpaymentCredit())->toBe('1050000.00');

    $this->service->reverse($overpaid, 'salah input nominal', $this->user->id);

    expect($this->loan->fresh()->overpaymentCredit())->toBe('0.00');
});

/** Pinjaman yang tak pernah bertitipan tidak boleh ikut terkekang guard ini. */
it('does not constrain reversals on a loan that never had titipan', function () {
    $first = $this->service->pay($this->schedules[0], ['amount_paid' => 1050000], $this->user->id);
    $this->service->pay($this->schedules[1]->fresh(), ['amount_paid' => 1050000], $this->user->id);

    $this->service->reverse($first, 'boleh dibatalkan walau bukan yang terakhir', $this->user->id);

    expect($this->schedules[0]->fresh()->status)->toBe(InstallmentScheduleStatus::BelumBayar)
        ->and($this->loan->fresh()->overpaymentCredit())->toBe('0.00');
});

it('carries credit_applied and session_key onto the reversal row', function () {
    $inst = $this->service->pay(
        $this->schedules[0],
        ['amount_paid' => 3000000, 'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN],
        $this->user->id,
    );

    $reversal = $this->service->reverse($inst, 'salah input nominal', $this->user->id);

    expect($reversal->credit_applied)->toBe($inst->credit_applied)
        ->and($reversal->session_key)->toBe($inst->session_key);
});

it('settles the loan and moves the leftover titipan when a session closes it', function () {
    foreach (range(0, 2) as $i) {
        $this->service->pay($this->schedules[$i]->fresh(), ['amount_paid' => 1050000], $this->user->id);
    }

    // Sisa 2 jadwal — bayar tagihan #4 saja, kelebihan sedikit jadi titipan.
    $this->service->pay($this->schedules[3]->fresh(), ['amount_paid' => 1060000], $this->user->id);

    expect($this->loan->fresh()->overpaymentCredit())->toBe('10000.00');

    // Angsuran terakhir: tagihan efektif 1.040.000; tidak dibelokkan ke pelunasan.
    $this->service->pay($this->schedules[4]->fresh(), ['amount_paid' => 1040000], $this->user->id);

    expect($this->loan->fresh()->status)->toBe(LoanStatus::Lunas)
        ->and($this->loan->fresh()->overpaymentCredit())->toBe('0.00');
});

/**
 * Item 2h / 3q (OQ-9, R20) — dengan lubang jadwal, Sisa Pokok di layar detail
 * harus sama dengan `settledPrincipal()`. Skenario uji dari ADR: setor 2× tagihan
 * mode tutup-sekalian (#1 & #2 lunas), lalu batalkan #1 saja.
 *
 * Versi lama menghitung dari nomor urut jadwal, jadi ia menjawab "ini angsuran
 * nomor 2 → 2 juta terbayar" padahal yang tersisa cuma satu angsuran bersih.
 */
it('keeps the remaining principal consistent when a schedule hole appears', function () {
    asSuperAdmin();

    $first = $this->service->pay(
        $this->schedules[0],
        ['amount_paid' => 2100000, 'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN],
        $this->user->id,
    );

    $second = Installment::where('loan_id', $this->loan->id)
        ->where('installment_seq', 2)
        ->firstOrFail();

    // Batalkan HANYA #1 — menyisakan lubang: #2 lunas, #1 tidak.
    $this->service->reverse($first, 'koreksi setoran pertama', $this->user->id);

    $loan = $this->loan->fresh();

    // Satu angsuran bersih tersisa → sisa pokok 4.000.000, bukan 3.000.000.
    expect($loan->settledPrincipal())->toBe('4000000.00');

    $shown = Livewire::test(InstallmentDetail::class, ['installment' => $second->fresh()])
        ->viewData('remaining');

    expect($shown)->toBe($loan->settledPrincipal());
});

/** Item 2d — layar pembatalan memberi tahu keterkaitan sesi, tanpa memaksa. */
it('shows the session sibling on the reversal screen', function () {
    asSuperAdmin();

    $first = $this->service->pay(
        $this->schedules[0],
        ['amount_paid' => 2100000, 'mode' => LoanPaymentService::MODE_TUTUP_SEKALIAN],
        $this->user->id,
    );

    $second = Installment::where('loan_id', $this->loan->id)
        ->where('installment_seq', 2)
        ->firstOrFail();

    Livewire::test(InstallmentDetail::class, ['installment' => $first])
        ->assertSee('Satu setoran bersama '.$second->installment_number);
});

it('says nothing about siblings for a single-row payment', function () {
    asSuperAdmin();

    $only = $this->service->pay($this->schedules[0], ['amount_paid' => 1050000], $this->user->id);

    Livewire::test(InstallmentDetail::class, ['installment' => $only])
        ->assertDontSee('Satu setoran bersama');
});
