<?php

namespace App\Filament\Resources\ShoppingTransactionResource\Pages;

use App\Filament\Resources\ShoppingTransactionResource;
use App\Models\ShoppingTransaction;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewShoppingTransaction extends ViewRecord
{
    protected static string $resource = ShoppingTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reverse')
                ->label('Reversal')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => ShoppingTransactionResource::canReverse($this->getRecord()))
                ->form(ShoppingTransactionResource::reverseFormSchema())
                ->requiresConfirmation()
                ->modalHeading('Reversal Pemakaian Belanja')
                ->modalDescription('Membuat transaksi-lawan; saldo Wajib Belanja kembali. Baris asli tidak dihapus.')
                ->action(function (array $data): void {
                    /** @var ShoppingTransaction $record */
                    $record = $this->getRecord();

                    ShoppingTransactionResource::performReversal($record, $data);

                    $this->record->refresh();
                }),
        ];
    }
}
