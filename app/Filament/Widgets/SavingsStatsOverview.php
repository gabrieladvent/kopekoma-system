<?php

namespace App\Filament\Widgets;

use App\Enums\LoanStatus;
use App\Enums\WithdrawalStatus;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\ShoppingTransaction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SavingsStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $now = now();

        $depositNet = (float) SavingsDeposit::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount ELSE -amount END), 0) as net')
            ->value('net');

        $withdrawalNet = (float) SavingsWithdrawal::query()
            ->where('status', WithdrawalStatus::Cair)
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

        // Pinjaman berjalan + sisa pokok agregat: principal_amount dikurangi
        // (monthly_principal × jumlah angsuran net) per pinjaman, floor 0.
        $activeLoans = Loan::query()->active()->count();
        $outstandingPrincipal = (float) Loan::query()
            ->where('loans.status', LoanStatus::Cair)
            ->leftJoinSub(
                'SELECT loan_id, SUM(CASE WHEN is_reversal = 0 THEN 1 ELSE -1 END) AS net'
                .' FROM installments GROUP BY loan_id',
                'ic',
                'ic.loan_id',
                '=',
                'loans.id'
            )
            // CASE WHEN, bukan GREATEST(): GREATEST tidak ada di SQLite yang
            // dipakai suite tes, sementara produksi MySQL. Semantiknya identik.
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN loans.principal_amount'
                .' - loans.monthly_principal * COALESCE(ic.net, 0) > 0'
                .' THEN loans.principal_amount'
                .' - loans.monthly_principal * COALESCE(ic.net, 0)'
                .' ELSE 0 END), 0) AS outstanding'
            )
            ->value('outstanding');

        // Tunggakan: jadwal angsuran yang jatuh tempo namun belum terbayar.
        $overdue = InstallmentSchedule::query()->overdue();
        $overdueCount = (clone $overdue)->count();
        $overdueAmount = (float) (clone $overdue)->sum('total_due');

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
            Stat::make('Sisa Pokok Pinjaman', 'Rp '.number_format($outstandingPrincipal, 0, ',', '.'))
                ->description($activeLoans.' pinjaman berjalan')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('warning'),
            Stat::make('Tunggakan Angsuran', 'Rp '.number_format($overdueAmount, 0, ',', '.'))
                ->description($overdueCount.' jadwal jatuh tempo lewat')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($overdueCount > 0 ? 'danger' : 'success'),
        ];
    }
}
