<?php

namespace App\Filament\Resources\LoanBlacklistResource\Pages;

use App\Filament\Resources\LoanBlacklistResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLoanBlacklists extends ListRecords
{
    protected static string $resource = LoanBlacklistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tandai Blacklist'),
        ];
    }
}
