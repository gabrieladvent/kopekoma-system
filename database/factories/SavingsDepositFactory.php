<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SavingsDepositFactory extends Factory
{
    protected $model = SavingsDeposit::class;

    public function definition(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => Member::factory(),
            'savings_type' => 'pokok',
            'amount' => 100000,
            'deposit_date' => now()->toDateString(),
            'period_month' => null,
            'deposit_method' => 'setor_sendiri',
            'deposited_by' => 'anggota',
            'recorded_by' => fn () => User::factory()->create()->id,
            'is_reversal' => false,
        ];
    }

    public function type(string $type): static
    {
        return $this->state(fn () => ['savings_type' => $type]);
    }

    public function holiday(int $year): static
    {
        return $this->state(fn () => [
            'savings_type' => 'hari_raya',
            'period_month' => "{$year}-01-01",
        ]);
    }
}
