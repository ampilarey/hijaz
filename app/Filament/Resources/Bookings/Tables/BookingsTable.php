<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Filament\Support\TripOptions;
use App\Models\Booking;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'trip']))
            ->columns([
                TextColumn::make('reference')->label('Booking')->searchable()
                    ->description(fn (Booking $record): string => $record->customer->name.' · '.$record->seats.' seat'.($record->seats === 1 ? '' : 's')),
                TextColumn::make('trip.code')->label('Trip')->sortable()->visibleFrom('sm'),
                TextColumn::make('status')->badge()->state(fn (Booking $record): string => $record->statusLabel())
                    ->color(fn (Booking $record): string => match ($record->status) {
                        Booking::CONFIRMED => 'success', Booking::CANCELLED => 'danger', Booking::COMPLETED => 'gray', default => 'warning',
                    }),
                TextColumn::make('balance')->label('Balance')->state(fn (Booking $record): string => $record->balanceSummary())
                    ->color(fn (Booking $record): string => $record->isPaidUp() ? 'success' : 'danger'),
                TextColumn::make('created_at')->label('Made')->since()->visibleFrom('md'),
            ])
            ->defaultSort('id', 'desc')
            ->searchable()
            ->filters([
                SelectFilter::make('trip_id')->label('Trip')->options(fn (): array => TripOptions::all()),
                SelectFilter::make('status')->options(Booking::STATUSES),
            ])
            ->recordActions([ViewAction::make()->label('Open')])
            ->emptyStateHeading('No bookings yet')
            ->emptyStateDescription('A booking puts a customer\'s pilgrims on a trip\'s seats and prices them.');
    }
}
