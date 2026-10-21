<?php

namespace App\Filament\Resources\Trips\Tables;

use App\Models\Trip;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TripsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // One card per trip on a phone; a row with columns from a tablet up.
                Split::make([
                    TextColumn::make('name')
                        ->searchable()
                        ->weight('medium')
                        ->description(fn (Trip $record): string => $record->departs_on->format('j M').' – '.$record->returns_on->format('j M Y').' · '.$record->code),
                    TextColumn::make('status')
                        ->badge()
                        ->grow(false)
                        ->state(fn (Trip $record): string => $record->status === Trip::OPEN ? 'Open' : $record->statusLabel())
                        ->color(fn (Trip $record): string => match ($record->status) {
                            Trip::OPEN => 'success',
                            Trip::TRAVELLING => 'info',
                            Trip::CANCELLED => 'danger',
                            Trip::COMPLETED => 'gray',
                            default => 'warning',
                        }),
                    TextColumn::make('seats')
                        ->label('Seats')
                        ->state(fn (Trip $record): string => $record->seatsTaken().' of '.$record->capacity_total)
                        ->grow(false)
                        ->visibleFrom('sm'),
                    TextColumn::make('leader.name')->label('Leader')->grow(false)->visibleFrom('md'),
                ])->from('md'),
            ])
            ->defaultSort('departs_on', 'desc')
            ->filters([
                SelectFilter::make('status')->options(Trip::STATUSES),
            ])
            ->recordActions([EditAction::make()->label('Open'), ViewAction::make()->label('Open')->hidden(fn (Trip $record): bool => auth()->user()?->can('update', $record) ?? false)])
            ->emptyStateHeading('No trips yet')
            ->emptyStateDescription('Create the next Umrah trip, or copy one that already exists.');
    }
}
