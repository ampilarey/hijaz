<?php

namespace App\Models;

use App\Services\Bookings\BookingDesk;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a seat costs by room occupancy and passenger type. The package
 * tiers are MVR; a single-room (private) tier is the USD upgrade.
 *
 * @property int $trip_id
 * @property string $occupancy
 * @property string $pax_type
 * @property int $amount_minor
 * @property string $currency
 */
class PriceTier extends Model
{
    use HasFactory;

    public const SINGLE = 'single';

    public const DOUBLE = 'double';

    public const TRIPLE = 'triple';

    public const QUAD = 'quad';

    public const QUINT = 'quint';

    /** @var array<string, string> */
    public const OCCUPANCIES = [
        self::DOUBLE => 'Double (2 to a room)',
        self::TRIPLE => 'Triple (3 to a room)',
        self::QUAD => 'Quad (4 to a room)',
        self::QUINT => 'Five to a room',
        self::SINGLE => 'Single (private room)',
    ];

    public const ADULT = 'adult';

    public const CHILD = 'child';

    public const INFANT = 'infant';

    /** @var array<string, string> */
    public const PAX_TYPES = [
        self::ADULT => 'Adult',
        self::CHILD => 'Child (2 to 11)',
        self::INFANT => 'Infant (under 2)',
    ];

    /** A new price reaches everyone who was registered while the trip had none for their room. */
    protected static function booted(): void
    {
        static::saved(fn (PriceTier $tier) => app(BookingDesk::class)->priceWaiting($tier->trip));
    }

    /** @var list<string> */
    protected $fillable = ['trip_id', 'occupancy', 'pax_type', 'amount_minor', 'currency', 'sort_order'];

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

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function label(): string
    {
        return (self::OCCUPANCIES[$this->occupancy] ?? $this->occupancy).', '.strtolower(self::PAX_TYPES[$this->pax_type] ?? $this->pax_type);
    }
}
