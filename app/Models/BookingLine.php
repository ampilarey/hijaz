<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $booking_id
 * @property int|null $booking_pilgrim_id
 * @property string $type
 * @property string $description
 * @property int $quantity
 * @property int $unit_amount_minor
 * @property int $amount_minor
 * @property string $currency
 * @property int|null $trip_excursion_id
 */
class BookingLine extends Model
{
    public const SEAT = 'seat';

    public const ROOM_UPGRADE = 'room_upgrade';

    public const EXTRA = 'extra';

    public const DISCOUNT = 'discount';

    public const FEE = 'fee';

    /** @var array<string, string> */
    public const TYPES = [
        self::SEAT => 'Seat',
        self::ROOM_UPGRADE => 'Room upgrade',
        self::EXTRA => 'Extra',
        self::DISCOUNT => 'Discount',
        self::FEE => 'Fee',
    ];

    /** @var list<string> */
    protected $fillable = ['booking_id', 'booking_pilgrim_id', 'type', 'description', 'quantity', 'unit_amount_minor', 'amount_minor', 'currency', 'trip_excursion_id', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_amount_minor' => 'integer', 'amount_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(fn (BookingLine $line) => $line->booking?->recalculateTotals());
        static::deleted(fn (BookingLine $line) => $line->booking?->recalculateTotals());
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
