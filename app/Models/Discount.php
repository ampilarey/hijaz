<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $booking_id
 * @property int|null $booking_line_id
 * @property string $kind
 * @property string $value
 * @property string $currency
 * @property int $amount_minor
 * @property string $reason
 * @property int|null $approved_by
 */
class Discount extends Model
{
    public const FIXED = 'fixed';

    public const PERCENT = 'percent';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'approved_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<BookingLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(BookingLine::class, 'booking_line_id');
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
