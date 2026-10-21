<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Filament\Forms\MoneyInput;
use App\Models\TripCost;
use App\Support\Currency;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CostsRelationManager extends RelationManager
{
    protected static string $relationship = 'costs';

    protected static ?string $title = 'Costs';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) Filament::auth()->user()?->can('cost.viewAny');
    }

    /** Editable on the view page too: who may do what is decided by the policy, not by the page. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('category')->required()->options(TripCost::CATEGORIES)->native(false),
            TextInput::make('supplier')->maxLength(120)->placeholder('Who is paid'),
            TextInput::make('description')->maxLength(255)->columnSpanFull(),
            Select::make('currency')->required()->options(Currency::options())->native(false)->default(Currency::SAR)->live(),
            MoneyInput::make('amount_minor', 'currency', 'Amount'),
            Toggle::make('is_per_person')->label('Per person')->helperText('Off means the whole cost for the trip however many travel.'),
            Select::make('status')->required()->options(TripCost::STATUSES)->default(TripCost::ESTIMATED)->native(false),
            DatePicker::make('incurred_on')->label('Dated')->native(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category')->label('What for')->badge()->state(fn (TripCost $record): string => $record->categoryLabel())
                    ->description(fn (TripCost $record): string => $record->supplier ?: ($record->description ?: '')),
                TextColumn::make('amount_minor')->label('Amount')->alignEnd()
                    ->state(fn (TripCost $record): string => $record->unit()->format().($record->is_per_person ? ' pp' : '')),
                TextColumn::make('status')->badge()->state(fn (TripCost $record): string => $record->statusLabel())
                    ->color(fn (TripCost $record): string => match ($record->status) {
                        TripCost::PAID => 'success', TripCost::COMMITTED => 'info', default => 'warning',
                    })->visibleFrom('sm'),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([CreateAction::make()->label('Add cost')])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No costs recorded');
    }
}
