<?php

use App\Enums\InstallmentScheduleStatus;
use App\Exceptions\CannotProcessPayment;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanPaymentService;

/**
 * Item 1d (ADR 2026-08-28) — alokasi bertingkat. MURNI HITUNGAN: allocate()
 * tidak membuat baris apa pun, jadi yang diuji di sini bentuk rencananya.
 *
 * Dua contoh di Design dipakai sebagai kasus acuan, angka demi angka:
 * setor 2.100.000 (kedua mode) dan setor 3.000.000 (kedua mode).
 */
beforeEach(function () {
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

    // Tagihan kontrak 1.050.000 × 5 bulan.
    $this->schedules = collect(range(1, 5))->map(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $this->loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 40000,
        'time_deposit_due' => 10000,
        'total_due' => 1050000,
    ]));
});

/** Tutup satu jadwal dengan baris angsuran gaya baru (credit_applied terisi). */
function tutup(Loan $loan, InstallmentSchedule $schedule, int|string $amount): void
{
    $shortfall = bcsub('1050000', (string) $amount, 2);

    Installment::factory()->create([
        'loan_id' => $loan->id,
        'schedule_id' => $schedule->getKey(),
        'installment_seq' => $schedule->installment_seq,
        'amount_paid' => $amount,
        'credit_applied' => bccomp($shortfall, '0', 2) > 0 ? $shortfall : '0.00',
        'is_settlement' => false,
    ]);

    $schedule->update(['status' => InstallmentScheduleStatus::Terbayar]);
}

/** Ringkas rencana jadi [seq => amount_paid] agar assertion-nya terbaca. */
function rencana(array $plan): array
{
    return collect($plan['rows'])
        ->mapWithKeys(fn (array $row) => [$row['schedule']->installment_seq => $row['amount_paid']])
        ->all();
}

it('bills exactly the contract amount for a plain payment', function () {
    $plan = $this->service->allocate($this->loan, $this->schedules[0], 1050000);

    expect(rencana($plan))->toBe([1 => '1050000.00'])
        ->and($plan['rows'][0]['credit_applied'])->toBe('0.00')
        ->and($plan['credit_after'])->toBe('0.00');
});

/** Tabel "Rumus benar di kedua mode" — mode Titipan, setor 2.100.000. */
it('keeps the remainder as titipan on a single row by default', function () {
    tutup($this->loan, $this->schedules[0], 1050000);

    $plan = $this->service->allocate($this->loan, $this->schedules[1], 2100000);

    expect(rencana($plan))->toBe([2 => '2100000.00'])
        ->and($plan['credit_after'])->toBe('1050000.00')
        ->and($plan['rows'][0]['credit_applied'])->toBe('0.00');
});

/** Tabel yang sama — mode Tutup Sekalian, setor 2.100.000: #2 dan #3 lunas, titipan 0. */
it('closes the next installment as well when asked', function () {
    tutup($this->loan, $this->schedules[0], 1050000);

    $plan = $this->service->allocate(
        $this->loan,
        $this->schedules[1],
        2100000,
        LoanPaymentService::MODE_TUTUP_SEKALIAN,
    );

    expect(rencana($plan))->toBe([2 => '1050000.00', 3 => '1050000.00'])
        ->and($plan['credit_after'])->toBe('0.00');
});

/** Contoh Kuitansi: setor 3.000.000 tutup-sekalian → baris #3 menyerap sisa. */
it('lets the last row absorb the remainder', function () {
    tutup($this->loan, $this->schedules[0], 1050000);

    $plan = $this->service->allocate(
        $this->loan,
        $this->schedules[1],
        3000000,
        LoanPaymentService::MODE_TUTUP_SEKALIAN,
    );

    expect(rencana($plan))->toBe([2 => '1050000.00', 3 => '1950000.00'])
        ->and($plan['credit_after'])->toBe('900000.00');
});

it('charges the titipan-reduced bill on the following month', function () {
    tutup($this->loan, $this->schedules[0], 1050000);
    tutup($this->loan, $this->schedules[1], 3000000); // titipan 1.950.000

    $plan = $this->service->allocate($this->loan, $this->schedules[2], 50000);

    // Tagihan efektif #3 = 1.050.000 − 1.000.000; titipan dipakai 1.000.000.
    expect(rencana($plan))->toBe([3 => '50000.00'])
        ->and($plan['rows'][0]['credit_applied'])->toBe('1000000.00')
        ->and($plan['credit_before'])->toBe('1950000.00')
        ->and($plan['credit_after'])->toBe('950000.00');
});

/**
 * Titipan besar TIDAK boleh menutup angsuran berikutnya secara gratis — jasa
 * dan tab tetap tertagih, jadi tutup-sekalian berhenti begitu sisa uang tak
 * cukup membayar tagihan efektif berikutnya.
 */
it('stops closing installments once the money runs out', function () {
    tutup($this->loan, $this->schedules[0], 1050000);

    $plan = $this->service->allocate(
        $this->loan,
        $this->schedules[1],
        1100000,
        LoanPaymentService::MODE_TUTUP_SEKALIAN,
    );

    expect(rencana($plan))->toBe([2 => '1100000.00'])
        ->and($plan['credit_after'])->toBe('50000.00');
});

it('rejects a payment below the effective bill', function () {
    $this->service->allocate($this->loan, $this->schedules[0], 1049999);
})->throws(CannotProcessPayment::class, 'tidak boleh kurang dari tagihan');

it('accepts the reduced bill that would be rejected against the contract bill', function () {
    tutup($this->loan, $this->schedules[0], 2100000); // titipan 1.050.000

    $plan = $this->service->allocate($this->loan, $this->schedules[1], 50000);

    expect(rencana($plan))->toBe([2 => '50000.00']);
});

