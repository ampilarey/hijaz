<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\ExcursionBooking;
use App\Models\ExcursionParticipant;
use App\Models\TripExcursion;
use Illuminate\Support\Facades\DB;

/** Places on an additional trip for the people of one booking, as their own riyal payable. */
final class Excursions
{
    /** @param  list<int>  $bookingPilgrimIds */
    public function book(TripExcursion $excursion, Booking $booking, array $bookingPilgrimIds): ExcursionBooking
    {
        if ($excursion->trip_id !== $booking->trip_id) {
            throw new \InvalidArgumentException('That excursion belongs to another trip.');
        }

        return DB::transaction(function () use ($excursion, $booking, $bookingPilgrimIds): ExcursionBooking {
            if ($excursion->capacity !== null) {
                $taken = ExcursionParticipant::query()
                    ->whereHas('excursionBooking', fn ($q) => $q->where('trip_excursion_id', $excursion->getKey())->where('status', '!=', ExcursionBooking::CANCELLED))
                    ->count();

                if ($taken + count($bookingPilgrimIds) > $excursion->capacity) {
                    throw new \InvalidArgumentException("{$excursion->title} has ".max(0, $excursion->capacity - $taken).' places left.');
                }
            }

            $xb = ExcursionBooking::query()->firstOrCreate(
                ['trip_excursion_id' => $excursion->getKey(), 'booking_id' => $booking->getKey(), 'status' => ExcursionBooking::OPEN],
                ['customer_id' => $booking->customer_id, 'currency' => $excursion->currency],
            );

            foreach (BookingPilgrim::query()->whereKey($bookingPilgrimIds)->where('booking_id', $booking->getKey())->get() as $bp) {
                $xb->participants()->firstOrCreate(['booking_pilgrim_id' => $bp->getKey()], ['amount_minor' => $excursion->is_included ? 0 : $excursion->price_minor]);
            }

            $xb->recalculateTotal();

            return $xb->fresh(['participants']);
        });
    }

    public function cancel(ExcursionBooking $xb): void
    {
        $xb->forceFill(['status' => ExcursionBooking::CANCELLED])->save();
    }
}
