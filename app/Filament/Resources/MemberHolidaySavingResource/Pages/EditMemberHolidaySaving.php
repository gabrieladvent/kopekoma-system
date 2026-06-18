<?php

namespace App\Filament\Resources\MemberHolidaySavingResource\Pages;

use App\Filament\Resources\MemberHolidaySavingResource;
use App\Filament\Resources\Pages\BaseEditRecord;
use Filament\Actions;

class EditMemberHolidaySaving extends BaseEditRecord
{
    protected static string $resource = MemberHolidaySavingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return MemberHolidaySavingResource::withDerivedYear($data);
    }
}
