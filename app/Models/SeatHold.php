<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Seats taken out of the pool for a booking. Live while unreleased and
 * unexpired; confirmed seats move from held to confirmed.
 *
 * @property int $trip_id
 * @property int|null $booking_id
 * @property int $seats
 * @property Carbon $expires_at
 * @property Carbon|null $released_at
 * @property Carbon|null $confirmed_at
 * @property string|null $released_reason
 */
class SeatHold extends Model
{
    public const EXPIRED = 'expired';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $fillable = ['trip_id', 'booking_id', 'seats', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'released_at' => 'datetime', 'confirmed_at' => 'datetime', 'seats' => 'integer'];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isLive(): bool
    {
        return $this->released_at === null && $this->expires_at->isFuture();
    }

    public function hasLapsed(): bool
    {
        return $this->released_at === null && $this->expires_at->isPast();
    }
}
