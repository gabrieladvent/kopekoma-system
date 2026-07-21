<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SavingsWithdrawal>
 */
class SavingsWithdrawalFactory extends Factory
{
    protected $model = SavingsWithdrawal::class;

    public function definition(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => Member::factory(),
            'savings_type' => 'sukarela',
            'amount' => 50000,
            'withdrawal_date' => now()->toDateString(),
            'status' => 'draft',
            'period_year' => null,
            'recorded_by' => fn () => User::factory()->create()->id,
            'is_reversal' => false,
        ];
    }

    public function type(string $type): static
    {
        return $this->state(fn () => ['savings_type' => $type]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function cair(): static
    {
        return $this->state(fn () => [
            'status' => 'cair',
            'disbursed_at' => now(),
        ]);
    }

    public function holiday(int $year): static
    {
        return $this->state(fn () => [
            'savings_type' => 'hari_raya',
            'period_year' => $year,
        ]);
    }
}
