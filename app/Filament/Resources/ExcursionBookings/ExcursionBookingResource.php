<?php

namespace App\Filament\Resources\ExcursionBookings;

use App\Filament\Resources\ExcursionBookings\Pages\ListExcursionBookings;
use App\Filament\Resources\ExcursionBookings\Tables\ExcursionBookingsTable;
use App\Models\ExcursionBooking;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

/** Every additional trip booked, across bookings: who is going to Taif, and who has paid. */
class ExcursionBookingResource extends Resource
{
    protected static ?string $model = ExcursionBooking::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Additional trips';

    protected static ?string $modelLabel = 'additional trip booking';

    protected static UnitEnum|string|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return ExcursionBookingsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExcursionBookings::route('/'),
        ];
    }
}
