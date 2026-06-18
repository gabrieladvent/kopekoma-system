<?php

namespace App\Filament\Resources\ShoppingTransactionResource\Pages;

use App\Filament\Resources\ShoppingTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShoppingTransactions extends ListRecords
{
    protected static string $resource = ShoppingTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
