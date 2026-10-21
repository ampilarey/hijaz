<?php

namespace App\Console\Commands;

use App\Models\Trip;
use App\Services\Bookings\SeatAllocator;
use Illuminate\Console\Command;

/**
 * Gives back the seats of draft bookings nobody confirmed in time. Safe to
 * run late and twice: the allocator only touches holds that have lapsed.
 */
class ExpireSeatHolds extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Release seat holds that have lapsed and cancel their draft bookings';

    public function handle(SeatAllocator $seats): int
    {
        $released = 0;

        Trip::query()->live()->whereHas('seatHoldsRelation', fn ($q) => $q->whereNull('released_at')->where('expires_at', '<=', now()))
            ->each(function (Trip $trip) use ($seats, &$released): void {
                $released += $seats->reclaim($trip);
            });

        $this->info("Released {$released} seat(s).");

        return self::SUCCESS;
    }
}
