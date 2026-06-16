<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Grade;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = (int) now()->format('Y');

        return [
            'member_number' => 'KM-'.$year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'full_name' => fake()->name(),
            'birth_place' => fake()->city(),
            'birth_date' => fake()->dateTimeBetween('-55 years', '-22 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(['L', 'P']),
            'nik' => fake()->unique()->numerify('################'),
            'nip' => fake()->numerify('##################'),
            'agency_id' => Agency::factory(),
            'position' => fake()->optional()->jobTitle(),
            'grade_id' => fn () => Grade::query()->inRandomOrder()->value('id')
                ?? Grade::create([
                    'code' => 'GOL-1',
                    'name' => 'Golongan I',
                    'mandatory_savings_amount' => 50000,
                    'is_active' => true,
                ])->id,
            'mandatory_savings_amount' => 50000,
            'employment_status' => fake()->randomElement(['ASN', 'Honorer']),
            'payroll_account_number' => fake()->numerify('##########'),
            'bank_name' => fake()->optional()->randomElement(['BRI', 'BNI', 'Mandiri']),
            'address' => fake()->address(),
            'phone_number' => '+628'.fake()->numerify('#########'),
            'join_date' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'exit_date' => null,
            'heir_name' => fake()->name(),
            'heir_relationship' => fake()->randomElement(['Istri', 'Suami', 'Anak', 'Orang Tua']),
            'heir_phone_number' => '+628'.fake()->numerify('#########'),
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
