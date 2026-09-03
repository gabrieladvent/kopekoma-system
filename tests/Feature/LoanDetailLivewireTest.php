<?php

use App\Enums\LoanStatus;
use App\Livewire\Loan\LoanDetail;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanPaymentService;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** Pinjaman Cair dengan N jadwal proyeksi (belum terbayar). */
function cairLoanWithSchedules(int $schedules = 2): Loan
{
    $member = Member::factory()->create();
    $loan = Loan::factory()->create([
        'member_id' => $member->id,
        'status' => 'Cair',
        'principal_amount' => 1000000,
        'term_months' => $schedules,
        'monthly_principal' => 1000000,
        'monthly_interest' => 6500,
        'monthly_time_deposit' => 1000,
    ]);

    collect(range(1, $schedules))->each(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'total_due' => 1007500,
    ]));

    return $loan;
}

it('cancels a clean loan to Dibatalkan, keeping the record but clearing schedules', function () {
    asSuperAdmin();
    $loan = cairLoanWithSchedules();

    Livewire::test(LoanDetail::class, ['loan' => $loan])
        ->call('openCorrect')
        ->set('correctReason', 'Salah input nominal pokok pinjaman')
        ->call('performCorrect')
        ->assertHasNoErrors();

    $loan->refresh();

    // Record DIPERTAHANKAN (bukan dihapus), status jadi Dibatalkan, jadwal dibersihkan.
    expect(Loan::find($loan->id))->not->toBeNull()
        ->and($loan->status)->toBe(LoanStatus::Dibatalkan)
        ->and($loan->schedules()->count())->toBe(0)
        ->and(Activity::where('event', 'koreksi')->where('subject_id', $loan->id)->exists())->toBeTrue();
});

it('requires a cancellation reason (min 5 chars)', function () {
    asSuperAdmin();
    $loan = cairLoanWithSchedules();

    Livewire::test(LoanDetail::class, ['loan' => $loan])
        ->call('openCorrect')
        ->set('correctReason', 'x')
        ->call('performCorrect')
        ->assertHasErrors('correctReason');

    expect($loan->fresh()->status)->toBe(LoanStatus::Cair); // tidak berubah
});

it('refuses to cancel a loan that already has a recorded installment', function () {
    asSuperAdmin();
    $loan = cairLoanWithSchedules();
    Installment::factory()->create(['loan_id' => $loan->id, 'is_reversal' => false]);

    // Gating: tombol Batalkan tak muncul karena sudah ada angsuran terbayar.
    expect(Livewire::test(LoanDetail::class, ['loan' => $loan])->instance()->canCorrect($loan->fresh()))
        ->toBeFalse();

    // Aksi pun ditolak server-side (abort 403) — status tetap Cair.
    Livewire::test(LoanDetail::class, ['loan' => $loan])
        ->call('openCorrect')
        ->assertStatus(403);

    expect($loan->fresh()->status)->toBe(LoanStatus::Cair);
});

/**
 * Sisa pokok di panel Progres Angsuran.
 *
 * Dulu dibaca dari `installments.remaining_principal` — kolom yang tak pernah
 * ada di tabelnya. Atribut yang tak ada bernilai null, jadi fallback `??`
 * selalu menang dan angkanya membeku di pokok awal: anggota yang sudah
 * menyicil setahun tetap terlihat berutang penuh oleh pengurus.
 */
it('counts down sisa pokok as installments are paid', function () {
    asSuperAdmin();

    $member = Member::factory()->create();
    $loan = Loan::factory()->create([
        'member_id' => $member->id,
        'status' => 'Cair',
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 12000000,
        'term_months' => 12,
        'monthly_principal' => 1000000,
        'monthly_interest' => 78000,
        'monthly_time_deposit' => 12000,
    ]);

    $schedules = collect(range(1, 12))->map(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 78000,
        'time_deposit_due' => 12000,
        'total_due' => 1090000,
    ]));

    $remaining = fn () => Livewire::test(LoanDetail::class, ['loan' => $loan])
        ->viewData('progress')['remaining'];

    expect($remaining())->toBe('12000000.00');

    $service = app(LoanPaymentService::class);
    $user = auth()->id();

    // Bayar pas.
    $service->pay($schedules[0], ['amount_paid' => 1090000], $user);
    expect($remaining())->toBe('11000000.00');

    // Kelebihan bayar: pokoknya tetap turun satu angsuran, tidak dua. Titipan
    // memotong tagihan BULAN BERIKUTNYA, bukan sisa pokok bulan ini.
    $service->pay($schedules[1], ['amount_paid' => 2180000], $user);
    expect($remaining())->toBe('10000000.00');

    // Tagihan efektif 90.000 (titipan menutup pokoknya) — tetap satu angsuran.
    $service->pay($schedules[2], ['amount_paid' => 90000], $user);
    expect($remaining())->toBe('9000000.00');
});

/** Pembatalan angsuran harus MENAIKKAN kembali sisa pokok. */
it('restores sisa pokok after an installment is reversed', function () {
    asSuperAdmin();

    $loan = Loan::factory()->create([
        'member_id' => Member::factory()->create()->id,
        'status' => 'Cair',
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 3000000,
        'term_months' => 3,
        'monthly_principal' => 1000000,
        'monthly_interest' => 20000,
        'monthly_time_deposit' => 5000,
    ]);

    $schedule = InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => 1,
        'principal_due' => 1000000,
        'interest_due' => 20000,
        'time_deposit_due' => 5000,
        'total_due' => 1025000,
    ]);

    $service = app(LoanPaymentService::class);
    $installment = $service->pay($schedule, ['amount_paid' => 1025000], auth()->id());

    $remaining = fn () => Livewire::test(LoanDetail::class, ['loan' => $loan->fresh()])
        ->viewData('progress')['remaining'];

    expect($remaining())->toBe('2000000.00');

    $service->reverse($installment, 'salah input', auth()->id());

    expect($remaining())->toBe('3000000.00');
});
