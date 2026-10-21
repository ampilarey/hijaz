<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Models\TripFlight;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FlightsRelationManager extends RelationManager
{
    protected static string $relationship = 'flights';

    protected static ?string $title = 'Flights';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('direction')->required()->options(TripFlight::DIRECTIONS)->default(TripFlight::OUTBOUND)->native(false),
            TextInput::make('airline')->required()->maxLength(80)->placeholder('Saudia'),
            TextInput::make('flight_number')->label('Flight number')->required()->maxLength(20)->placeholder('SV 3301'),
            TextInput::make('pnr')->label('PNR')->maxLength(50)->helperText('Staff only; never printed for pilgrims.'),
            TextInput::make('from_airport')->label('From')->required()->length(3)->placeholder('MLE')
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state)),
            TextInput::make('to_airport')->label('To')->required()->length(3)->placeholder('JED')
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state)),
            DateTimePicker::make('departs_at')->label('Departs')->required()->seconds(false)->native(false)
                ->helperText('Local time at the airport it leaves from, as printed on the ticket.'),
            DateTimePicker::make('arrives_at')->label('Arrives')->seconds(false)->native(false)
                ->helperText('Local time where it lands.'),
            TextInput::make('seats')->label('Seats held')->integer()->minValue(0),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Flight')
                    ->state(fn (TripFlight $record): string => $record->label())
                    ->description(fn (TripFlight $record): string => $record->airline.' · '.(TripFlight::DIRECTIONS[$record->direction] ?? $record->direction)),
                TextColumn::make('departs_at')->label('Departs')->dateTime('j M, H:i'),
                TextColumn::make('arrives_at')->label('Arrives')->dateTime('j M, H:i')->visibleFrom('sm'),
                TextColumn::make('seats')->visibleFrom('md'),
            ])
            ->defaultSort('departs_at')
            ->headerActions([CreateAction::make()->label('Add flight')])
            ->recordActions([ActionGroup::make([EditAction::make(), DeleteAction::make()])])
            ->emptyStateHeading('No flights yet');
    }
}
