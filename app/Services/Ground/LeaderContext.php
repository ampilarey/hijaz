<?php

namespace App\Services\Ground;

use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which trips, and which travellers on them, a person may work with on the
 * ground. Operations sees every trip on the road; a group leader sees the
 * trips they lead a bus on or lead outright, and on them their own bus
 * first. A leader with no bus assigned sees nobody, because the failure of
 * a missing assignment has to be less access, not more.
 */
final class LeaderContext
{
    /** Trips on the road now or within a month, that this person may work on. @return Collection<int, Trip> */
    public function trips(User $user): Collection
    {
        $query = Trip::query()->live()
            ->whereDate('returns_on', '>=', now()->subDays(3))
            ->whereDate('departs_on', '<=', now()->addDays(30))
            ->orderBy('departs_on');

        if (! $this->seesEverything($user)) {
            $query->where(fn ($q) => $q->where('leader_user_id', $user->getKey())->orWhereHas('busGroups', fn ($b) => $b->where('leader_user_id', $user->getKey())));
        }

        return $query->get();
    }

    public function mayWorkOn(User $user, Trip $trip): bool
    {
        if (! $user->can('ground.view')) {
            return false;
        }

        if ($this->seesEverything($user)) {
            return true;
        }

        return $trip->leader_user_id === $user->getKey() || $trip->busGroups()->where('leader_user_id', $user->getKey())->exists();
    }

    /** The confirmed travellers this person is responsible for: their bus, or everyone. @return Collection<int, BookingPilgrim> */
    public function travellers(User $user, Trip $trip): Collection
    {
        $query = BookingPilgrim::query()
            ->whereIn('booking_id', Booking::query()->where('trip_id', $trip->getKey())->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])->select('id'))
            ->with(['pilgrim', 'booking.customer', 'busGroup', 'roomAssignments.room.hotel']);

        $own = $this->ownBuses($user, $trip);

        if (! $this->seesEverything($user) && $trip->leader_user_id !== $user->getKey() && $own !== []) {
            $query->whereIn('bus_group_id', $own);
        }

        return $query->get()->sortBy(fn (BookingPilgrim $bp) => ($bp->busGroup?->name ?? 'zzz').' '.$bp->booking->customer->name.' '.$bp->pilgrim->name_en)->values();
    }

    /** @return list<int> */
    public function ownBuses(User $user, Trip $trip): array
    {
        return $trip->busGroups()->where('leader_user_id', $user->getKey())->pluck('id')->all();
    }

    public function seesEverything(User $user): bool
    {
        return $user->can('bus.assign');
    }
}
