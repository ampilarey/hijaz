<?php

namespace App\Filament\Resources\Pilgrims;

use App\Filament\Resources\Pilgrims\Pages\CreatePilgrim;
use App\Filament\Resources\Pilgrims\Pages\EditPilgrim;
use App\Filament\Resources\Pilgrims\Pages\ListPilgrims;
use App\Filament\Resources\Pilgrims\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Pilgrims\Schemas\PilgrimForm;
use App\Filament\Resources\Pilgrims\Tables\PilgrimsTable;
use App\Models\Pilgrim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class PilgrimResource extends Resource
{
    protected static ?string $model = Pilgrim::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static UnitEnum|string|null $navigationGroup = 'Pilgrims';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name_en';

    public static function form(Schema $schema): Schema
    {
        return PilgrimForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PilgrimsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPilgrims::route('/'),
            'create' => CreatePilgrim::route('/create'),
            'edit' => EditPilgrim::route('/{record}/edit'),
        ];
    }
}
