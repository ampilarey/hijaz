<?php

namespace App\Filament\Resources\Trips\Schemas;

use App\Models\Trip;
use App\Models\TripFlight;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class TripForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(120)->placeholder('July 2027 Umrah')->columnSpanFull(),
                DatePicker::make('departs_on')->label('Departs')->native(false)->displayFormat('j M Y')->live()
                    ->disabled(fn (?Trip $record): bool => self::fromFlights($record, TripFlight::OUTBOUND))
                    ->helperText(fn (?Trip $record): string => self::fromFlights($record, TripFlight::OUTBOUND) ? 'Taken from the first flight out.' : 'Optional. Leave empty until it is fixed; adding the flights fills it in.'),
                DatePicker::make('returns_on')->label('Returns')->native(false)->displayFormat('j M Y')
                    ->disabled(fn (?Trip $record): bool => self::fromFlights($record, TripFlight::RETURN))
                    ->helperText(fn (?Trip $record): ?string => self::fromFlights($record, TripFlight::RETURN) ? 'Taken from the last flight home.' : null)
                    ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        if (filled($value) && filled($get('departs_on')) && Carbon::parse($value)->lt(Carbon::parse($get('departs_on')))) {
                            $fail('The return date cannot be before the departure date.');
                        }
                    }),
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

    /** Once a flight in that direction is entered, the date comes from the flights. */
    private static function fromFlights(?Trip $trip, string $direction): bool
    {
        return $trip !== null && $trip->flights()->where('direction', $direction)->exists();
    }
}
