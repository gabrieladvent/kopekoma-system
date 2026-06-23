<?php

namespace Database\Factories;

use App\Models\LoanBlacklist;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanBlacklist>
 */
class LoanBlacklistFactory extends Factory
{
    protected $model = LoanBlacklist::class;

    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'reason' => 'Angsuran macet berkepanjangan.',
            'is_active' => true,
            'blacklisted_at' => now()->toDateString(),
            'released_at' => null,
            'recorded_by' => fn () => User::factory()->create()->id,
        ];
    }

    public function released(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
            'released_at' => now()->toDateString(),
        ]);
    }
}
