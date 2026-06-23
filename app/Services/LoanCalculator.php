<?php

namespace App\Services;

use App\Settings\CooperativeSettings;
use Illuminate\Support\Carbon;

/**
 * Inti finansial modul Pinjaman (ADR D1/D1b). Service stateless & deterministik:
 * menghitung potongan pencairan, konstanta angsuran per bulan, dan jadwal.
 *
 * Aturan kunci:
 * - Jasa & Tabungan Berjangka dihitung dari **principal_amount** (jumlah pinjaman
 *   diajukan), BUKAN pokok per bulan.
 * - Pokok per bulan = `ceil(principal / term)` ke rupiah utuh (bulat ke ATAS) →
 *   `Σ pokok ≥ principal` (pinjaman pasti lunas penuh).
 * - Ketiga komponen KONSTAN tiap bulan.
 * - Semua aritmetika pakai bcmath string, bukan float.
 */
class LoanCalculator
{
    private const SCALE = 2;

    public function __construct(private readonly CooperativeSettings $settings) {}

    /**
     * Potongan saat pencairan + dana diterima anggota.
     *
     * @return array{admin_fee:string, swp_amount:string, disbursed_amount:string}
     */
    public function disbursement(string $loanType, string|int|float $principalAmount): array
    {
        $principal = $this->money((string) $principalAmount);

        if ($loanType === 'jangka_pendek') {
            return [
                'admin_fee' => '0.00',
                'swp_amount' => '0.00',
                'disbursed_amount' => $principal,
            ];
        }

        $admin = $this->applyRate($principal, $this->settings->loan_admin_fee_rate);
        $swp = $this->applyRate($principal, $this->settings->loan_swp_rate);
        $disbursed = bcsub(bcsub($principal, $admin, self::SCALE), $swp, self::SCALE);

        return [
            'admin_fee' => $admin,
            'swp_amount' => $swp,
            'disbursed_amount' => $disbursed,
        ];
    }

    /**
     * Konstanta tagihan angsuran per bulan (dikunci saat akad → loans.monthly_*).
     *
     * @return array{monthly_principal:string, monthly_interest:string, monthly_time_deposit:string}
     */
    public function monthlyConstants(string $loanType, string|int|float $principalAmount, int $termMonths): array
    {
        $principal = $this->money((string) $principalAmount);

        if ($loanType === 'jangka_pendek') {
            // Sebrakan: dilunasi penuh sekali, tanpa jasa/tab berjangka.
            return [
                'monthly_principal' => $principal,
                'monthly_interest' => '0.00',
                'monthly_time_deposit' => '0.00',
            ];
        }

        return [
            'monthly_principal' => $this->ceilPrincipalPerMonth($principal, $termMonths),
            'monthly_interest' => $this->applyRate($principal, $this->settings->loan_interest_rate),
            'monthly_time_deposit' => $this->applyRate($principal, $this->settings->loan_time_deposit_rate),
        ];
    }

    /**
     * Total tagihan angsuran per bulan (konstan).
     */
    public function monthlyTotal(string $loanType, string|int|float $principalAmount, int $termMonths): string
    {
        $c = $this->monthlyConstants($loanType, $principalAmount, $termMonths);

        return bcadd(
            bcadd($c['monthly_principal'], $c['monthly_interest'], self::SCALE),
            $c['monthly_time_deposit'],
            self::SCALE
        );
    }

    /**
     * Jadwal angsuran: N baris (jangka panjang) atau 1 baris (Sebrakan, jasa/tab=0).
     *
     * @return list<array{installment_seq:int, due_date:string, principal_due:string, interest_due:string, time_deposit_due:string, total_due:string, status:string}>
     */
    public function buildSchedule(string $loanType, string|int|float $principalAmount, int $termMonths, Carbon|string $firstDueDate): array
    {
        $c = $this->monthlyConstants($loanType, $principalAmount, $termMonths);
        $total = bcadd(
            bcadd($c['monthly_principal'], $c['monthly_interest'], self::SCALE),
            $c['monthly_time_deposit'],
            self::SCALE
        );

        $first = $firstDueDate instanceof Carbon ? $firstDueDate->copy() : Carbon::parse($firstDueDate);
        $count = $loanType === 'jangka_pendek' ? 1 : max(1, $termMonths);

        $rows = [];
        for ($seq = 1; $seq <= $count; $seq++) {
            $rows[] = [
                'installment_seq' => $seq,
                'due_date' => $first->copy()->addMonthsNoOverflow($seq - 1)->toDateString(),
                'principal_due' => $c['monthly_principal'],
                'interest_due' => $c['monthly_interest'],
                'time_deposit_due' => $c['monthly_time_deposit'],
                'total_due' => $total,
                'status' => 'Belum Bayar',
            ];
        }

        return $rows;
    }

    /**
     * Pokok per bulan = pembagian dibulatkan ke ATAS ke rupiah utuh.
     */
    private function ceilPrincipalPerMonth(string $principal, int $termMonths): string
    {
        $term = (string) max(1, $termMonths);

        $exact = bcdiv($principal, $term, 6);
        $floor = bcdiv($principal, $term, 0); // truncate (principal ≥ 0)

        if (bccomp($exact, $floor, 6) === 1) {
            $floor = bcadd($floor, '1', 0);
        }

        return $this->money($floor);
    }

    /**
     * principal × rate, dibulatkan (half-up) ke 2 desimal.
     */
    private function applyRate(string $principal, float $rate): string
    {
        $product = bcmul($principal, $this->rateString($rate), 8);

        return $this->roundHalfUp($product);
    }

    private function rateString(float $rate): string
    {
        return number_format($rate, 8, '.', '');
    }

    private function roundHalfUp(string $value): string
    {
        $delta = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd($value, $delta, self::SCALE);
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', self::SCALE);
    }
}
