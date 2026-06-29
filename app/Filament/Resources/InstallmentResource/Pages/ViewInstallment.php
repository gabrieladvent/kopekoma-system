<?php

namespace App\Filament\Resources\InstallmentResource\Pages;

use App\Filament\Resources\InstallmentResource;
use App\Models\Installment;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewInstallment extends ViewRecord
{
    protected static string $resource = InstallmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reverse')
                ->label('Reversal')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => InstallmentResource::canReverse($this->getRecord()))
                ->form(InstallmentResource::reverseFormSchema())
                ->requiresConfirmation()
                ->modalHeading('Reversal Pembayaran')
                ->action(function (array $data): void {
                    /** @var Installment $record */
                    $record = $this->getRecord();
                    InstallmentResource::performReversal($record, $data);
                }),
        ];
    }
}
