<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    protected $model = Loan::class;

    public function definition(): array
    {
        // Default: jangka panjang 12.000.000 / 12 bulan (contoh Dokumentasi §4.6).
        return [
            'member_id' => Member::factory(),
            'loan_type' => 'jangka_panjang',
            'principal_amount' => 12000000,
            'admin_fee' => 120000,
            'swp_amount' => 120000,
            'disbursed_amount' => 11760000,
            'term_months' => 12,
            'monthly_principal' => 1000000,
            'monthly_interest' => 78000,
            'monthly_time_deposit' => 12000,
            'disbursement_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
            'status' => 'Cair',
            'recorded_by' => fn () => User::factory()->create()->id,
        ];
    }

    public function jangkaPendek(int|float $amount = 500000): static
    {
        return $this->state(fn () => [
            'loan_type' => 'jangka_pendek',
            'principal_amount' => $amount,
            'admin_fee' => 0,
            'swp_amount' => 0,
            'disbursed_amount' => $amount,
            'term_months' => 1,
            'monthly_principal' => $amount,
            'monthly_interest' => 0,
            'monthly_time_deposit' => 0,
        ]);
    }

    public function lunas(): static
    {
        return $this->state(fn () => ['status' => 'Lunas']);
    }
}
