<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

class MoneyInput extends TextInput
{
    public static function make(string $name): static
    {
        return parent::make($name)
            ->numeric()
            ->prefix('Rp')
            ->minValue(0)
            ->formatStateUsing(fn ($state) => filled($state) ? (string) (int) round((float) $state) : $state)
            ->mask(RawJs::make("\$money(\$input, ',', '.', 0)"))
            ->stripCharacters('.');
    }
}
