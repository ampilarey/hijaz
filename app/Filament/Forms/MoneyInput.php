<?php

namespace App\Filament\Forms;

use App\Support\Money;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Whole units on the screen, minor units in the column, converted through
 * Money both ways. Nothing else multiplies by 100.
 */
final class MoneyInput
{
    public static function make(string $name, string $currencyField = 'currency', ?string $label = 'Amount'): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->required()
            ->minValue(0)
            ->step(0.01)
            ->formatStateUsing(fn ($state): ?string => $state === null || $state === '' ? null : Money::ofMinor((int) $state, 'MVR')->major())
            // Blank stays null (a hidden or optional amount); the column's own
            // nullability and the field's `required()` decide whether that is allowed.
            ->dehydrateStateUsing(fn ($state, Get $get): ?int => $state === null || $state === ''
                ? null
                : Money::ofMajor((string) $state, (string) ($get($currencyField) ?: 'MVR'))->minor);
    }
}
