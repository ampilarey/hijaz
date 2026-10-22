<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\BookingPilgrim;
use App\Models\PriceTier;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Trip;
use App\Models\TripHotel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Who sleeps where, and what is wrong with it. Ported from Rihla: it reports
 * and never allocates, because a heuristic that separates a mother from her
 * children is an argument at a hotel desk at two in the morning. The one
 * shortcut, "room the family", puts one booking's people into one room.
 *
 * Only people on confirmed bookings are owed a bed.
 */
final class Rooming
{
    public const OVER_CAPACITY = 'over_capacity';

    public const MIXED_GENDER = 'mixed_gender';

    public const UNDESIGNATED = 'undesignated';

    public const UNACCOMPANIED_CHILD = 'unaccompanied_child';

    public const DOUBLE_BOOKED = 'double_booked';

    public const UNROOMED = 'unroomed';

    public const WRONG_OCCUPANCY = 'wrong_occupancy';

    /** @var array<string, int> Beds a booked occupancy means. */
    private const BEDS = [PriceTier::SINGLE => 1, PriceTier::DOUBLE => 2, PriceTier::TRIPLE => 3, PriceTier::QUAD => 4, PriceTier::QUINT => 5];

    /**
     * @param  list<int>  $bookingPilgrimIds
     * @return array{assigned: int, skipped: list<string>}
     */
    public function assign(Room $room, array $bookingPilgrimIds): array
    {
        return DB::transaction(function () use ($room, $bookingPilgrimIds): array {
            $room->loadMissing('hotel');
            $assigned = 0;
            $skipped = [];

            foreach (BookingPilgrim::query()->whereKey($bookingPilgrimIds)->with(['pilgrim', 'booking', 'roomAssignments.room'])->get() as $bp) {
                if ($bp->booking->trip_id !== $room->hotel->trip_id) {
                    $skipped[] = $bp->pilgrim->name_en.': on another trip';

                    continue;
                }

                // Moving within the hotel: the old bed is given up.
                foreach ($bp->roomAssignments as $existing) {
                    if ($existing->room?->trip_hotel_id === $room->trip_hotel_id && $existing->room_id !== $room->getKey()) {
                        $existing->delete();
                    }
                }

                RoomAssignment::query()->firstOrCreate(['room_id' => $room->getKey(), 'booking_pilgrim_id' => $bp->getKey()], ['assigned_by' => Auth::id()]);
                $assigned++;
            }

            return ['assigned' => $assigned, 'skipped' => $skipped];
        });
    }

    /** @param  list<int>  $bookingPilgrimIds */
    public function unassign(TripHotel $hotel, array $bookingPilgrimIds): int
    {
        return RoomAssignment::query()
            ->whereIn('booking_pilgrim_id', $bookingPilgrimIds)
            ->whereHas('room', fn ($q) => $q->where('trip_hotel_id', $hotel->getKey()))
            ->get()->each->delete()->count();
    }

    /**
     * One booking's people into one room of this hotel: the first family
     * room with enough free beds, or a new one sized for them.
     */
    public function roomFamily(Booking $booking, TripHotel $hotel): Room
    {
        return DB::transaction(function () use ($booking, $hotel): Room {
            $people = $booking->pilgrims()->get();
            $room = $hotel->rooms()->with('assignments')->where('gender', Room::FAMILY)->get()
                ->first(fn (Room $r): bool => $r->bedsFree() >= $people->count());

            if ($room === null) {
                $n = $hotel->rooms()->count() + 1;
                $room = $hotel->rooms()->create(['label' => 'F'.$n, 'capacity' => $people->count(), 'gender' => Room::FAMILY, 'sort_order' => $n]);
            }

            $this->assign($room, $people->pluck('id')->all());

            return $room->fresh(['assignments']);
        });
    }

    /**
     * Everything wrong with one hotel's rooming, in the order it matters at the desk.
     *
     * @return list<array{type: string, room: ?string, who: ?string, detail: string}>
     */
    public function problemsWith(TripHotel $hotel): array
    {
        $hotel->loadMissing(['rooms.assignments.bookingPilgrim.pilgrim', 'trip']);
        $problems = [];

        foreach ($hotel->rooms as $room) {
            $problems = array_merge($problems, $this->problemsInRoom($room, $hotel->trip));
        }

        return array_merge($problems, $this->doubleBooked($hotel), $this->unroomed($hotel));
    }

    public function isSettled(TripHotel $hotel): bool
    {
        return $this->problemsWith($hotel) === [];
    }

