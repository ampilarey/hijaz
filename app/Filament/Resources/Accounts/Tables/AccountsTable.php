<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Models\Account;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Account $record): string => $record->kindLabel().($record->bank ? ' · '.$record->bank : '')),
                TextColumn::make('currency')->badge(),
                TextColumn::make('account_number')->label('Number')
                    ->state(fn (Account $record): string => $record->maskedNumber() ?? '—')
                    ->visibleFrom('sm'),
                IconColumn::make('is_active')->label('Active')->boolean()->visibleFrom('md'),
            ])
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make()]);
    }
}
