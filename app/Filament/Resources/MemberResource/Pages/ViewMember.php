<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewMember extends ViewRecord
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cetakKartu')
                ->label('Cetak Kartu')
                ->icon('heroicon-o-identification')
                ->color('gray')
                ->visible(fn (): bool => MemberResource::canExportMembers())
                ->action(fn (): StreamedResponse => MemberResource::printCard($this->getRecord())),
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
