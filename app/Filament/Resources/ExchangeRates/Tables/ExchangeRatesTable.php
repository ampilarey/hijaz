<?php

namespace App\Filament\Resources\ExchangeRates\Tables;

use App\Models\ExchangeRate;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExchangeRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Rate')
                    ->description(fn (ExchangeRate $record): string => $record->source ?: ''),
                TextColumn::make('as_of')->label('From')->date('j M Y')->sortable(),
                TextColumn::make('setter.name')->label('Set by')->visibleFrom('md'),
            ])
            ->defaultSort('as_of', 'desc')
            ->filters([
                SelectFilter::make('from_currency')->label('From')->options(['MVR' => 'MVR', 'USD' => 'USD', 'SAR' => 'SAR']),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No rates yet')
            ->emptyStateDescription('Until a rate is set, nothing is converted and reports show each currency on its own.');
    }
}