    /** @return Collection<int, BookingPilgrim> */
    public function owedABed(Trip $trip): Collection
    {
        return BookingPilgrim::query()
            ->whereIn('booking_id', Booking::query()->where('trip_id', $trip->getKey())->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])->select('id'))
            ->with('pilgrim')
            ->get();
    }

    /** @return list<array{type: string, room: ?string, who: ?string, detail: string}> */
    private function problemsInRoom(Room $room, Trip $trip): array
    {
        $problems = [];
        $occupants = $room->occupants();

        if ($room->isOverfull()) {
            $problems[] = ['type' => self::OVER_CAPACITY, 'room' => $room->label, 'who' => null, 'detail' => $occupants->count().' people in a room with '.($room->capacity === 1 ? 'one bed' : $room->capacity.' beds').'.'];
        }

        if ($occupants->isEmpty()) {
            return $problems;
        }

        $names = fn (Collection $c): string => $c->map(fn (BookingPilgrim $bp) => $bp->pilgrim->name_en)->implode(', ');

        if ($room->gender === Room::FAMILY) {
            // Mixed on purpose.
        } elseif ($room->gender === null) {
            $problems[] = ['type' => self::UNDESIGNATED, 'room' => $room->label, 'who' => null, 'detail' => "Nobody has said whether this is a men's, women's or family room."];
        } else {
            $genders = $occupants->map(fn (BookingPilgrim $bp) => $bp->pilgrim->gender)->filter()->unique()->values();

            if ($genders->count() > 1) {
                $problems[] = ['type' => self::MIXED_GENDER, 'room' => $room->label, 'who' => $names($occupants), 'detail' => 'Men and women share this room, and it is not marked as a family room.'];
            } elseif ($genders->count() === 1 && $genders->first() !== $room->gender) {
                $problems[] = ['type' => self::MIXED_GENDER, 'room' => $room->label, 'who' => $names($occupants), 'detail' => 'Marked for '.strtolower($room->genderLabel()).', occupied by '.$genders->first().'.'];
            }
        }

        $under = (int) config('booking.room_child_under', 16);
        $children = $occupants->filter(fn (BookingPilgrim $bp): bool => ($age = $bp->pilgrim->ageOn($trip->departs_on ?? today())) !== null && $age < $under);

        if ($children->isNotEmpty()) {
            // An adult of their own booking; a stranger's company is not supervision.
            $bookings = $children->pluck('booking_id')->unique();
            $adultsOfTheirs = $occupants->filter(fn (BookingPilgrim $bp): bool => $bookings->contains($bp->booking_id) && (($age = $bp->pilgrim->ageOn($trip->departs_on ?? today())) === null || $age >= $under));

            if ($adultsOfTheirs->isEmpty()) {
                $problems[] = ['type' => self::UNACCOMPANIED_CHILD, 'room' => $room->label, 'who' => $names($children), 'detail' => 'Under '.$under.', with no adult of their family in the room.'];
            }
        }

        // What was paid for against what they got. A single paying in a quad is a complaint.
        foreach ($occupants as $bp) {
            $beds = self::BEDS[$bp->occupancy] ?? null;

            if ($beds !== null && $room->capacity > $beds) {
                $problems[] = ['type' => self::WRONG_OCCUPANCY, 'room' => $room->label, 'who' => $bp->pilgrim->name_en, 'detail' => 'Paid for a '.$bp->occupancy.' room and is in a room with '.$room->capacity.' beds.'];
            }
        }

        return $problems;
    }

    /** @return list<array{type: string, room: ?string, who: ?string, detail: string}> */
    private function doubleBooked(TripHotel $hotel): array
    {
        $seen = [];

        foreach ($hotel->rooms as $room) {
            foreach ($room->assignments as $a) {
                $seen[$a->booking_pilgrim_id][] = [$room->label, $a->bookingPilgrim?->pilgrim?->name_en];
            }
        }

        $problems = [];

        foreach ($seen as $rooms) {
            if (count($rooms) > 1) {
                $problems[] = ['type' => self::DOUBLE_BOOKED, 'room' => implode(' and ', array_column($rooms, 0)), 'who' => $rooms[0][1], 'detail' => 'In more than one room in this hotel.'];
            }
        }

        return $problems;
    }

    /** @return list<array{type: string, room: ?string, who: ?string, detail: string}> */
    private function unroomed(TripHotel $hotel): array
    {
        $roomed = $hotel->rooms->flatMap(fn (Room $r) => $r->assignments->pluck('booking_pilgrim_id'))->unique();
        $missing = $this->owedABed($hotel->trip)->reject(fn (BookingPilgrim $bp): bool => $roomed->contains($bp->getKey()));

        if ($missing->isEmpty()) {
            return [];
        }

        return [['type' => self::UNROOMED, 'room' => null, 'who' => $missing->map(fn (BookingPilgrim $bp) => $bp->pilgrim->name_en)->implode(', '), 'detail' => $missing->count().' confirmed traveller'.($missing->count() === 1 ? '' : 's').' with no room in '.$hotel->name.'.']];
    }
}
