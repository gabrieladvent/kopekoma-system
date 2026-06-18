<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Filament\Resources\SavingsDepositResource;
use App\Models\SavingsDeposit;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SavingsDepositsRelationManager extends RelationManager
{
    protected static string $relationship = 'savingsDeposits';

    protected static ?string $title = 'Riwayat Simpanan';

    protected static ?string $icon = 'heroicon-o-arrow-down-on-square-stack';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transaction_number')
            ->defaultSort('deposit_date', 'desc')
            ->emptyStateHeading('Belum ada setoran')
            ->emptyStateDescription('Setoran simpanan anggota ini akan tampil di sini.')
            ->columns([
                Tables\Columns\TextColumn::make('transaction_number')
                    ->label('No. Transaksi')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('savings_type')
                    ->label('Jenis')
                    ->badge()
                    ->color(fn (string $state): string => SavingsDepositResource::typeColor($state))
                    ->formatStateUsing(fn (string $state): string => SavingsDepositResource::SAVINGS_TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('deposit_date')
                    ->label('Tanggal')
                    ->date('d M Y')
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
            ->filters([
                Tables\Filters\SelectFilter::make('savings_type')
                    ->label('Jenis Simpanan')
                    ->options(SavingsDepositResource::SAVINGS_TYPES),
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
