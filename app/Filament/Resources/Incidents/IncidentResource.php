<?php

namespace App\Filament\Resources\Incidents;

use App\Filament\Resources\Incidents\Pages\ListIncidents;
use App\Filament\Resources\Incidents\Tables\IncidentsTable;
use App\Models\Incident;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

/** What went wrong on the ground, raised by a leader's phone or typed here, resolved with what was done. */
class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static UnitEnum|string|null $navigationGroup = 'Trips';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'incidents';

    public static function table(Table $table): Table
    {
        return IncidentsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! Filament::auth()->user()?->can('incident.viewAny')) {
            return null;
        }

        $n = Incident::query()->open()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIncidents::route('/'),
        ];
    }
}
