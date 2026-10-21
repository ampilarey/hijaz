<?php

namespace App\Filament\Resources\Trips;

use App\Filament\Resources\Trips\Pages\CreateTrip;
use App\Filament\Resources\Trips\Pages\EditTrip;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Filament\Resources\Trips\Pages\ViewTrip;
use App\Filament\Resources\Trips\RelationManagers\ChecklistRelationManager;
use App\Filament\Resources\Trips\RelationManagers\CostsRelationManager;
use App\Filament\Resources\Trips\RelationManagers\ExcursionsRelationManager;
use App\Filament\Resources\Trips\RelationManagers\FlightsRelationManager;
use App\Filament\Resources\Trips\RelationManagers\HotelsRelationManager;
use App\Filament\Resources\Trips\RelationManagers\PriceTiersRelationManager;
use App\Filament\Resources\Trips\RelationManagers\StopsRelationManager;
use App\Filament\Resources\Trips\Schemas\TripForm;
use App\Filament\Resources\Trips\Tables\TripsTable;
use App\Models\Trip;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class TripResource extends Resource
{
    protected static ?string $model = Trip::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static UnitEnum|string|null $navigationGroup = 'Trips';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TripForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TripsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            FlightsRelationManager::class,
            HotelsRelationManager::class,
            StopsRelationManager::class,
            PriceTiersRelationManager::class,
            ExcursionsRelationManager::class,
            ChecklistRelationManager::class,
            CostsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrips::route('/'),
            'create' => CreateTrip::route('/create'),
            'view' => ViewTrip::route('/{record}'),
            'edit' => EditTrip::route('/{record}/edit'),
        ];
    }
}
