<?php

namespace App\Filament\Resources\Islands;

use App\Filament\Resources\Islands\Pages\ListIslands;
use App\Filament\Resources\Islands\Schemas\IslandForm;
use App\Filament\Resources\Islands\Tables\IslandsTable;
use App\Models\Island;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class IslandResource extends Resource
{
    protected static ?string $model = Island::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static UnitEnum|string|null $navigationGroup = 'Reference';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return IslandForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IslandsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIslands::route('/'),
        ];
    }
}
