<?php

namespace App\Services\Trips;

use App\Models\Trip;
use App\Models\TripFlight;
use App\Models\TripHotel;

/**
 * Proposes the route from the flights and hotels already entered, in the
 * order the hotels are stayed in. Staff edit the result; it never
 * overwrites stops that exist.
 */
class RouteBuilder
{
    public function build(Trip $trip): int
    {
        if ($trip->stops()->exists()) {
            return 0;
        }

        $hotels = $trip->hotels->sortBy('check_in_on')->values();
        $outbound = $trip->flights->first(fn (TripFlight $f): bool => $f->direction === TripFlight::OUTBOUND);
        $return = $trip->flights->first(fn (TripFlight $f): bool => $f->direction === TripFlight::RETURN);

        $stops = [];
        $order = 0;

        $arrivalAirport = $outbound ? strtoupper($outbound->to_airport) : null;
        $firstHotelCity = $hotels->first()?->city;

        // Landing in Jeddah on the way to Makkah is a stop only if nobody sleeps there.
        if ($arrivalAirport === 'JED' && $firstHotelCity !== TripHotel::JEDDAH) {
            $stops[] = ['city' => 'jeddah', 'arrives_on' => $trip->departs_on->toDateString(), 'departs_on' => $trip->departs_on->toDateString(), 'transport' => 'bus'];
        }

        foreach ($hotels as $i => $hotel) {
            $next = $hotels->get($i + 1);
            $stops[] = [
                'city' => $hotel->city,
                'arrives_on' => $hotel->check_in_on->toDateString(),
                'departs_on' => $hotel->check_out_on->toDateString(),
                'trip_hotel_id' => $hotel->id,
                'transport' => $next === null ? 'flight' : ($hotel->city !== TripHotel::JEDDAH && $next->city !== TripHotel::JEDDAH ? 'bus' : 'bus'),
            ];
        }

        $departureAirport = $return ? strtoupper($return->from_airport) : null;
        $lastHotelCity = $hotels->last()?->city;

        if ($departureAirport === 'JED' && $lastHotelCity !== TripHotel::JEDDAH && $hotels->isNotEmpty()) {
            $stops[] = ['city' => 'jeddah', 'arrives_on' => $trip->returns_on->toDateString(), 'departs_on' => $trip->returns_on->toDateString(), 'transport' => 'flight'];
        }

        foreach ($stops as $stop) {
            $trip->stops()->create(array_merge($stop, ['sort_order' => ++$order]));
        }

        $trip->unsetRelation('stops');

        return count($stops);
    }
}
