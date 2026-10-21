<?php

namespace App\Filament\Resources\DocumentBatches;

use App\Filament\Resources\DocumentBatches\Pages\ListDocumentBatches;
use App\Filament\Resources\DocumentBatches\Pages\ViewDocumentBatch;
use App\Filament\Resources\DocumentBatches\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\DocumentBatches\Tables\DocumentBatchesTable;
use App\Models\DocumentBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

class DocumentBatchResource extends Resource
{
    protected static ?string $model = DocumentBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Visa uploads';

    protected static ?string $modelLabel = 'upload';

    protected static UnitEnum|string|null $navigationGroup = 'Pilgrims';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return DocumentBatchesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('document.create');
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentBatches::route('/'),
            'view' => ViewDocumentBatch::route('/{record}'),
        ];
    }
}
