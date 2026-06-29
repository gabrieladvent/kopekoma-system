<?php

use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanArrearsService;

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
