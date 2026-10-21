<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Models\TripHotel;
use App\Models\TripStop;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StopsRelationManager extends RelationManager
{
    protected static string $relationship = 'stops';

    protected static ?string $title = 'Route';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('city')->required()->options(TripStop::CITIES)->native(false),
            TextInput::make('sort_order')->label('Order')->integer()->default(fn (): int => (int) $this->getOwnerRecord()->stops()->max('sort_order') + 1),
            DatePicker::make('arrives_on')->label('Arrive')->native(false),
            DatePicker::make('departs_on')->label('Leave')->native(false),
            Select::make('trip_hotel_id')->label('Hotel')
                ->options(fn (): array => $this->getOwnerRecord()->hotels()->get()->mapWithKeys(fn (TripHotel $h): array => [$h->id => $h->cityLabel().': '.$h->name])->all()),
            Select::make('transport')->label('Leave by')->options(TripStop::TRANSPORT)->native(false),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#'),
                TextColumn::make('city')->state(fn (TripStop $record): string => $record->cityLabel())
                    ->description(fn (TripStop $record): string => $record->hotel?->name ?? ''),
                TextColumn::make('arrives_on')->label('Dates')
                    ->state(fn (TripStop $record): string => ($record->arrives_on?->format('j M') ?? '?').' – '.($record->departs_on?->format('j M') ?? '?')),
                TextColumn::make('transport')->label('Leave by')->state(fn (TripStop $record): string => TripStop::TRANSPORT[$record->transport] ?? '')->visibleFrom('sm'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([CreateAction::make()->label('Add stop')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No route yet')
            ->emptyStateDescription('Add the stops in order, or use "Build route" in the menu above once flights and hotels are in.');
    }
}
