<?php

namespace App\Services\Operations;

use App\Models\BookingPilgrim;
use App\Models\TripGroup;
use Illuminate\Support\Facades\DB;

/** Puts travellers in a flight group. Someone moved off a group comes off that group's bus too. */
final class Groups
{
    /**
     * @param  list<int>  $bookingPilgrimIds
     * @return array{assigned: int, skipped: list<string>, offBus: int}
     */
    public function assign(TripGroup $group, array $bookingPilgrimIds): array
    {
        return DB::transaction(function () use ($group, $bookingPilgrimIds): array {
            $assigned = 0;
            $offBus = 0;
            $skipped = [];

            foreach (BookingPilgrim::query()->whereKey($bookingPilgrimIds)->with(['pilgrim', 'booking', 'busGroup'])->get() as $bp) {
                if ($bp->booking->trip_id !== $group->trip_id) {
                    $skipped[] = $bp->pilgrim->name_en.': on another trip';

                    continue;
                }

                if ($bp->trip_group_id === $group->getKey()) {
                    continue;
                }

                $changes = ['trip_group_id' => $group->getKey()];

                if ($bp->busGroup !== null && $bp->busGroup->trip_group_id !== null && $bp->busGroup->trip_group_id !== $group->getKey()) {
                    $changes += ['bus_group_id' => null, 'checked_in_at' => null];
                    $offBus++;
                }

                $bp->forceFill($changes)->save();
                $assigned++;
            }

            return ['assigned' => $assigned, 'skipped' => $skipped, 'offBus' => $offBus];
        });
    }

    /** @param  list<int>  $bookingPilgrimIds */
    public function unassign(array $bookingPilgrimIds): int
    {
        return BookingPilgrim::query()->whereKey($bookingPilgrimIds)->whereNotNull('trip_group_id')->get()
            ->each(fn (BookingPilgrim $bp) => $bp->forceFill(['trip_group_id' => null])->save())
            ->count();
    }
}
