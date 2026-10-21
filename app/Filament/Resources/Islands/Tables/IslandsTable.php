<?php

namespace App\Filament\Resources\Islands\Tables;

use App\Models\Island;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class IslandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Island $record): string => $record->atoll_code.'. '.$record->atoll_name),
                TextColumn::make('name_dv')->label('Dhivehi')->visibleFrom('sm'),
                IconColumn::make('is_active')->label('Active')->boolean()->visibleFrom('md'),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('atoll_code')->label('Atoll')->options(
                    fn (): array => Island::query()->orderBy('atoll_name')->distinct()
                        ->pluck('atoll_name', 'atoll_code')->map(fn (string $n, string $c): string => "{$c}. {$n}")->all(),
                ),
            ])
            ->recordActions([EditAction::make()]);
    }
}
