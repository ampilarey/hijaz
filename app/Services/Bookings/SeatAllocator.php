<?php

namespace App\Services\Bookings;

use App\Exceptions\NoSeatsAvailable;
use App\Models\Booking;
use App\Models\SeatHold;
use App\Models\Trip;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only code that moves a trip's seat counters. It takes a row lock on
 * the trip first, so two bookings for the last seat take turns, and the
 * database constraint is the backstop underneath.
 */
final class SeatAllocator
{
    public function hold(Trip $trip, int $seats, ?Booking $booking = null, ?CarbonInterface $expiresAt = null): SeatHold
    {
        if ($seats < 1) {
            throw new \InvalidArgumentException('A hold must be for at least one seat.');
        }

        return DB::transaction(function () use ($trip, $seats, $booking, $expiresAt): SeatHold {
            $locked = $this->lock($trip);
            $this->reclaimLapsedOn($locked);

            if ($locked->capacity_total < 1) {
                throw NoSeatsAvailable::becauseCapacityIsUnset($locked);
            }

            $remaining = $locked->capacity_total - $locked->capacity_held - $locked->capacity_confirmed;

            if ($seats > $remaining) {
                throw NoSeatsAvailable::on($locked, $seats, max(0, $remaining));
            }

            $this->setCounters($locked, held: $locked->capacity_held + $seats);

            $hold = $locked->seatHoldsRelation()->create([
                'booking_id' => $booking?->getKey(),
                'seats' => $seats,
                'expires_at' => $expiresAt ?? now()->addDays((int) config('booking.hold_days', 7)),
            ]);

            $trip->setRawAttributes($locked->getAttributes(), sync: true);

            return $hold;
        });
    }

    public function confirm(SeatHold $hold): void
    {
        DB::transaction(function () use ($hold): void {
            $locked = $this->lock($hold->trip);
            $this->reclaimLapsedOn($locked);
            $hold->refresh();

            if ($hold->confirmed_at !== null) {
                return;
            }

            if ($hold->released_at === null) {
                $this->setCounters($locked, held: max(0, $locked->capacity_held - $hold->seats), confirmed: $locked->capacity_confirmed + $hold->seats);
            } else {
                $remaining = $locked->capacity_total - $locked->capacity_held - $locked->capacity_confirmed;

                if ($hold->seats > $remaining) {
                    throw NoSeatsAvailable::on($locked, $hold->seats, max(0, $remaining));
                }

                $this->setCounters($locked, confirmed: $locked->capacity_confirmed + $hold->seats);
            }

            $hold->forceFill(['confirmed_at' => now(), 'released_at' => $hold->released_at ?? now(), 'released_reason' => SeatHold::CONFIRMED])->save();
        });
    }

    public function release(SeatHold $hold, string $reason = SeatHold::CANCELLED): void
    {
        DB::transaction(function () use ($hold, $reason): void {
            $locked = $this->lock($hold->trip);
            $hold->refresh();

            if ($hold->released_at !== null) {
                return;
            }

            $this->setCounters($locked, held: max(0, $locked->capacity_held - $hold->seats));
            $hold->forceFill(['released_at' => now(), 'released_reason' => $reason])->save();
        });
    }

    /** Give a confirmed booking's seats back, once, however many times this is called. */
    public function releaseConfirmed(Booking $booking): void
    {
        DB::transaction(function () use ($booking): void {
            $locked = $this->lock($booking->trip);

            $hold = $booking->seatHolds()->whereNotNull('confirmed_at')->where('released_reason', SeatHold::CONFIRMED)->latest('id')->first();

            if (! $hold instanceof SeatHold) {
                return;
            }

            $this->setCounters($locked, confirmed: max(0, $locked->capacity_confirmed - $hold->seats));
            $hold->forceFill(['released_reason' => SeatHold::CANCELLED])->save();
        });
    }

    /** Change the seats a live hold covers (a pilgrim added or removed before confirmation). */
    public function resize(SeatHold $hold, int $seats): SeatHold
    {
        return DB::transaction(function () use ($hold, $seats): SeatHold {
            $locked = $this->lock($hold->trip);
            $hold->refresh();

            if ($hold->confirmed_at !== null || $hold->released_at !== null) {
                throw new \InvalidArgumentException('Only a live hold can be resized.');
            }

            $delta = $seats - $hold->seats;
            $remaining = $locked->capacity_total - $locked->capacity_held - $locked->capacity_confirmed;

            if ($delta > $remaining) {
                throw NoSeatsAvailable::on($locked, $delta, max(0, $remaining));
            }

            $this->setCounters($locked, held: max(0, $locked->capacity_held + $delta));
            $hold->forceFill(['seats' => $seats])->save();

            return $hold;
        });
    }

    public function extend(SeatHold $hold, CarbonInterface $until): SeatHold
    {
        return DB::transaction(function () use ($hold, $until): SeatHold {
            $locked = $this->lock($hold->trip);
            $hold->refresh();

            if ($hold->confirmed_at !== null) {
                throw new \InvalidArgumentException('Those seats are already confirmed.');
            }

            if ($hold->isLive()) {
                $hold->forceFill(['expires_at' => $until])->save();

                return $hold;
            }

            if ($hold->released_at === null) {
                $this->setCounters($locked, held: max(0, $locked->capacity_held - $hold->seats));
                $hold->forceFill(['released_at' => now(), 'released_reason' => SeatHold::EXPIRED])->save();
            }

            $remaining = $locked->capacity_total - $locked->capacity_held - $locked->capacity_confirmed;

            if ($hold->seats > $remaining) {
                throw NoSeatsAvailable::on($locked, $hold->seats, max(0, $remaining));
            }

            $this->setCounters($locked, held: $locked->capacity_held + $hold->seats);

            return $locked->seatHoldsRelation()->create(['booking_id' => $hold->booking_id, 'seats' => $hold->seats, 'expires_at' => $until]);
        });
    }

    /** Expire lapsed holds on a trip; their draft bookings are cancelled. Returns seats returned. */
    public function reclaim(Trip $trip): int
    {
        return DB::transaction(fn (): int => $this->reclaimLapsedOn($this->lock($trip)));
    }

    private function reclaimLapsedOn(Trip $locked): int
    {
        $seats = 0;

        foreach ($locked->seatHoldsRelation()->whereNull('released_at')->where('expires_at', '<=', now())->get() as $hold) {
            $seats += $hold->seats;
            $hold->forceFill(['released_at' => now(), 'released_reason' => SeatHold::EXPIRED])->save();

            if ($hold->booking && $hold->booking->status === Booking::DRAFT) {
                $hold->booking->transitionTo(Booking::CANCELLED, 'Seats were held for '.config('booking.hold_days', 7).' days and the booking was not confirmed.');
            }
        }

        if ($seats > 0) {
            $this->setCounters($locked, held: max(0, $locked->capacity_held - $seats));
        }

        return $seats;
    }

    private function lock(Trip $trip): Trip
    {
        return Trip::query()->whereKey($trip->getKey())->lockForUpdate()->firstOrFail();
    }

    private function setCounters(Trip $locked, ?int $held = null, ?int $confirmed = null): void
    {
        $locked->forceFill(array_filter([
            'capacity_held' => $held,
            'capacity_confirmed' => $confirmed,
        ], fn ($v) => $v !== null))->save();
    }
}
