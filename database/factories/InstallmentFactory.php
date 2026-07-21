<?php

namespace Database\Factories;

use App\Models\Installment;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Installment>
 */
class InstallmentFactory extends Factory
{
    protected $model = Installment::class;

    public function definition(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'loan_id' => Loan::factory(),
            'schedule_id' => null,
            'installment_seq' => 1,
            'payment_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'amount_paid' => 1090000,
            'payment_method' => 'manual',
            'is_reversal' => false,
            'recorded_by' => fn () => User::factory()->create()->id,
        ];
    }

    public function potongGaji(): static
    {
        return $this->state(fn () => ['payment_method' => 'potong_gaji']);
    }
}
