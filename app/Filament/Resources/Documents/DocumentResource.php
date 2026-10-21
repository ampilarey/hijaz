<?php

namespace App\Filament\Resources\Documents;

use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Tables\DocumentsTable;
use App\Models\Document;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

/** The queue: every copy waiting to be accepted or verified, across all pilgrims. */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'Copies to check';

    protected static ?string $modelLabel = 'copy';

    protected static ?string $pluralModelLabel = 'copies';

    protected static UnitEnum|string|null $navigationGroup = 'Pilgrims';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return DocumentsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Document::query()->forPilgrims()->pending()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
        ];
    }
}
