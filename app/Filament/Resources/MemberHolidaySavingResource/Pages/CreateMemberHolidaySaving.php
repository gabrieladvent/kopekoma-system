<?php

namespace App\Filament\Resources\MemberHolidaySavingResource\Pages;

use App\Filament\Resources\MemberHolidaySavingResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateMemberHolidaySaving extends BaseCreateRecord
{
    protected static string $resource = MemberHolidaySavingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return MemberHolidaySavingResource::withDerivedYear($data);
    }
}
