<?php

namespace App\Services\Trips;

use App\Models\Trip;
use App\Models\TripCost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Copy this trip": the same set-up with every date shifted by the
 * difference between the two departure days. Bookings, pilgrims, done
 * ticks and PNRs are not copied: they belong to the trip that happened.
 */
class TripCopier
{
    public function copy(Trip $from, string $name, Carbon $departsOn): Trip
    {
        $shift = (int) $from->departs_on->diffInDays($departsOn, false);
        $shifted = fn (?Carbon $date): ?string => $date?->copy()->addDays($shift)->toDateString();

        return DB::transaction(function () use ($from, $name, $departsOn, $shifted): Trip {
            $to = Trip::query()->create([
                'name' => $name,
                'departs_on' => $departsOn->toDateString(),
                'returns_on' => $shifted($from->returns_on),
                'capacity_total' => $from->capacity_total,
                'leader_user_id' => $from->leader_user_id,
                'notes' => $from->notes,
            ]);

            foreach ($from->priceTiers as $tier) {
                $to->priceTiers()->create($tier->only(['occupancy', 'pax_type', 'amount_minor', 'currency', 'sort_order']));
            }

            foreach ($from->flights as $flight) {
                $to->flights()->create(array_merge(
                    $flight->only(['direction', 'airline', 'flight_number', 'from_airport', 'to_airport', 'seats', 'notes', 'sort_order']),
                    [
                        'departs_at' => $flight->departs_at->copy()->addDays((int) $from->departs_on->diffInDays($departsOn, false)),
                        'arrives_at' => $flight->arrives_at?->copy()->addDays((int) $from->departs_on->diffInDays($departsOn, false)),
                    ],
                ));
            }

            $hotelMap = [];

            foreach ($from->hotels as $hotel) {
                $copy = $to->hotels()->create(array_merge(
                    $hotel->only(['city', 'name', 'address', 'distance_to_haram_m', 'contact_name', 'contact_phone', 'room_types', 'notes', 'sort_order']),
                    ['check_in_on' => $shifted($hotel->check_in_on), 'check_out_on' => $shifted($hotel->check_out_on)],
                ));
                $hotelMap[$hotel->id] = $copy->id;
            }

            foreach ($from->stops as $stop) {
                $to->stops()->create(array_merge(
                    $stop->only(['sort_order', 'city', 'transport', 'notes']),
                    [
                        'arrives_on' => $shifted($stop->arrives_on),
                        'departs_on' => $shifted($stop->departs_on),
                        'trip_hotel_id' => $stop->trip_hotel_id ? ($hotelMap[$stop->trip_hotel_id] ?? null) : null,
                    ],
                ));
            }

            foreach ($from->excursions as $excursion) {
                $to->excursions()->create(array_merge(
                    $excursion->only(['title', 'city', 'is_included', 'price_minor', 'currency', 'capacity', 'provider', 'cost_minor', 'cost_currency', 'notes', 'sort_order']),
                    ['scheduled_on' => $shifted($excursion->scheduled_on)],
                ));
            }

            foreach ($from->checklist as $item) {
                $to->checklist()->create(array_merge(
                    $item->only(['title', 'is_blocking', 'notes', 'sort_order']),
                    ['due_on' => $shifted($item->due_on)],
                ));
            }

            foreach ($from->costs as $cost) {
                $to->costs()->create(array_merge(
                    $cost->only(['category', 'supplier', 'description', 'currency', 'amount_minor', 'is_per_person']),
                    ['status' => TripCost::ESTIMATED],
                ));
            }

            return $to;
        });
    }
}