/** Penjaga R4 — berjalan paling awal, mengalahkan bawaan maupun pilihan petugas. */
it('redirects to early settlement when the money covers the whole loan', function () {
    tutup($this->loan, $this->schedules[0], 1050000);
    tutup($this->loan, $this->schedules[1], 1050000);
    tutup($this->loan, $this->schedules[2], 1050000);

    // Sisa 2 angsuran: tutup-sekalian 2.100.000, pelunasan hanya 2.040.000.
    expect($this->loan->payoffAmount())->toBe('2040000.00');

    $this->service->allocate(
        $this->loan,
        $this->schedules[3],
        2100000,
        LoanPaymentService::MODE_TUTUP_SEKALIAN,
    );
})->throws(CannotProcessPayment::class, 'Pelunasan Dipercepat');

it('redirects to early settlement in the default mode too', function () {
    tutup($this->loan, $this->schedules[0], 1050000);
    tutup($this->loan, $this->schedules[1], 1050000);
    tutup($this->loan, $this->schedules[2], 1050000);

    $this->service->allocate($this->loan, $this->schedules[3], 2040000);
})->throws(CannotProcessPayment::class, 'Pelunasan Dipercepat');

/**
 * Angsuran TERAKHIR tidak boleh dibelokkan ke pelunasan: tak ada jasa yang
 * dibebaskan di situ, sementara barisnya jadi `is_settlement` sehingga akrual
 * Tabungan Berjangka bulan itu hangus.
 */
it('does not redirect the final installment to early settlement', function () {
    foreach (range(0, 3) as $i) {
        tutup($this->loan, $this->schedules[$i], 1050000);
    }

    $plan = $this->service->allocate($this->loan, $this->schedules[4], 1050000);

    expect(rencana($plan))->toBe([5 => '1050000.00']);
});

it('does not redirect a jangka pendek loan', function () {
    $loan = Loan::factory()->jangkaPendek(500000)->create([
        'member_id' => Member::factory()->create()->id,
    ]);

    $schedule = InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => 1,
        'principal_due' => 500000,
        'interest_due' => 0,
        'time_deposit_due' => 0,
        'total_due' => 500000,
    ]);

    $plan = $this->service->allocate($loan, $schedule, 500000);

    expect(rencana($plan))->toBe([1 => '500000.00']);
});

it('refuses a schedule that is already paid', function () {
    tutup($this->loan, $this->schedules[0], 1050000);

    $this->service->allocate($this->loan, $this->schedules[0], 1050000);
})->throws(CannotProcessPayment::class, 'sudah terbayar');

/**
 * Model basi: statusnya BelumBayar di memori, sudah Terbayar di database.
 * Tanpa penjaga, alokasi bergeser diam-diam ke jadwal berikutnya.
 */
it('refuses a stale schedule that was paid in the meantime', function () {
    $stale = $this->schedules[0]->replicate();
    $stale->id = $this->schedules[0]->id;
    $stale->exists = true;

    $this->schedules[0]->update(['status' => InstallmentScheduleStatus::Terbayar]);

    $this->service->allocate($this->loan, $stale, 1050000);
})->throws(CannotProcessPayment::class, 'sudah terbayar');

it('refuses a schedule from another loan', function () {
    $other = Loan::factory()->create(['member_id' => Member::factory()->create()->id]);

    $this->service->allocate($other, $this->schedules[0], 1050000);
})->throws(InvalidArgumentException::class, 'bukan milik pinjaman ini');

it('refuses an unknown mode', function () {
    $this->service->allocate($this->loan, $this->schedules[0], 1050000, 'lunasin_aja');
})->throws(InvalidArgumentException::class, 'Mode alokasi tidak dikenal');

/**
 * Invariant 3f dalam bentuk rencana: `credit_applied = max(0, kontrak − dibayar)`
 * pada SETIAP baris, apa pun modenya.
 */
it('keeps credit_applied equal to the shortfall against the contract bill', function () {
    tutup($this->loan, $this->schedules[0], 3150000); // titipan 2.100.000

    $plan = $this->service->allocate(
        $this->loan,
        $this->schedules[1],
        150000,
        LoanPaymentService::MODE_TUTUP_SEKALIAN,
    );

    foreach ($plan['rows'] as $row) {
        $shortfall = bcsub((string) $row['schedule']->total_due, $row['amount_paid'], 2);
        $expected = bccomp($shortfall, '0', 2) > 0 ? $shortfall : '0.00';

        expect($row['credit_applied'])->toBe($expected);
    }

    // #2 dan #3 masing-masing memakai 1.000.000 titipan (2.100.000 → 100.000),
    // lalu baris #3 menyerap sisa uang 50.000 yang balik jadi titipan → 150.000.
    // Kekekalannya: 2.100.000 + (150.000 − 2 × 1.050.000) = 150.000.
    expect(rencana($plan))->toBe([2 => '50000.00', 3 => '100000.00'])
        ->and($plan['credit_after'])->toBe('150000.00');
});

/** Uang yang diterima harus sama dengan Σ baris — tak ada rupiah yang menguap. */
it('never loses a rupiah between the deposit and the rows', function () {
    tutup($this->loan, $this->schedules[0], 1050000);

    foreach ([1050000, 1100000, 2100000, 3000000] as $amount) {
        $plan = $this->service->allocate(
            $this->loan,
            $this->schedules[1],
            $amount,
            LoanPaymentService::MODE_TUTUP_SEKALIAN,
        );

        $total = collect($plan['rows'])->reduce(
            fn (string $carry, array $row) => bcadd($carry, $row['amount_paid'], 2),
            '0.00'
        );

        expect($total)->toBe(bcadd((string) $amount, '0', 2));
    }
});
