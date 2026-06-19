<?php

namespace App\Filament\Resources\SavingsWithdrawalResource\Pages;

use App\Filament\Resources\SavingsWithdrawalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSavingsWithdrawals extends ListRecords
{
    protected static string $resource = SavingsWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
