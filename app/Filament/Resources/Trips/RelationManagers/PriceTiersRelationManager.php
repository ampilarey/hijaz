<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Filament\Forms\MoneyInput;
use App\Models\PriceTier;
use App\Support\Currency;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PriceTiersRelationManager extends RelationManager
{
    protected static string $relationship = 'priceTiers';

    protected static ?string $title = 'Prices';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('occupancy')->required()->options(PriceTier::OCCUPANCIES)->native(false)->default(PriceTier::QUAD),
            Select::make('pax_type')->label('Passenger')->required()->options(PriceTier::PAX_TYPES)->native(false)->default(PriceTier::ADULT),
            Select::make('currency')->required()->options(Currency::options())->native(false)->default(Currency::MVR)->live()
                ->helperText('Packages are MVR. A private room is the USD upgrade.'),
            MoneyInput::make('amount_minor', 'currency', 'Price per person'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occupancy')->label('Tier')->state(fn (PriceTier $record): string => $record->label()),
                TextColumn::make('amount_minor')->label('Price')->alignEnd()->state(fn (PriceTier $record): string => $record->money()->format()),
            ])
            ->defaultSort('sort_order')
            ->headerActions([CreateAction::make()->label('Add price')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No prices yet')
            ->emptyStateDescription('A price per occupancy and passenger type. Nobody can be booked until there is one.');
    }
}
