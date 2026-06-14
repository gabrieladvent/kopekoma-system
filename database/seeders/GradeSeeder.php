<?php

namespace Database\Seeders;

use App\Models\Grade;
use Illuminate\Database\Seeder;

class GradeSeeder extends Seeder
{
    public function run(): void
    {
        $grades = [
            ['code' => 'HR-THL', 'name' => 'Honorer / THL', 'mandatory_savings_amount' => 30000],
            ['code' => 'GOL-1', 'name' => 'Golongan I', 'mandatory_savings_amount' => 50000],
            ['code' => 'GOL-2', 'name' => 'Golongan II', 'mandatory_savings_amount' => 75000],
            ['code' => 'GOL-3', 'name' => 'Golongan III', 'mandatory_savings_amount' => 100000],
            ['code' => 'GOL-4', 'name' => 'Golongan IV', 'mandatory_savings_amount' => 150000],
        ];

        foreach ($grades as $grade) {
            Grade::updateOrCreate(
                ['code' => $grade['code']],
                $grade + ['is_active' => true],
            );
        }
    }
}
