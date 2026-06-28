<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\Pages\BaseCreateRecord;
use App\Filament\Resources\UserResource;

class CreateUser extends BaseCreateRecord
{
    protected static string $resource = UserResource::class;
}
