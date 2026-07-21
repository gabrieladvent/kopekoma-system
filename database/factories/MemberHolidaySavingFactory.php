<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberHolidaySaving;
use Illuminate\Database\Eloquent\Factories\Factory;

class MemberHolidaySavingFactory extends Factory
{
    protected $model = MemberHolidaySaving::class;

    public function definition(): array
    {
        $year = (int) now()->year;

        return [
            'member_id' => Member::factory(),
            'period_year' => $year,
            'start_date' => "{$year}-01-01",
            'end_date' => "{$year}-12-31",
            'monthly_amount' => 100000,
            'is_active' => true,
            'notes' => null,
        ];
    }

    /**
     * Program penuh satu tahun kalender (collection Jan–Des, dibagi akhir tahun).
     */
    public function year(int $year): static
    {
        return $this->state(fn () => [
            'period_year' => $year,
            'start_date' => "{$year}-01-01",
            'end_date' => "{$year}-12-31",
        ]);
    }

    /**
     * Rentang pengumpulan eksplisit; period_year = tahun end_date (tahun pembagian).
     */
    public function range(string $startDate, string $endDate): static
    {
        return $this->state(fn () => [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'period_year' => (int) date('Y', strtotime($endDate)),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
