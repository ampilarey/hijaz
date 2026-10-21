<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property string|null $seat_number
 * @property string $visa_status
 * @property string|null $visa_number
 * @property string|null $visa_agent
 * @property Carbon|null $visa_submitted_on
 * @property Carbon|null $visa_issued_on
 * @property int|null $bus_group_id
 * @property Carbon|null $checked_in_at
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
    protected $fillable = ['booking_id', 'pilgrim_id', 'occupancy', 'pax_type', 'price_tier_id', 'amount_minor', 'currency', 'is_lead', 'ticket_number', 'seat_number', 'visa_status', 'visa_number', 'visa_agent', 'visa_submitted_on', 'visa_issued_on', 'bus_group_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_lead' => 'boolean', 'amount_minor' => 'integer', 'visa_submitted_on' => 'date', 'visa_issued_on' => 'date', 'checked_in_at' => 'datetime'];
    }

    public const VISA_ISSUED = 'issued';

    public const VISA_SUBMITTED = 'submitted';

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

    /** @return BelongsTo<BusGroup, $this> */
    public function busGroup(): BelongsTo
    {
        return $this->belongsTo(BusGroup::class);
    }

    /** @return HasMany<RoomAssignment, $this> */
    public function roomAssignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class);
    }

    /** @return HasMany<Card, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function roomIn(TripHotel $hotel): ?Room
    {
        return $this->roomAssignments->map(fn (RoomAssignment $a): ?Room => $a->room)->first(fn (?Room $r): bool => $r !== null && $r->trip_hotel_id === $hotel->getKey());
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    public function visaLabel(): string
    {
        return self::VISA_STATUSES[$this->visa_status] ?? $this->visa_status;
    }

    /** Submitted to the agent and nothing back after the agreed days. */
    public function visaIsLate(): bool
    {
        return $this->visa_status === self::VISA_SUBMITTED
            && $this->visa_submitted_on !== null
            && $this->visa_submitted_on->addDays((int) config('booking.visa_sla_days', 7))->isPast();
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
