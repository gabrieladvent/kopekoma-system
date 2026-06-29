<?php

namespace Database\Factories;

use App\Models\InstallmentSchedule;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstallmentSchedule>
 */
class InstallmentScheduleFactory extends Factory
{
    protected $model = InstallmentSchedule::class;

    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'installment_seq' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'principal_due' => 1000000,
            'interest_due' => 78000,
            'time_deposit_due' => 12000,
            'total_due' => 1090000,
            'status' => 'Belum Bayar',
        ];
    }

    public function terbayar(): static
    {
        return $this->state(fn () => ['status' => 'Terbayar']);
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'due_date' => now()->subMonth()->toDateString(),
            'status' => 'Belum Bayar',
        ]);
    }
}
