<?php

namespace App\Filament\Resources\SavingsDepositResource\Pages;

use App\Filament\Resources\SavingsDepositResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSavingsDeposits extends ListRecords
{
    protected static string $resource = SavingsDepositResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
