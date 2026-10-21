<?php

namespace App\Filament\Resources\ExcursionBookings\Tables;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Support\PaymentActions;
use App\Filament\Support\TripOptions;
use App\Models\ExcursionBooking;
use App\Models\TripExcursion;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExcursionBookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['excursion.trip', 'customer', 'participants.bookingPilgrim.pilgrim', 'booking']))
            ->columns([
                TextColumn::make('excursion.title')->label('Trip')
                    ->description(fn (ExcursionBooking $record): string => $record->excursion->trip->code.' · '.$record->customer->name),
                TextColumn::make('participants')->label('Who')->state(fn (ExcursionBooking $record): string => $record->participants->map(fn ($p) => $p->bookingPilgrim?->pilgrim?->name_en)->filter()->implode(', '))->visibleFrom('md')->wrap(),
                TextColumn::make('total_minor')->label('Total')->alignEnd()->state(fn (ExcursionBooking $record): string => $record->total()->format())->visibleFrom('sm'),
                TextColumn::make('status')->badge()
                    ->state(fn (ExcursionBooking $record): string => $record->status === ExcursionBooking::PAID ? 'Paid' : ($record->status === ExcursionBooking::CANCELLED ? 'Cancelled' : $record->balance()->format().' due'))
                    ->color(fn (ExcursionBooking $record): string => match ($record->status) {
                        ExcursionBooking::PAID => 'success', ExcursionBooking::CANCELLED => 'gray', default => 'warning',
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('trip')->label('Umrah trip')->options(fn (): array => TripOptions::all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereHas('excursion', fn ($e) => $e->where('trip_id', $data['value'])) : $query),
                SelectFilter::make('trip_excursion_id')->label('Additional trip')->options(fn (): array => TripExcursion::query()->with('trip')->orderByDesc('id')->limit(100)->get()->mapWithKeys(fn (TripExcursion $x): array => [$x->id => $x->title.' · '.$x->trip->code])->all()),
                SelectFilter::make('status')->options([ExcursionBooking::OPEN => 'Open', ExcursionBooking::PAID => 'Paid', ExcursionBooking::CANCELLED => 'Cancelled']),
            ])
            ->recordActions([
                PaymentActions::record(fn (?ExcursionBooking $record) => $record, 'Pay')->visible(fn (ExcursionBooking $record): bool => $record->status === ExcursionBooking::OPEN && $record->total_minor > 0),
                Action::make('invoice')->label('Invoice')->icon('heroicon-o-document-text')->color('gray')
                    ->url(fn (ExcursionBooking $record): string => route('excursion-bookings.invoice', $record), shouldOpenInNewTab: true),
                Action::make('booking')->label('Booking')->icon('heroicon-o-ticket')->color('gray')
                    ->url(fn (ExcursionBooking $record): string => BookingResource::getUrl('view', ['record' => $record->booking_id])),
            ])
            ->emptyStateHeading('No additional trips booked yet');
    }
}
