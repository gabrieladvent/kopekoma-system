<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\Pages\BaseEditRecord;
use App\Filament\Resources\UserResource;
use Filament\Actions;
use Illuminate\Database\Eloquent\Model;

class EditUser extends BaseEditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->hidden(fn (Model $record): bool => auth()->id() === $record->getKey()),
        ];
    }
}
