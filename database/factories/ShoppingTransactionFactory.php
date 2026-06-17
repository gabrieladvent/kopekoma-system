<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\ShoppingTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ShoppingTransactionFactory extends Factory
{
    protected $model = ShoppingTransaction::class;

    public function definition(): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => Member::factory(),
            'amount' => 25000,
            'transaction_date' => now()->toDateString(),
            'source' => 'manual',
            'recorded_by' => fn () => User::factory()->create()->id,
            'is_reversal' => false,
        ];
    }
}
