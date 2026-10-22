<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Models\Customer;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['contact', 'head']))
            ->columns([
                TextColumn::make('name')->searchable()
                    ->description(fn (Customer $record): string => $record->typeLabel().' · '.$record->contactName().(($phone = $record->contactPhone()) ? ' · '.$phone : '')),
                TextColumn::make('pilgrims_count')->label('People')->counts('pilgrims')->alignCenter(),
                TextColumn::make('island.label')->label('Island')->visibleFrom('md'),
            ])
            ->defaultSort('id', 'desc')
            ->searchable()
            ->filters([SelectFilter::make('type')->options(Customer::TYPES)])
            ->recordActions([EditAction::make()->label('Open')])
            ->emptyStateHeading('No customers yet');
    }
}
