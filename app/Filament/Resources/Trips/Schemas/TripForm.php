<?php

namespace App\Filament\Resources\Trips\Schemas;

use App\Models\Trip;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TripForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(120)->placeholder('July 2027 Umrah')->columnSpanFull(),
                DatePicker::make('departs_on')->label('Departs')->required()->native(false)->displayFormat('j M Y'),
                DatePicker::make('returns_on')->label('Returns')->required()->native(false)->displayFormat('j M Y')
                    ->afterOrEqual('departs_on'),
                TextInput::make('capacity_total')->label('Seats')->integer()->required()->minValue(0)
                    ->minValue(fn (Get $get, ?Trip $record): int => $record?->seatsTaken() ?? 0)
                    ->helperText('How many people this trip can take. Never less than are already booked.'),
                Select::make('leader_user_id')->label('Group leader')
                    ->options(fn (): array => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TextInput::make('code')->label('Code')->disabled()->dehydrated(false)->placeholder('Given automatically')
                    ->visible(fn (?Trip $record): bool => $record !== null),
                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }
}
