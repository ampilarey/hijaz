<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\BusGroup;
use App\Models\Trip;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who sits on which coach. Assignment is a person's decision; this makes it
 * and then reports what is wrong: a bus over capacity, a family split, a
 * confirmed traveller on no bus at all.
 */
final class Buses
{
    public const OVER_CAPACITY = 'over_capacity';

    public const SPLIT_FAMILY = 'split_family';

    public const UNASSIGNED = 'unassigned';

    public const NO_LEADER = 'no_leader';

    /**
     * @param  list<int>  $bookingPilgrimIds
     * @return array{assigned: int, skipped: list<string>, warnings: list<string>}
     */
    public function assign(BusGroup $bus, array $bookingPilgrimIds): array
    {
        return DB::transaction(function () use ($bus, $bookingPilgrimIds): array {
            $members = BookingPilgrim::query()->whereKey($bookingPilgrimIds)->with(['pilgrim', 'booking'])->get();
            $assigned = 0;
            $skipped = [];

            foreach ($members as $bp) {
                if ($bp->booking->trip_id !== $bus->trip_id) {
                    $skipped[] = $bp->pilgrim->name_en.': on another trip';

                    continue;
                }

                if ($bp->bus_group_id === $bus->getKey()) {
                    continue;
                }

                // A bus carries one group; someone already in another group stays off it.
                if ($bus->trip_group_id !== null && $bp->trip_group_id !== null && $bp->trip_group_id !== $bus->trip_group_id) {
                    $skipped[] = $bp->pilgrim->name_en.': in '.($bp->group?->name ?? 'another group').', not '.($bus->group?->name ?? 'this bus\'s group');

                    continue;
                }

                $bp->forceFill(array_filter(['bus_group_id' => $bus->getKey(), 'trip_group_id' => $bus->trip_group_id ?? $bp->trip_group_id]))->save();
                $assigned++;
            }

            $warnings = [];

            if ($bus->isOverfull()) {
                $warnings[] = $bus->name.' now has '.$bus->members()->count().' people for '.$bus->capacity.' seats.';
            }

            foreach ($this->splitFamilies($bus->trip) as $split) {
                if ($members->pluck('booking_id')->contains($split['booking_id'])) {
                    $warnings[] = $split['detail'];
                }
            }

            return ['assigned' => $assigned, 'skipped' => $skipped, 'warnings' => $warnings];
        });
    }

    /** @param  list<int>  $bookingPilgrimIds */
    public function unassign(array $bookingPilgrimIds): int
    {
        return BookingPilgrim::query()->whereKey($bookingPilgrimIds)->whereNotNull('bus_group_id')->get()
            ->each(fn (BookingPilgrim $bp) => $bp->forceFill(['bus_group_id' => null, 'checked_in_at' => null])->save())
            ->count();
    }

    /**
     * Everything wrong with a trip's buses.
     *
     * @return list<array{type: string, bus: ?string, who: ?string, detail: string}>
     */
    public function problemsWith(Trip $trip): array
    {
        $problems = [];

        foreach ($trip->busGroups()->with('members.pilgrim')->get() as $bus) {
            if ($bus->isOverfull()) {
                $problems[] = ['type' => self::OVER_CAPACITY, 'bus' => $bus->name, 'who' => null, 'detail' => $bus->members->count().' people for '.$bus->capacity.' seats.'];
            }

            if ($bus->leaderLabel() === null) {
                $problems[] = ['type' => self::NO_LEADER, 'bus' => $bus->name, 'who' => null, 'detail' => 'No leader named.'];
            }
        }

        foreach ($this->splitFamilies($trip) as $split) {
            $problems[] = ['type' => self::SPLIT_FAMILY, 'bus' => $split['buses'], 'who' => $split['who'], 'detail' => $split['detail']];
        }

        $unassigned = $this->confirmedTravellers($trip)->filter(fn (BookingPilgrim $bp): bool => $bp->bus_group_id === null);

        if ($unassigned->isNotEmpty() && $trip->busGroups()->exists()) {
            $problems[] = ['type' => self::UNASSIGNED, 'bus' => null, 'who' => $unassigned->map(fn (BookingPilgrim $bp) => $bp->pilgrim->name_en)->implode(', '), 'detail' => $unassigned->count().' confirmed traveller'.($unassigned->count() === 1 ? '' : 's').' on no bus.'];
        }

        return $problems;
    }

    /** @return Collection<int, BookingPilgrim> */
    public function confirmedTravellers(Trip $trip): Collection
    {
        return BookingPilgrim::query()
            ->whereIn('booking_id', Booking::query()->where('trip_id', $trip->getKey())->where('status', Booking::CONFIRMED)->select('id'))
            ->with(['pilgrim', 'busGroup'])
            ->get();
    }

    /**
     * Bookings whose people sit on different buses.
     *
     * @return list<array{booking_id: int, who: string, buses: string, detail: string}>
     */
    private function splitFamilies(Trip $trip): array
    {
        $out = [];

        $byBooking = BookingPilgrim::query()
            ->whereIn('booking_id', Booking::query()->where('trip_id', $trip->getKey())->live()->select('id'))
            ->whereNotNull('bus_group_id')
            ->with(['busGroup', 'booking.customer'])
            ->get()
            ->groupBy('booking_id');

        foreach ($byBooking as $bookingId => $members) {
            $buses = $members->map(fn (BookingPilgrim $bp) => $bp->busGroup?->name)->filter()->unique()->values();

            if ($buses->count() > 1) {
                $customer = $members->first()->booking->customer->name;
                $out[] = ['booking_id' => (int) $bookingId, 'who' => $customer, 'buses' => $buses->implode(' and '), 'detail' => $customer.' are split across '.$buses->implode(' and ').'.'];
            }
        }

        return $out;
    }
}
