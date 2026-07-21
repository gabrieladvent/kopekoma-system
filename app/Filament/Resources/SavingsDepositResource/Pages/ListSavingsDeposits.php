<?php

namespace App\Filament\Resources\SavingsDepositResource\Pages;

use App\Filament\Pages\BatchSalaryDeduction;
use App\Filament\Resources\SavingsDepositResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSavingsDeposits extends ListRecords
{
    protected static string $resource = SavingsDepositResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('batchSalaryDeduction')
                ->label('Batch Potong Gaji')
                ->icon('heroicon-o-users')
                ->color('gray')
                ->url(BatchSalaryDeduction::getUrl())
                ->visible(fn (): bool => BatchSalaryDeduction::canAccess()),
            Actions\CreateAction::make()
                ->label('Setoran Tunggal'),
        ];
    }
}
