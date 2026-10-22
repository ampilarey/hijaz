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
    protected $fillable = ['booking_id', 'pilgrim_id', 'occupancy', 'pax_type', 'price_tier_id', 'amount_minor', 'currency', 'is_lead', 'ticket_number', 'seat_number', 'visa_status', 'visa_number', 'visa_agent', 'visa_submitted_on', 'visa_issued_on', 'bus_group_id', 'outbound_flight_id', 'return_flight_id'];

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
    /** @return BelongsTo<TripFlight, $this> */
    public function outboundFlight(): BelongsTo
    {
        return $this->belongsTo(TripFlight::class, 'outbound_flight_id');
    }

    /** @return BelongsTo<TripFlight, $this> */
    public function returnFlight(): BelongsTo
    {
        return $this->belongsTo(TripFlight::class, 'return_flight_id');
    }

    /** The flight out: the one they were put on, or the trip's only outbound flight. */
    public function flightOut(): ?TripFlight
    {
        return $this->outboundFlight ?? $this->booking->trip->onlyFlight(TripFlight::OUTBOUND);
    }

    /** The flight home, the same way. */
    public function flightBack(): ?TripFlight
    {
        return $this->returnFlight ?? $this->booking->trip->onlyFlight(TripFlight::RETURN);
    }

    /** When this traveller leaves: their own flight's day, else the trip's departure date. */
    public function departsOn(): ?Carbon
    {
        return $this->flightOut()?->departs_at?->copy()->startOfDay() ?? $this->booking->trip->departs_on;
    }

    /** When this traveller is home: their own return flight's day, else the trip's return date. */
    public function returnsOn(): ?Carbon
    {
        $back = $this->flightBack();

        return ($back?->arrives_at ?? $back?->departs_at)?->copy()->startOfDay() ?? $this->booking->trip->returns_on;
    }

    /** "SV 789 · 30 Nov" out and back, or what is known. */
    public function flightsLabel(): string
    {
        $part = fn (?TripFlight $f): string => $f ? trim($f->flight_number).' '.$f->departs_at->format('j M') : '—';

        return $part($this->flightOut()).' / '.$part($this->flightBack());
    }

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
