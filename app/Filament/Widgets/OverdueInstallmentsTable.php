<?php

namespace App\Filament\Widgets;

use App\Models\InstallmentSchedule;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OverdueInstallmentsTable extends TableWidget
{
    protected static ?string $heading = 'Tunggakan Angsuran Terbaru';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                InstallmentSchedule::query()
                    ->overdue()
                    ->with('loan.member')
                    ->orderBy('due_date')
            )
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5, 10, 25])
            ->emptyStateHeading('Tidak ada tunggakan')
            ->emptyStateDescription('Semua angsuran yang jatuh tempo sudah terbayar.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('loan.member.full_name')
                    ->label('Anggota')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('loan.loan_number')
                    ->label('No. Pinjaman')
                    ->searchable(),
                TextColumn::make('installment_seq')
                    ->label('Angsuran ke-')
                    ->alignCenter(),
                TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('days_overdue')
                    ->label('Terlambat')
                    ->state(fn (InstallmentSchedule $record): string => $record->due_date->diffInDays(now()).' hari')
                    ->badge()
                    ->color('danger'),
                TextColumn::make('total_due')
                    ->label('Nominal')
                    ->money('IDR')
                    ->alignEnd()
                    ->weight('bold'),
            ]);
    }
}
