<?php

use App\Enums\LoanStatus;
use App\Livewire\Loan\LoanDetail;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
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
