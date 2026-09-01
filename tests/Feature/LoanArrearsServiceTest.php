<?php

use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use App\Services\LoanArrearsService;
use App\Services\LoanPaymentService;

function arrears(): LoanArrearsService
{
    return app(LoanArrearsService::class);
}

it('warning null saat anggota tak punya tunggakan maupun riwayat telat', function () {
    $member = Member::factory()->create();
    $loan = Loan::factory()->for($member)->create();

    // Jadwal jatuh tempo bulan depan (belum overdue) + pembayaran tepat waktu.
    InstallmentSchedule::factory()->for($loan)->create();
    Installment::factory()->for($loan)->create([
        'payment_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);

    expect(arrears()->arrearsWarning($member))->toBeNull();
});

it('menghitung tunggakan berjalan (lewat tempo & belum bayar)', function () {
    $member = Member::factory()->create();
    $loan = Loan::factory()->for($member)->create();
    InstallmentSchedule::factory()->for($loan)->overdue()->count(2)->create();

    expect(arrears()->memberOverdueCount($member))->toBe(2)
        ->and(arrears()->arrearsWarning($member))->toContain('2 angsuran masih nunggak');
});

it('menghitung riwayat telat bayar (payment_date > due_date) meski sudah terbayar', function () {
    $member = Member::factory()->create();
    $loan = Loan::factory()->for($member)->create();

    // Dibayar 2 bulan setelah jatuh tempo — telat, tapi sudah lunas.
    Installment::factory()->for($loan)->create([
        'due_date' => now()->subMonths(3)->toDateString(),
        'payment_date' => now()->subMonth()->toDateString(),
    ]);

    expect(arrears()->memberOverdueCount($member))->toBe(0)
        ->and(arrears()->memberLatePaymentCount($member))->toBe(1)
        ->and(arrears()->arrearsWarning($member))->toContain('1 angsuran pernah dibayar telat');
});

it('mengabaikan baris reversal saat menghitung telat bayar', function () {
    $member = Member::factory()->create();
    $loan = Loan::factory()->for($member)->create();

    Installment::factory()->for($loan)->create([
        'due_date' => now()->subMonths(3)->toDateString(),
        'payment_date' => now()->subMonth()->toDateString(),
        'is_reversal' => true,
    ]);

    expect(arrears()->memberLatePaymentCount($member))->toBe(0);
});

it('menggabungkan tunggakan berjalan dan riwayat telat dalam satu warning', function () {
    $member = Member::factory()->create();
    $loan = Loan::factory()->for($member)->create();

    InstallmentSchedule::factory()->for($loan)->overdue()->create();
    Installment::factory()->for($loan)->create([
        'due_date' => now()->subMonths(2)->toDateString(),
        'payment_date' => now()->subMonth()->toDateString(),
    ]);

    $warning = arrears()->arrearsWarning($member);

    expect($warning)->toContain('1 angsuran masih nunggak')
        ->and($warning)->toContain('1 angsuran pernah dibayar telat');
});

/**
 * Item 2f (R13) — angka tunggakan memakai tagihan EFEKTIF. `total_due`
 * kontraktual melaporkan anggota bertitipan menunggak lebih besar dari
 * kewajiban riilnya.
 */
it('reports overdue amount net of the titipan', function () {
    $member = Member::factory()->create();

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
        'due_date' => now()->subMonths(6 - $seq)->toDateString(),
        'principal_due' => 1000000,
        'interest_due' => 40000,
        'time_deposit_due' => 10000,
        'total_due' => 1050000,
    ]));

    $service = app(LoanArrearsService::class);

    // Tanpa titipan: 5 jadwal tertunggak × 1.050.000.
    expect($service->overdueAmount())->toBe('5250000.00');

    // Bayar #1 dengan kelebihan → titipan 1.050.000.
    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 2100000], User::factory()->create()->id);

    // Sisa 4 tertunggak = 4.200.000. Titipan 1.050.000 dikuras berurutan:
    // #2 dipotong 1.000.000 (batas pokok), sisa 50.000 ikut memotong #3.
    expect($service->overdueAmount())->toBe('3150000.00');
});

it('drains the titipan across a loan instead of deducting it on every row', function () {
    $member = Member::factory()->create();

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
        'due_date' => now()->subMonths(6 - $seq)->toDateString(),
        'principal_due' => 1000000,
        'interest_due' => 40000,
        'time_deposit_due' => 10000,
        'total_due' => 1050000,
    ]));

    // Titipan 1.950.000 — cukup memotong pokok DUA angsuran, tidak semua.
    app(LoanPaymentService::class)->pay($schedules[0], ['amount_paid' => 3000000], User::factory()->create()->id);

    $bills = app(LoanArrearsService::class)->effectiveBills(
        InstallmentSchedule::query()->overdue()->with('loan')->get()
    );

    // #2 dipotong penuh 1.000.000, #3 dipotong sisanya 950.000, #4 & #5 utuh.
    expect(array_values($bills))->toBe(['50000.00', '100000.00', '1050000.00', '1050000.00']);
});
