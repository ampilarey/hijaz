<?php

namespace App\Filament\Resources\LedgerCategories;

use App\Filament\Resources\LedgerCategories\Pages\ListLedgerCategories;
use App\Models\LedgerCategory;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class LedgerCategoryResource extends Resource
{
    protected static ?string $model = LedgerCategory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Book categories';

    protected static ?string $modelLabel = 'category';

    protected static UnitEnum|string|null $navigationGroup = 'Reference';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80),
            Select::make('kind')->options([LedgerCategory::INCOME => 'Income', LedgerCategory::EXPENSE => 'Expense'])->required()->native(false),
            TextInput::make('sort_order')->integer()->default(50),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->description(fn (LedgerCategory $record): string => $record->slug),
                TextColumn::make('kind')->badge()->color(fn (string $state): string => $state === LedgerCategory::INCOME ? 'success' : 'warning'),
                IconColumn::make('is_active')->label('Active')->boolean()->visibleFrom('sm'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([CreateAction::make()->label('Add a category')->mutateDataUsing(fn (array $data): array => $data + ['slug' => Str::slug($data['name'], '_')])])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerCategories::route('/'),
        ];
    }
}
