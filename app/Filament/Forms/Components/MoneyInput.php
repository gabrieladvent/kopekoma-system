<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

/**
 * Reusable Rupiah money input. Displays with thousand separators while typing
 * (e.g. "1.000.000") but dehydrates to a plain number for storage (1000000).
 *
 * Use this for ALL monetary fields across the app so formatting & storage stay
 * consistent. Example: MoneyInput::make('mandatory_savings_amount').
 */
class MoneyInput extends TextInput
{
    public static function make(string $name): static
    {
        return parent::make($name)
            ->numeric()
            ->prefix('Rp')
            ->minValue(0)
            // Normalize a stored value (e.g. decimal:2 "50000.00") to a plain
            // integer string before display, so stripCharacters('.') below never
            // eats a decimal point on a save round-trip.
            ->formatStateUsing(fn ($state) => filled($state) ? (string) (int) round((float) $state) : $state)
            // $money(input, decimalSeparator, thousandsSeparator, precision)
            ->mask(RawJs::make("\$money(\$input, ',', '.', 0)"))
            // strip the thousands separator so the stored value is a plain number
            ->stripCharacters('.');
    }
}
