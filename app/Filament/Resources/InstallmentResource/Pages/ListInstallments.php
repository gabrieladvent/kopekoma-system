<?php

namespace App\Filament\Resources\InstallmentResource\Pages;

use App\Filament\Pages\BatchInstallmentPayment;
use App\Filament\Resources\InstallmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInstallments extends ListRecords
{
    protected static string $resource = InstallmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Batch potong gaji angsuran per OPD (ADR pinjaman 3c) — mode kolektif,
            // diakses lewat tombol di sini (bukan menu terpisah).
            Actions\Action::make('batchInstallmentPayment')
                ->label('Batch Potong Gaji')
                ->icon('heroicon-o-users')
                ->color('gray')
                ->url(BatchInstallmentPayment::getUrl())
                ->visible(fn (): bool => BatchInstallmentPayment::canAccess()),
            Actions\CreateAction::make()->label('Catat Angsuran'),
        ];
    }
}
