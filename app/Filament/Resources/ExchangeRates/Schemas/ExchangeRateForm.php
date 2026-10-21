<?php

namespace App\Filament\Resources\ExchangeRates\Schemas;

use App\Support\Currency;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ExchangeRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('from_currency')->label('One unit of')->options(Currency::options())->default(Currency::USD)->required()->native(false),
            Select::make('to_currency')->label('Is worth, in')->options(Currency::options())->default(Currency::MVR)->required()->native(false)
                ->different('from_currency'),
            TextInput::make('rate')->numeric()->required()->minValue(0.00000001)->step(0.0001)
                ->helperText('How many of the second currency one unit of the first buys. USD to MVR is about 15.42.'),
            DatePicker::make('as_of')->label('From date')->required()->default(now())->maxDate(now()),
            TextInput::make('source')->maxLength(120)->placeholder('BML counter, MMA, agent quote'),
            Hidden::make('set_by')->default(fn (): ?int => Filament::auth()->id()),
        ]);
    }
}
