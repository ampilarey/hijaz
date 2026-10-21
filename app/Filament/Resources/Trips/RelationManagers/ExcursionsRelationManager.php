<?php

namespace App\Filament\Resources\Trips\RelationManagers;

use App\Filament\Forms\MoneyInput;
use App\Models\TripExcursion;
use App\Models\TripStop;
use App\Support\Currency;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExcursionsRelationManager extends RelationManager
{
    protected static string $relationship = 'excursions';

    protected static ?string $title = 'Additional trips';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->required()->maxLength(120)->placeholder('Taif Ziyarah'),
            Select::make('city')->label('From')->options(TripStop::CITIES)->native(false),
            DatePicker::make('scheduled_on')->label('Date')->native(false),
            TextInput::make('capacity')->label('Seats')->integer()->minValue(0),
            Toggle::make('is_included')->label('Included in the package')->live()->columnSpanFull(),
            Select::make('currency')->options(Currency::options())->default(Currency::SAR)->native(false)->required()
                ->visible(fn (Get $get): bool => ! $get('is_included')),
            MoneyInput::make('price_minor', 'currency', 'Price per person')
                ->visible(fn (Get $get): bool => ! $get('is_included'))
                ->required(fn (Get $get): bool => ! $get('is_included')),
            TextInput::make('provider')->label('Bus company / provider')->maxLength(120),
            Select::make('cost_currency')->label('Cost currency')->options(Currency::options())->native(false)->default(Currency::SAR),
            MoneyInput::make('cost_minor', 'cost_currency', 'Cost to Hijaz (whole trip)')->required(false),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->description(fn (TripExcursion $record): string => trim(($record->city ? TripStop::CITIES[$record->city] ?? $record->city : '').($record->scheduled_on ? ' · '.$record->scheduled_on->format('j M') : ''))),
                TextColumn::make('price_minor')->label('Price')->state(fn (TripExcursion $record): string => $record->priceLabel()),
                TextColumn::make('capacity')->label('Seats')->visibleFrom('sm'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([CreateAction::make()->label('Add trip')->mutateDataUsing(fn (array $data): array => self::normalise($data))])
            ->recordActions([ActionGroup::make([EditAction::make()->mutateDataUsing(fn (array $data): array => self::normalise($data)), DeleteAction::make()])])
            ->emptyStateHeading('No additional trips yet')
            ->emptyStateDescription('Makkah Ziyarah, Taif, Jeddah: free with the package or priced in riyal.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        if (! empty($data['is_included'])) {
            $data['price_minor'] = 0;
            $data['currency'] = $data['currency'] ?? Currency::SAR;
        }

        if (($data['cost_minor'] ?? null) === null || $data['cost_minor'] === '') {
            $data['cost_minor'] = null;
        }

        return $data;
    }
}
