<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One pilgrim on one booking: their seat, occupancy and what it cost.
 *
 * @property int $booking_id
 * @property int $pilgrim_id
 * @property string $occupancy
 * @property string $pax_type
 * @property int|null $price_tier_id
 * @property int $amount_minor
 * @property string $currency
 * @property bool $is_lead
 * @property string|null $ticket_number
 * @property string $visa_status
 */
class BookingPilgrim extends Model
{
    /** @var array<string, string> */
    public const VISA_STATUSES = [
        'not_started' => 'Not started',
        'submitted' => 'Submitted',
        'issued' => 'Issued',
        'rejected' => 'Rejected',
    ];

    /** @var list<string> */
    protected $fillable = ['booking_id', 'pilgrim_id', 'occupancy', 'pax_type', 'price_tier_id', 'amount_minor', 'currency', 'is_lead', 'ticket_number', 'visa_status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_lead' => 'boolean', 'amount_minor' => 'integer'];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Pilgrim, $this> */
    public function pilgrim(): BelongsTo
    {
        return $this->belongsTo(Pilgrim::class);
    }

    /** @return BelongsTo<PriceTier, $this> */
    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }

    /** @return HasMany<BookingLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BookingLine::class);
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
