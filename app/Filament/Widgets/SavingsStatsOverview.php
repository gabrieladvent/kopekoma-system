<?php

namespace App\Filament\Widgets;

use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\ShoppingTransaction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SavingsStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $now = now();

        $depositNet = (float) SavingsDeposit::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount ELSE -amount END), 0) as net')
            ->value('net');

        $withdrawalNet = (float) SavingsWithdrawal::query()
            ->where('status', 'cair')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount ELSE -amount END), 0) as net')
            ->value('net');

        $shoppingNet = (float) ShoppingTransaction::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount ELSE -amount END), 0) as net')
            ->value('net');

        $totalSavings = $depositNet - $withdrawalNet - $shoppingNet;

        $monthlyDeposits = SavingsDeposit::query()
            ->where('is_reversal', false)
            ->whereYear('deposit_date', $now->year)
            ->whereMonth('deposit_date', $now->month);

        $monthlySum = (float) (clone $monthlyDeposits)->sum('amount');
        $monthlyCount = (clone $monthlyDeposits)->count();

        $activeMembers = Member::query()->where('status', 'Aktif')->count();

        return [
            Stat::make('Total Simpanan', 'Rp '.number_format($totalSavings, 0, ',', '.'))
                ->description('Net seluruh simpanan anggota')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
            Stat::make('Setoran Bulan Ini', 'Rp '.number_format($monthlySum, 0, ',', '.'))
                ->description($monthlyCount.' transaksi · '.$now->translatedFormat('F Y'))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary'),
            Stat::make('Anggota Aktif', number_format($activeMembers, 0, ',', '.'))
                ->description('Berstatus aktif')
                ->descriptionIcon('heroicon-m-users')
                ->color('gray'),
        ];
    }
}
