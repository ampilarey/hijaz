<?php

namespace App\Filament\Resources\LedgerEntries;

use App\Filament\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Resources\LedgerEntries\Tables\LedgerEntriesTable;
use App\Models\LedgerEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

/** The books: every movement of money, recorded by Finance or posted from a verified payment. */
class LedgerEntryResource extends Resource
{
    protected static ?string $model = LedgerEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Books';

    protected static ?string $modelLabel = 'entry';

    protected static ?string $pluralModelLabel = 'entries';

    protected static UnitEnum|string|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return LedgerEntriesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerEntries::route('/'),
        ];
    }
}
