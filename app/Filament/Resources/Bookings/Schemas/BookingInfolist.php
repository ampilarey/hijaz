<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Booking;
use App\Models\SeatHold;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BookingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['default' => 2, 'md' => 4])
                ->schema([
                    TextEntry::make('status')->badge()->state(fn (Booking $record): string => $record->statusLabel())
                        ->color(fn (Booking $record): string => match ($record->status) {
                            Booking::CONFIRMED => 'success', Booking::CANCELLED => 'danger', Booking::COMPLETED => 'gray', default => 'warning',
                        }),
                    TextEntry::make('trip.name')->label('Trip')->url(fn (Booking $record): string => TripResource::getUrl('view', ['record' => $record->trip]))
                        ->helperText(fn (Booking $record): string => $record->trip->code.' · '.$record->trip->departs_on->format('j M').' – '.$record->trip->returns_on->format('j M Y')),
                    TextEntry::make('customer.name')->label('Customer')->url(fn (Booking $record): string => CustomerResource::getUrl('edit', ['record' => $record->customer]))
                        ->helperText(fn (Booking $record): ?string => $record->customer->phone),
                    TextEntry::make('seats')->label('Seats')
                        ->helperText(function (Booking $record): ?string {
                            if ($record->status !== Booking::DRAFT) {
                                return null;
                            }

                            $hold = $record->seatHolds()->whereNull('released_at')->latest('id')->first();

                            return $hold instanceof SeatHold ? 'held until '.$hold->expires_at->format('j M, H:i') : 'no live hold';
                        }),
                ]),
            Section::make('Money')
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('totals')->label('Total')->state(fn (Booking $record): string => self::list($record, 'total')),
                    TextEntry::make('paid')->label('Received')->state(fn (Booking $record): string => self::list($record, 'paidIn')),
                    TextEntry::make('balance')->label('Balance')->state(fn (Booking $record): string => $record->balanceSummary())
                        ->color(fn (Booking $record): string => $record->isPaidUp() && $record->currencies() !== [] ? 'success' : 'danger')
                        ->weight('bold'),
                ]),
            TextEntry::make('notes')->columnSpanFull()->visible(fn (Booking $record): bool => filled($record->notes)),
            TextEntry::make('cancellation_reason')->label('Cancelled because')->columnSpanFull()->color('danger')->visible(fn (Booking $record): bool => filled($record->cancellation_reason)),
        ]);
    }

    private static function list(Booking $record, string $method): string
    {
        $parts = array_map(fn (string $c): string => $record->{$method}($c)->format(), $record->currencies());

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
