<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (User $record): string => $record->email),
                TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->visibleFrom('sm'),
                IconColumn::make('app_authentication_secret')
                    ->label('2FA')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->hasSecondFactor())
                    ->visibleFrom('md'),
                TextColumn::make('last_login_at')
                    ->label('Last sign-in')
                    ->since()
                    ->visibleFrom('md'),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([EditAction::make()]);
    }
}
