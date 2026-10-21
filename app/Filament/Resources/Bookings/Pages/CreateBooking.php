<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Exceptions\NoSeatsAvailable;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Customer;
use App\Models\Trip;
use App\Services\Bookings\BookingDesk;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /** The desk opens the booking: it holds the seats and prices the party in one transaction. */
    protected function handleRecordCreation(array $data): Model
    {
        $trip = Trip::query()->findOrFail($data['trip_id']);
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $party = array_values(array_map(fn (array $m): array => ['pilgrim_id' => (int) $m['pilgrim_id'], 'occupancy' => (string) $m['occupancy']], $data['party'] ?? []));

        try {
            return app(BookingDesk::class)->open($trip, $customer, $party, $data['notes'] ?? null);
        } catch (NoSeatsAvailable|\InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Booking opened. Seats are held for '.config('booking.hold_days', 7).' days.';
    }
}
