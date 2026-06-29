<?php

use App\Services\LoanCalculator;

/**
 * Inti finansial modul Pinjaman (ADR D1/D1b). Rate dari CooperativeSettings
 * (default seeded: admin 1%, swp 1%, jasa 0,65%, tab 0,1%).
 */
beforeEach(function () {
    $this->calc = app(LoanCalculator::class);
});

it('computes disbursement deductions for jangka panjang (Dokumentasi §4.6)', function () {
    $d = $this->calc->disbursement('jangka_panjang', 12000000);

    expect($d['admin_fee'])->toBe('120000.00')
        ->and($d['swp_amount'])->toBe('120000.00')
        ->and($d['disbursed_amount'])->toBe('11760000.00');
});

it('applies no deductions for jangka pendek (Sebrakan)', function () {
    $d = $this->calc->disbursement('jangka_pendek', 500000);

    expect($d['admin_fee'])->toBe('0.00')
        ->and($d['swp_amount'])->toBe('0.00')
        ->and($d['disbursed_amount'])->toBe('500000.00');
});

it('computes constant monthly items from principal, not pokok (12jt/12bln = 1.090.000)', function () {
    $c = $this->calc->monthlyConstants('jangka_panjang', 12000000, 12);

    // Jasa = 12jt × 0,65% = 78.000 ; Tab = 12jt × 0,1% = 12.000 (BUKAN dari pokok)
    expect($c['monthly_principal'])->toBe('1000000.00')
        ->and($c['monthly_interest'])->toBe('78000.00')
        ->and($c['monthly_time_deposit'])->toBe('12000.00')
        ->and($this->calc->monthlyTotal('jangka_panjang', 12000000, 12))->toBe('1090000.00');
});

it('rounds pokok UP to whole rupiah so loan is fully covered', function () {
    // 10.000.000 / 12 = 833.333,33… → ceil = 833.334
    $c = $this->calc->monthlyConstants('jangka_panjang', 10000000, 12);

    expect($c['monthly_principal'])->toBe('833334.00');

    // Σ pokok ≥ principal (12 × 833.334 = 10.000.008 ≥ 10.000.000)
    $sumPokok = bcmul($c['monthly_principal'], '12', 2);
    expect(bccomp($sumPokok, '10000000.00', 2))->toBe(1);
});

it('builds a 12-row schedule with constant amounts and monthly due dates', function () {
    $rows = $this->calc->buildSchedule('jangka_panjang', 12000000, 12, '2026-07-10');

    expect($rows)->toHaveCount(12)
        ->and($rows[0]['due_date'])->toBe('2026-07-10')
        ->and($rows[1]['due_date'])->toBe('2026-08-10')
        ->and($rows[11]['due_date'])->toBe('2027-06-10')
        ->and($rows[0]['total_due'])->toBe('1090000.00')
        ->and($rows[11]['total_due'])->toBe('1090000.00')
        ->and($rows[11]['principal_due'])->toBe('1000000.00')
        ->and($rows[0]['status'])->toBe('Belum Bayar');
});

it('builds a single-row schedule for Sebrakan with zero jasa/tab', function () {
    $rows = $this->calc->buildSchedule('jangka_pendek', 500000, 1, '2026-07-10');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['principal_due'])->toBe('500000.00')
        ->and($rows[0]['interest_due'])->toBe('0.00')
        ->and($rows[0]['time_deposit_due'])->toBe('0.00')
        ->and($rows[0]['total_due'])->toBe('500000.00');
});
