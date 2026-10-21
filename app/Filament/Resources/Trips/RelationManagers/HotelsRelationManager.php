<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Models\PriceTier;
use App\Models\TripHotel;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HotelsRelationManager extends RelationManager
{
    protected static string $relationship = 'hotels';

    protected static ?string $title = 'Hotels';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('city')->required()->options(TripHotel::CITIES)->native(false),
            TextInput::make('name')->required()->maxLength(120),
            DatePicker::make('check_in_on')->label('Check in')->required()->native(false),
            DatePicker::make('check_out_on')->label('Check out')->required()->native(false)->afterOrEqual('check_in_on'),
            TextInput::make('address')->maxLength(255)->columnSpanFull(),
            TextInput::make('distance_to_haram_m')->label('Distance to the Haram (metres)')->integer()->minValue(0),
            TextInput::make('booking_reference')->label('Booking reference')->maxLength(60),
            TextInput::make('contact_name')->label('Contact')->maxLength(80),
            TextInput::make('contact_phone')->label('Contact phone')->tel()->maxLength(40),
            CheckboxList::make('room_types')->label('Room types booked')->options(PriceTier::OCCUPANCIES)->columns(2)->columnSpanFull(),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn (TripHotel $record): string => $record->cityLabel().($record->distance_to_haram_m ? ' · '.$record->distance_to_haram_m.' m from the Haram' : '')),
                TextColumn::make('check_in_on')->label('Stay')
                    ->state(fn (TripHotel $record): string => $record->check_in_on->format('j M').' – '.$record->check_out_on->format('j M').' ('.$record->nights().' nights)'),
                TextColumn::make('booking_reference')->label('Reference')->visibleFrom('md'),
            ])
            ->defaultSort('check_in_on')
            ->headerActions([CreateAction::make()->label('Add hotel')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No hotels yet');
    }
}
