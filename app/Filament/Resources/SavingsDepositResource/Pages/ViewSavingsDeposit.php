<?php

namespace App\Filament\Resources\SavingsDepositResource\Pages;

use App\Filament\Resources\SavingsDepositResource;
use App\Models\SavingsDeposit;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSavingsDeposit extends ViewRecord
{
    protected static string $resource = SavingsDepositResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reverse')
                ->label('Reversal')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => SavingsDepositResource::canReverse($this->getRecord()))
                ->form(SavingsDepositResource::reverseFormSchema())
                ->requiresConfirmation()
                ->modalHeading('Reversal Setoran')
                ->modalDescription('Membuat transaksi-lawan. Baris asli tidak dihapus.')
                ->action(function (array $data): void {
                    /** @var SavingsDeposit $record */
                    $record = $this->getRecord();

                    SavingsDepositResource::performReversal($record, $data);
                }),
        ];
    }
}
