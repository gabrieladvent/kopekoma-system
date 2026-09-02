<?php

use App\Enums\LoanStatus;
use App\Livewire\Reports\LaporanRekonsiliasiPinjaman;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\User;
use App\Services\LoanPaymentService;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Pembanding yang hilang saat SWP & Tabungan Berjangka jadi simpanan sungguhan.
 *
 * Dulu saldonya diturunkan dari tabel pinjaman, jadi tak bisa dipalsukan. Dua
 * guard kini menjaga pintunya, tapi guard hanya menutup pintu yang sudah
 * diketahui. Halaman ini menjawab: kalau ada pintu yang belum diketahui, dari
 * mana kita tahu?
 */
beforeEach(function () {
    $this->user = User::factory()->create();
});

function rekonLoan(string $memberId, int $userId, int $swp = 30000): array
{
    $loan = Loan::factory()->create([
        'member_id' => $memberId,
        'loan_type' => 'jangka_panjang',
        'principal_amount' => 2000000,
        'term_months' => 2,
        'monthly_principal' => 1000000,
        'monthly_interest' => 0,
        'monthly_time_deposit' => 12000,
        'swp_amount' => $swp,
        'recorded_by' => $userId,
    ]);

    $schedules = collect(range(1, 2))->map(fn (int $seq) => InstallmentSchedule::factory()->create([
        'loan_id' => $loan->id,
        'installment_seq' => $seq,
        'principal_due' => 1000000,
        'interest_due' => 0,
        'time_deposit_due' => 12000,
        'total_due' => 1012000,
    ]));

    return [$loan, $schedules];
}

it('is closed to petugas', function () {
    asPetugas();

    $this->get(route('reports.rekonsiliasi'))->assertForbidden();
});

/** Jalur normal harus menghasilkan halaman KOSONG — itu hasil yang benar. */
it('shows nothing when every balance matches', function () {
    asPengurus();

    $member = Member::factory()->create();
    [, $schedules] = rekonLoan($member->id, $this->user->id);

    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1012000], $this->user->id);

    $page = Livewire::test(LaporanRekonsiliasiPinjaman::class);

    expect($page->viewData('rows'))->toBe([]);

    $page->assertSee('Seluruhnya cocok');
});

/** Setoran SWP yang tak berdasar harus muncul sebagai selisih positif. */
it('catches an swp deposit with no loan behind it', function () {
    asPengurus();

    $member = Member::factory()->create();

    // Disisipkan langsung — meniru jalur yang lolos guard di masa depan.
    SavingsDeposit::factory()->create([
        'member_id' => $member->id,
        'savings_type' => 'swp',
        'amount' => 750000,
        'is_reversal' => false,
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $rows = Livewire::test(LaporanRekonsiliasiPinjaman::class)->viewData('rows');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['member_name'])->toBe($member->full_name)
        ->and($rows[0]['swp']['tercatat'])->toBe('750000.00')
        ->and($rows[0]['swp']['seharusnya'])->toBe('0.00')
        ->and($rows[0]['swp']['selisih'])->toBe('750000.00');
});

/** Pembalikan yang tak semestinya muncul sebagai selisih negatif. */
it('catches a tabungan berjangka deposit that went missing', function () {
    asPengurus();

    $member = Member::factory()->create();
    [, $schedules] = rekonLoan($member->id, $this->user->id);

    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1012000], $this->user->id);

    // Barisnya dihapus paksa — meniru kebocoran yang tak lewat pembalikan sah.
    SavingsDeposit::where('savings_type', 'tabungan_berjangka')->delete();

    $rows = Livewire::test(LaporanRekonsiliasiPinjaman::class)->viewData('rows');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['tabungan_berjangka']['tercatat'])->toBe('0.00')
        ->and($rows[0]['tabungan_berjangka']['seharusnya'])->toBe('12000.00')
        ->and($rows[0]['tabungan_berjangka']['selisih'])->toBe('-12000.00');
});

/** Pinjaman dibatalkan: setorannya ikut dibalik, jadi tetap cocok. */
it('stays clean after a loan is cancelled', function () {
    asPengurus();

    $member = Member::factory()->create();
    [$loan] = rekonLoan($member->id, $this->user->id);

    $loan->update(['status' => LoanStatus::Dibatalkan]);

    expect(Livewire::test(LaporanRekonsiliasiPinjaman::class)->viewData('rows'))->toBe([]);
});

/** Pelunasan dipercepat tak mengakru — pembandingnya harus ikut tahu itu. */
it('stays clean after an early settlement', function () {
    asPengurus();

    $member = Member::factory()->create();
    [$loan, $schedules] = rekonLoan($member->id, $this->user->id);

    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1012000], $this->user->id);

    $fresh = $loan->fresh();
    app(LoanPaymentService::class)->settleEarly($fresh, ['amount_paid' => $fresh->payoffAmount()], $this->user->id);

    expect(Livewire::test(LaporanRekonsiliasiPinjaman::class)->viewData('rows'))->toBe([]);
});

/** Dan setelah pembatalan angsuran — setoran ikut terbalik, tetap cocok. */
it('stays clean after an installment reversal', function () {
    asPengurus();

    $member = Member::factory()->create();
    [, $schedules] = rekonLoan($member->id, $this->user->id);

    $installment = app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 1012000], $this->user->id);
    app(LoanPaymentService::class)->reverse($installment, 'salah input', $this->user->id);

    expect(Livewire::test(LaporanRekonsiliasiPinjaman::class)->viewData('rows'))->toBe([]);
});
