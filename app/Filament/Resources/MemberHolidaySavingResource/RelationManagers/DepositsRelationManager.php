<?php

namespace App\Filament\Resources\MemberHolidaySavingResource\RelationManagers;

use App\Filament\Resources\SavingsDepositResource;
use App\Models\SavingsDeposit;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class DepositsRelationManager extends RelationManager
{
    protected static string $relationship = 'deposits';

    protected static ?string $title = 'Setoran Hari Raya';

    protected static ?string $icon = 'heroicon-o-banknotes';

    /**
     * Read-only: setoran dibuat dari modul Setoran (immutable, koreksi via
     * reversal). Di sini hanya rekap setoran untuk tahun program ini.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transaction_number')
            ->emptyStateHeading('Belum ada setoran Hari Raya')
            ->emptyStateDescription('Setoran yang dicatat untuk tahun program ini akan tampil di sini.')
            ->columns([
                Tables\Columns\TextColumn::make('transaction_number')
                    ->label('No. Transaksi')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('deposit_date')
                    ->label('Tanggal Setor')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('deposit_method')
                    ->label('Metode')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => SavingsDepositResource::DEPOSIT_METHODS[$state] ?? $state)
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_reversal')
                    ->label('Reversal')
                    ->boolean(),
            ])
            ->defaultSort('deposit_date', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_reversal')
                    ->label('Reversal'),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn (SavingsDeposit $record): string => SavingsDepositResource::getUrl('view', ['record' => $record])),
            ])
            ->bulkActions([]);
    }
}
