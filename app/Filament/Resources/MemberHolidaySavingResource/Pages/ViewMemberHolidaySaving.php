<?php

namespace App\Filament\Resources\MemberHolidaySavingResource\Pages;

use App\Filament\Resources\MemberHolidaySavingResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewMemberHolidaySaving extends ViewRecord
{
    protected static string $resource = MemberHolidaySavingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
