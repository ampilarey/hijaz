<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $excursion_booking_id
 * @property int $booking_pilgrim_id
 * @property int $amount_minor
 */
class ExcursionParticipant extends Model
{
    /** @var list<string> */
    protected $fillable = ['excursion_booking_id', 'booking_pilgrim_id', 'amount_minor'];

    protected static function booted(): void
    {
        static::saved(fn (ExcursionParticipant $p) => $p->excursionBooking?->recalculateTotal());
        static::deleted(fn (ExcursionParticipant $p) => $p->excursionBooking?->recalculateTotal());
    }

    /** @return BelongsTo<ExcursionBooking, $this> */
    public function excursionBooking(): BelongsTo
    {
        return $this->belongsTo(ExcursionBooking::class);
    }

    /** @return BelongsTo<BookingPilgrim, $this> */
    public function bookingPilgrim(): BelongsTo
    {
        return $this->belongsTo(BookingPilgrim::class);
    }
}
