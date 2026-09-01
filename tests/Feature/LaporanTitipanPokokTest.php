<?php

use App\Livewire\Reports\LaporanTitipanPokok;
use App\Models\Agency;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanPaymentService;
use Livewire\Livewire;

/**
 * Item 2i (ADR 2026-08-28) — laporan agregat Titipan Pokok.
 *
 * Panel Riwayat per-pinjaman hanya bisa MENGONFIRMASI kecurigaan. Halaman ini
 * yang MEMUNCULKANNYA, dan itu syarat agar pendeteksian pasca-kejadian benar-
 * benar berfungsi sebagai pengaman R14 yang diterima sadar (OQ-0).
 */
function laporanLoan(string $agencyId, string $name): array
{
    $member = Member::factory()->create([
        'agency_id' => $agencyId,
        'status' => 'Aktif',
        'full_name' => $name,
    ]);

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

beforeEach(function () {
    $this->agency = Agency::factory()->create(['agency_name' => 'Dinas A']);
});

it('is closed to petugas — this is the channel that audits them', function () {
    asPetugas();

    $this->get(route('reports.titipan'))->assertForbidden();
});

it('is open to pengurus', function () {
    asPengurus();

    $this->get(route('reports.titipan'))->assertOk();
});

it('lists only loans that actually hold titipan, largest first', function () {
    $user = asPengurus();
    $service = app(LoanPaymentService::class);

    [, , $besar] = laporanLoan($this->agency->id, 'Anggota Besar');
    [, , $kecil] = laporanLoan($this->agency->id, 'Anggota Kecil');
    laporanLoan($this->agency->id, 'Anggota Pas'); // tak pernah bayar — titipan 0

    $service->pay($besar[0], ['amount_paid' => 2050000], $user->id);   // titipan 1.000.000
    $service->pay($kecil[0], ['amount_paid' => 1250000], $user->id);   // titipan   200.000

    $rows = Livewire::test(LaporanTitipanPokok::class)->viewData('rows');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['member_name'])->toBe('Anggota Besar')
        ->and($rows[0]['credit'])->toBe('1000000.00')
        ->and($rows[1]['member_name'])->toBe('Anggota Kecil')
        ->and($rows[1]['credit'])->toBe('200000.00');
});

/**
 * Selisih kontrak − efektif adalah persis nominal yang bisa dikantongi bila
 * petugas menerima uang sebesar kontrak tapi mencatat yang efektif. Keduanya
 * wajib tampil berdampingan, bukan salah satu saja.
 */
it('shows the contract bill beside the effective bill', function () {
    $user = asPengurus();

    [, , $schedules] = laporanLoan($this->agency->id, 'Anggota Besar');

    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 2050000], $user->id);

    $row = Livewire::test(LaporanTitipanPokok::class)->viewData('rows')[0];

    expect($row['next_seq'])->toBe(2)
        ->and($row['contract_bill'])->toBe('1050000.00')
        // 1.050.000 − min(titipan 1.000.000, pokok 1.000.000).
        ->and($row['effective_bill'])->toBe('50000.00');
});

it('totals the titipan across the whole cooperative', function () {
    $user = asPengurus();
    $service = app(LoanPaymentService::class);

    [, , $a] = laporanLoan($this->agency->id, 'Anggota A');
    [, , $b] = laporanLoan($this->agency->id, 'Anggota B');

    $service->pay($a[0], ['amount_paid' => 2050000], $user->id);
    $service->pay($b[0], ['amount_paid' => 1250000], $user->id);

    $total = Livewire::test(LaporanTitipanPokok::class)->viewData('total');

    expect($total)->toBe('1200000.00');
});

it('filters by OPD and by search', function () {
    $user = asPengurus();
    $service = app(LoanPaymentService::class);
    $lain = Agency::factory()->create(['agency_name' => 'Dinas B']);

    [, , $a] = laporanLoan($this->agency->id, 'Anggota Dinas A');
    [, , $b] = laporanLoan($lain->id, 'Anggota Dinas B');

    $service->pay($a[0], ['amount_paid' => 2050000], $user->id);
    $service->pay($b[0], ['amount_paid' => 2050000], $user->id);

    $page = Livewire::test(LaporanTitipanPokok::class);

    expect($page->viewData('rows'))->toHaveCount(2);

    $page->set('agency', $this->agency->id);
    expect($page->viewData('rows'))->toHaveCount(1)
        ->and($page->viewData('rows')[0]['member_name'])->toBe('Anggota Dinas A');

    $page->call('clearFilters')->set('search', 'Dinas B');
    expect($page->viewData('rows'))->toHaveCount(1)
        ->and($page->viewData('rows')[0]['member_name'])->toBe('Anggota Dinas B');
});

/**
 * Pinjaman yang sudah Lunas bertitipan 0 menurut rumusnya sendiri — sisanya
 * sudah dilimpahkan ke Sukarela. Ia tak boleh muncul di sini seolah masih ada
 * uang mengendap.
 */
it('drops a loan once it is paid off', function () {
    $user = asPengurus();
    $service = app(LoanPaymentService::class);

    [, $loan, $schedules] = laporanLoan($this->agency->id, 'Anggota Lunas');

    $service->pay($schedules[0], ['amount_paid' => 2050000], $user->id);

    expect(Livewire::test(LaporanTitipanPokok::class)->viewData('rows'))->toHaveCount(1);

    $fresh = $loan->fresh();
    $service->settleEarly($fresh, ['amount_paid' => $fresh->payoffAmount()], $user->id);

    expect(Livewire::test(LaporanTitipanPokok::class)->viewData('rows'))->toBe([]);
});

/**
 * Rumus saldo hanya boleh hidup di satu tempat. Bentuk jamak yang dipakai
 * laporan dan bentuk tunggal yang dipakai seluruh sistem wajib sepakat — bila
 * mereka pernah menyimpang, itu R2 lagi dalam bentuk baru.
 */
it('agrees with the per-loan formula it delegates to', function () {
    $user = asPengurus();
    $service = app(LoanPaymentService::class);

    [, $loanA, $a] = laporanLoan($this->agency->id, 'Anggota A');
    [, $loanB, $b] = laporanLoan($this->agency->id, 'Anggota B');

    $service->pay($a[0], ['amount_paid' => 2050000], $user->id);
    $service->pay($b[0], ['amount_paid' => 1250000], $user->id);

    $bulk = Loan::overpaymentCredits([$loanA->fresh(), $loanB->fresh()]);

    expect($bulk[$loanA->id])->toBe($loanA->fresh()->overpaymentCredit())
        ->and($bulk[$loanB->id])->toBe($loanB->fresh()->overpaymentCredit());
});
