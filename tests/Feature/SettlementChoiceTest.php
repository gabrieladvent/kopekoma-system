<?php

use App\Enums\LoanStatus;
use App\Livewire\Loan\Installment\InstallmentForm;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanPaymentService;
use Livewire\Livewire;

/**
 * Uang yang cukup melunasi: **ditawarkan, bukan ditolak**.
 *
 * Penjaga lama melempar "Gunakan Pelunasan Dipercepat" dan berhenti di situ.
 * Niatnya melindungi — anggota tak boleh diam-diam membayar penuh sementara
 * jasa bulan sisa yang seharusnya dibebaskan tetap ditagih. Tapi ia juga
 * melarang keadaan yang sah: anggota yang membawa uang lebih dan memang ingin
 * pinjamannya berjalan terus. Petugas tak punya jalan selain menyuruhnya pulang.
 *
 * Sekarang keduanya disajikan dalam rupiah lalu petugas memilih — dan penjaga
 * hanya dimatikan SETELAH pilihan itu diambil.
 */
function settlementChoiceLoan(): array
{
    $loan = Loan::factory()->create([
        'member_id' => Member::factory()->create()->id,
        'status' => LoanStatus::Cair,
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

    // Sepuluh angsuran terbayar → sisa pokok 2.000.000, dua jadwal tersisa.
    $service = app(LoanPaymentService::class);
    foreach ($schedules->take(10) as $paid) {
        $service->pay($paid, ['amount_paid' => 1090000], test()->user->id);
    }

    return [$loan->fresh(), $schedules[10]];
}

beforeEach(function () {
    $this->user = asPengurus();
});

it('offers a choice instead of rejecting the payment outright', function () {
    [$loan, $next] = settlementChoiceLoan();

    $page = Livewire::test(InstallmentForm::class)
        ->set('member_id', $loan->member_id)
        ->set('loan_id', $loan->id)
        ->set('schedule_id', $next->id)
        ->set('amount_paid', 2500000)
        ->call('pay');

    // Tidak ada error — dan tidak ada baris yang tercatat diam-diam.
    $page->assertHasNoErrors()
        ->assertSet('showSettlementChoice', true);

    expect($loan->fresh()->installments()->where('is_settlement', false)->count())->toBe(10);
});

/** Angkanya harus dari payoffAmount(), bukan salinan rumus lokal. */
it('quotes the payoff and the interest it waives', function () {
    [$loan, $next] = settlementChoiceLoan();

    $offer = Livewire::test(InstallmentForm::class)
        ->set('member_id', $loan->member_id)
        ->set('loan_id', $loan->id)
        ->set('schedule_id', $next->id)
        ->set('amount_paid', 2500000)
        ->instance()
        ->settlementOffer();

    expect($offer['payoff'])->toBe($loan->payoffAmount())
        // Dua jadwal tersisa → 1× jasa ditagih, 1 bulan dibebaskan.
        ->and($offer['sisa_bulan'])->toBe(2)
        ->and($offer['jasa_dibebaskan'])->toBe('78000.00');
});

it('lets the member keep installing when that is what they asked for', function () {
    [$loan, $next] = settlementChoiceLoan();

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $loan->member_id)
        ->set('loan_id', $loan->id)
        ->set('schedule_id', $next->id)
        ->set('amount_paid', 2500000)
        ->call('pay')
        ->call('chooseKeepInstalling')
        ->assertHasNoErrors();

    $fresh = $loan->fresh();

    expect($fresh->status)->toBe(LoanStatus::Cair)
        ->and($fresh->installments()->where('is_settlement', true)->count())->toBe(0);

    // 2.500.000 − kontrak 1.090.000 = 1.410.000 jadi Titipan Pokok.
    expect($fresh->overpaymentCredit())->toBe('1410000.00');
});

it('settles the loan when that is what they asked for', function () {
    [$loan, $next] = settlementChoiceLoan();

    Livewire::test(InstallmentForm::class)
        ->set('member_id', $loan->member_id)
        ->set('loan_id', $loan->id)
        ->set('schedule_id', $next->id)
        ->set('amount_paid', 2500000)
        ->call('pay')
        ->call('chooseSettlement')
        ->assertHasNoErrors();

    expect($loan->fresh()->status)->toBe(LoanStatus::Lunas);
});

/**
 * Penjaga hanya boleh mati SETELAH pilihan diambil. Sebagai properti publik
 * biasa, klien tinggal mengirim `keepInstalling = true` dan perlindungannya
 * hilang tanpa anggota pernah diberi tahu ada yang lebih ringan.
 */
it('refuses to let the client skip the choice', function () {
    [$loan, $next] = settlementChoiceLoan();

    expect(fn () => Livewire::test(InstallmentForm::class)
        ->set('member_id', $loan->member_id)
        ->set('loan_id', $loan->id)
        ->set('schedule_id', $next->id)
        ->set('amount_paid', 2500000)
        ->set('keepInstalling', true))
        ->toThrow(Exception::class, 'Cannot update locked property: [keepInstalling]');
});

/** Satu jadwal tersisa: "pelunasan" tak membebaskan apa pun — jangan ditawarkan. */
it('does not offer settlement on the final installment', function () {
    [$loan, $next] = settlementChoiceLoan();

    app(LoanPaymentService::class)->pay($next, ['amount_paid' => 1090000], $this->user->id);

    $last = InstallmentSchedule::query()
        ->where('loan_id', $loan->id)
        ->where('installment_seq', 12)
        ->first();

    $offer = Livewire::test(InstallmentForm::class)
        ->set('member_id', $loan->member_id)
        ->set('loan_id', $loan->id)
        ->set('schedule_id', $last->id)
        ->set('amount_paid', 2000000)
        ->instance()
        ->settlementOffer();

    expect($offer)->toBeNull();
});
