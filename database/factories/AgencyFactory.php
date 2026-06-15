<?php

namespace Database\Factories;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    protected $model = Agency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_code' => strtoupper(Str::random(6)),
            'agency_name' => 'Dinas '.fake()->unique()->words(2, true),
            'address' => fake()->address(),
            'payroll_treasurer' => fake()->name(),
            'pic_phone_number' => fake()->numerify('08##########'),
            'status' => 'Aktif',
        ];
    }

    public function nonActive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Non-Aktif',
        ]);
    }
}
