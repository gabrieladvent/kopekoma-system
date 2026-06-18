<?php

namespace App\Filament\Widgets;

use App\Models\SavingsDeposit;
use Filament\Widgets\ChartWidget;

class SavingsCashInflowChart extends ChartWidget
{
    protected static ?string $heading = 'Arus Uang Masuk (Setoran / Bulan)';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $labels = [];
        $data = [];

        foreach (range(5, 0) as $offset) {
            $month = now()->subMonths($offset);

            $labels[] = $month->translatedFormat('M Y');
            $data[] = (float) SavingsDeposit::query()
                ->where('is_reversal', false)
                ->whereYear('deposit_date', $month->year)
                ->whereMonth('deposit_date', $month->month)
                ->sum('amount');
        }

        return [
            'datasets' => [
                [
                    'label' => 'Setoran (Rp)',
                    'data' => $data,
                    'backgroundColor' => 'rgba(16, 185, 129, 0.4)',
                    'borderColor' => 'rgb(16, 185, 129)',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
