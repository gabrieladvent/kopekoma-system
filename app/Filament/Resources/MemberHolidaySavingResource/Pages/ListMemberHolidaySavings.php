<?php

namespace App\Filament\Resources\MemberHolidaySavingResource\Pages;

use App\Filament\Resources\MemberHolidaySavingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMemberHolidaySavings extends ListRecords
{
    protected static string $resource = MemberHolidaySavingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
