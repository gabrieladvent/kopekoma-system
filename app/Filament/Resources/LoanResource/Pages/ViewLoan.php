<?php

namespace App\Filament\Resources\LoanResource\Pages;

use App\Filament\Resources\LoanResource;
use App\Models\Loan;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewLoan extends ViewRecord
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('printReceipt')
                ->label('Tanda Terima')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(fn (): StreamedResponse => LoanResource::printReceipt($this->getRecord())),
            Actions\Action::make('correct')
                ->label('Batalkan')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => LoanResource::canCorrect($this->getRecord()))
                ->form(LoanResource::correctionFormSchema())
                ->requiresConfirmation()
                ->modalHeading('Batalkan Pinjaman Salah-Input')
                ->modalDescription('Hanya untuk pinjaman salah input yang belum punya angsuran. Pinjaman ditandai Dibatalkan (tetap tersimpan sebagai histori), jadwalnya dibersihkan, dicatat di audit.')
                ->action(function (array $data): void {
                    /** @var Loan $record */
                    $record = $this->getRecord();
                    LoanResource::performCorrection($record, $data);
                }),
        ];
    }
}
