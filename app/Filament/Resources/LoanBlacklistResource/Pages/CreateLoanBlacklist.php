<?php

namespace App\Filament\Resources\LoanBlacklistResource\Pages;

use App\Filament\Resources\LoanBlacklistResource;
use App\Filament\Resources\Pages\BaseCreateRecord;

class CreateLoanBlacklist extends BaseCreateRecord
{
    protected static string $resource = LoanBlacklistResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['recorded_by'] = auth()->id();
        $data['is_active'] = true;

        return $data;
    }
}
