<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One traveller's line on the bank's USD list for a trip. */
class UsdAllocation extends Model
{
    /** @var list<string> */
    protected $fillable = ['trip_id', 'booking_pilgrim_id', 'amount_minor', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<BookingPilgrim, $this> */
    public function bookingPilgrim(): BelongsTo
    {
        return $this->belongsTo(BookingPilgrim::class);
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, 'USD');
    }
}
