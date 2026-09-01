<?php

use App\Livewire\Loan\Installment\BatchInstallmentPayment;
use App\Models\Agency;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Services\BatchInstallmentPaymentService;
use App\Services\LoanPaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Temuan security review atas ADR 2026-08-28 pada pintu potong gaji — pintu yang
 * BENAR-BENAR dipakai (panel Filament dimatikan).
 *
 * Tiga hal yang dikunci di sini:
 *  - jumlah pelunasan di layar batch memakai `Loan::payoffAmount()`, bukan rumus
 *    lokal yang lupa memotong Titipan Pokok (R2, salinan keempat);
 *  - baris yang dilewati meninggalkan DAFTAR beserta sebabnya, bukan cuma angka;
 *  - otoritas pelunasan ditegakkan juga di dalam service, bukan hanya di halaman.
 */
function batchLoanBertitipan(string $agencyId): array
{
    $member = Member::factory()->create(['agency_id' => $agencyId, 'status' => 'Aktif']);

    $loan = Loan::factory()->create([
        'member_id' => $member->id,
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

    return [$member, $loan, $schedules];
}

it('prefills the batch payoff net of titipan pokok', function () {
    $user = asPengurus();
    $agency = Agency::factory()->create();
    [, $loan, $schedules] = batchLoanBertitipan($agency->id);

    // Setoran 2× tagihan di angsuran #1 → titipan 1.050.000.
    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 2100000], $user->id);

    $line = Livewire::test(BatchInstallmentPayment::class)
        ->set('agency_id', $agency->id)
        ->get('rows')[0]['lines'][0];

    // Sisa pokok 4.000.000 + 1× jasa 40.000 − titipan 1.050.000.
    expect($line['payoff'])->toBe('2990000')
        ->and($line['payoff'])->toBe((string) (int) round((float) $loan->fresh()->payoffAmount()));
});

/**
 * Angka layar dipakai bendahara untuk memotong gaji. Bila ia lebih besar dari
 * yang benar-benar diutang, anggota bertitipan dipotong berlebih — kelebihannya
 * memang berakhir di Simpanan Sukarela, tapi uang itu tak pernah ia setujui
 * untuk pindah ke sana.
 */
it('keeps the batch payoff identical to what settleEarly() will enforce', function () {
    $user = asPengurus();
    $agency = Agency::factory()->create();
    [, $loan, $schedules] = batchLoanBertitipan($agency->id);

    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 2100000], $user->id);

    $line = Livewire::test(BatchInstallmentPayment::class)
        ->set('agency_id', $agency->id)
        ->get('rows')[0]['lines'][0];

    $result = app(BatchInstallmentPaymentService::class)->run($agency, '2026-06-01', [
        [
            'schedule_id' => $line['schedule_id'],
            'loan_id' => $loan->id,
            'settle_early' => true,
            'amount_paid' => $line['payoff'],
        ],
    ], $user->id);

    // Tak ada kelebihan yang perlu dialihkan: yang dipotong persis yang diutang.
    expect($result['created'])->toBe(1)
        ->and($loan->fresh()->status->value)->toBe('Lunas')
        ->and($loan->member->savingsDeposits()->where('savings_type', 'sukarela')->sum('amount'))->toEqual(0);
});

it('records which rows were skipped and why', function () {
    $user = asPengurus();
    $agency = Agency::factory()->create();
    [, $loan, $schedules] = batchLoanBertitipan($agency->id);

    // Angsuran #1 sudah dibayar manual lebih dulu — barisnya akan dilewati.
    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1050000], $user->id);

    $result = app(BatchInstallmentPaymentService::class)->run($agency, '2026-06-01', [
        ['schedule_id' => $schedules[0]->id, 'loan_id' => $loan->id, 'amount_paid' => '1050000'],
        ['schedule_id' => $schedules[1]->id, 'loan_id' => $loan->id, 'amount_paid' => '1050000'],
    ], $user->id);

    expect($result['created'])->toBe(1)
        ->and($result['skipped'])->toBe(1)
        ->and($result['skipped_rows'][0]['schedule_id'])->toBe((string) $schedules[0]->id)
        ->and($result['skipped_rows'][0]['loan_number'])->toBe($loan->loan_number)
        ->and($result['skipped_rows'][0]['reason'])->toBe('Jadwal sudah terbayar');

    // Dan jejaknya tersimpan di tempat yang memang DIRENDER panel audit (R22).
    $log = Activity::where('event', 'batch_angsuran_potong_gaji')->latest('id')->firstOrFail();

    expect($log->properties['attributes']['skipped_rows'][0]['loan_number'])->toBe($loan->loan_number)
        ->and($log->properties['attributes']['created'])->toBe(1);
});

it('refuses a settle_early row from a causer without the permission', function () {
    $petugas = asPetugas();
    $agency = Agency::factory()->create();
    [, $loan, $schedules] = batchLoanBertitipan($agency->id);

    expect($petugas->can('settle_early_installment'))->toBeFalse();

    expect(fn () => app(BatchInstallmentPaymentService::class)->run($agency, '2026-06-01', [
        [
            'schedule_id' => $schedules[0]->id,
            'loan_id' => $loan->id,
            'settle_early' => true,
            'amount_paid' => (string) (int) round((float) $loan->payoffAmount()),
        ],
    ], $petugas->id))->toThrow(AuthorizationException::class);

    expect($loan->fresh()->status->value)->toBe('Cair');
});
