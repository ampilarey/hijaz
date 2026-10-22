<?php

namespace App\Models;

use App\Exceptions\IllegalTripTransition;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * One Umrah trip: "July 2027 Umrah". Each is set up on its own and can be
 * copied from last year's.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property Carbon|null $departs_on Empty on a draft whose dates are not fixed yet
 * @property Carbon|null $returns_on
 * @property int $capacity_total
 * @property int $capacity_held
 * @property int $capacity_confirmed
 * @property string $status
 * @property int|null $leader_user_id
 * @property string|null $notes
 */
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory;

    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const TRAVELLING = 'travelling';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::OPEN => 'Open for registration',
        self::CLOSED => 'Closed',
        self::TRAVELLING => 'Travelling',
        self::COMPLETED => 'Completed',
        self::CANCELLED => 'Cancelled',
    ];

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::DRAFT => [self::OPEN, self::CANCELLED],
        self::OPEN => [self::CLOSED, self::CANCELLED],
        self::CLOSED => [self::OPEN, self::TRAVELLING, self::CANCELLED],
        self::TRAVELLING => [self::COMPLETED],
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    /** Statuses in which a trip still takes bookings or carries people. */
    public const LIVE = [self::DRAFT, self::OPEN, self::CLOSED, self::TRAVELLING];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::DRAFT,
        'capacity_total' => 0,
        'capacity_held' => 0,
        'capacity_confirmed' => 0,
    ];

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'departs_on', 'returns_on', 'capacity_total', 'leader_user_id', 'notes', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'departs_on' => 'date',
            'returns_on' => 'date',
            'capacity_total' => 'integer',
            'capacity_held' => 'integer',
            'capacity_confirmed' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Admin passes every policy, so the rule lives here too: bookings mean money and pilgrims' history.
        static::deleting(function (Trip $trip): void {
            if ($trip->hasBookings()) {
                throw new \DomainException('A trip with bookings cannot be deleted; cancel it instead.');
            }
        });

        static::creating(function (Trip $trip): void {
            $trip->code = $trip->code ?: static::nextCode($trip->departs_on);
            $trip->created_by ??= Auth::id();
        });

        // A trip made before its dates were known takes its real code once it has a departure date.
        static::updating(function (Trip $trip): void {
            if (str_starts_with((string) $trip->code, self::UNDATED_CODE) && $trip->departs_on !== null) {
                $trip->code = static::nextCode($trip->departs_on);
            }
        });
    }

    /** The code of a trip with no dates yet, until it gets one: "UMR-TBA", "UMR-TBA-2". */
    public const UNDATED_CODE = 'UMR-TBA';

    /** "UMR-2027-07", with a suffix when a month already has one. */
    public static function nextCode(Carbon|string|null $departsOn): string
    {
        $base = $departsOn === null || $departsOn === '' ? self::UNDATED_CODE : 'UMR-'.Carbon::parse($departsOn)->format('Y-m');
        $code = $base;

        for ($n = 2; static::query()->where('code', $code)->exists(); $n++) {
            $code = "{$base}-{$n}";
        }

        return $code;
    }

    // ── Relations ────────────────────────────────────────────────────────

    /** @return BelongsTo<User, $this> */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    /** @return HasMany<PriceTier, $this> */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(PriceTier::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<TripFlight, $this> */
    public function flights(): HasMany
    {
        return $this->hasMany(TripFlight::class)->orderBy('departs_at');
    }

    /** @return HasMany<TripHotel, $this> */
    public function hotels(): HasMany
    {
        return $this->hasMany(TripHotel::class)->orderBy('check_in_on');
    }

    /** @return HasMany<TripStop, $this> */
    public function stops(): HasMany
    {
        return $this->hasMany(TripStop::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<TripExcursion, $this> */
    public function excursions(): HasMany
    {
        return $this->hasMany(TripExcursion::class)->orderBy('sort_order')->orderBy('scheduled_on');
    }

    /** @return HasMany<TripChecklistItem, $this> */
    public function checklist(): HasMany
    {
        return $this->hasMany(TripChecklistItem::class)->orderBy('sort_order')->orderBy('due_on');
    }

    /** @return HasMany<TripCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(TripCost::class)->orderByDesc('id');
    }

    /** @return HasMany<SeatHold, $this> */
    public function seatHoldsRelation(): HasMany
    {
        return $this->hasMany(SeatHold::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** @return HasMany<UsdAllocation, $this> */
    public function usdAllocations(): HasMany
    {
        return $this->hasMany(UsdAllocation::class);
    }

    /** @return HasMany<RollCall, $this> */
    public function rollCalls(): HasMany
    {
        return $this->hasMany(RollCall::class)->orderByDesc('taken_at');
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class)->orderByDesc('happened_at');
    }

    /** @return HasMany<BusGroup, $this> */
    public function busGroups(): HasMany
    {
        return $this->hasMany(BusGroup::class)->orderBy('sort_order')->orderBy('name');
    }

    /** Everyone on a live booking for this trip, through the bookings. @return HasManyThrough<BookingPilgrim, Booking, $this> */
    public function travellers(): HasManyThrough
    {
        return $this->hasManyThrough(BookingPilgrim::class, Booking::class)->whereIn('bookings.status', [Booking::DRAFT, Booking::CONFIRMED]);
    }

    /** @return HasManyThrough<Room, TripHotel, $this> */
    public function rooms(): HasManyThrough
    {
        return $this->hasManyThrough(Room::class, TripHotel::class);
    }

    /** @return HasMany<TripStatusTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(TripStatusTransition::class)->orderBy('id');
    }

    // ── Status ───────────────────────────────────────────────────────────

    /** @return list<string> */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    public function transitionTo(string $status, ?string $reason = null): void
    {
        if (! $this->canTransitionTo($status)) {
            throw IllegalTripTransition::from($this->status, $status);
        }

        if (! $this->hasDates() && in_array($status, [self::TRAVELLING, self::COMPLETED], true)) {
            throw IllegalTripTransition::becauseDatesAreUnset();
        }

        $from = $this->status;
        $this->forceFill(['status' => $status])->save();

        $this->transitions()->create([
            'from_status' => $from,
            'to_status' => $status,
            'user_id' => Auth::id(),
            'reason' => $reason,
        ]);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    /** @param  Builder<Trip>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', self::LIVE);
    }

    /** @param  Builder<Trip>  $query */
    public function scopeUpcoming(Builder $query): void
    {
        $query->live()->whereDate('returns_on', '>=', today())->orderBy('departs_on');
    }

    // ── Derived ──────────────────────────────────────────────────────────

    public function nights(): int
    {
        return $this->hasDates() ? max(0, (int) $this->departs_on->diffInDays($this->returns_on)) : 0;
    }

    /** The one flight in a direction, when there is exactly one; with several, each traveller is put on theirs. */
    public function onlyFlight(string $direction): ?TripFlight
    {
        $flights = $this->flights->where('direction', $direction);

        return $flights->count() === 1 ? $flights->first() : null;
    }

    /**
     * Once flights are entered they decide the dates: the first flight out
     * and the last one home. A direction with no flight keeps what was typed.
     */
    public function syncDatesFromFlights(): void
    {
        $flights = $this->flights()->get();
        $out = $flights->where('direction', TripFlight::OUTBOUND);
        $back = $flights->where('direction', TripFlight::RETURN);

        if ($out->isNotEmpty()) {
            $this->departs_on = $out->min('departs_at')->copy()->startOfDay();
        }

        if ($back->isNotEmpty()) {
            $this->returns_on = $back->map(fn (TripFlight $f) => $f->arrives_at ?? $f->departs_at)->max()->copy()->startOfDay();
        }

        if ($this->isDirty(['departs_on', 'returns_on'])) {
            $this->save();
        }

        $this->unsetRelation('flights');
    }

    /** Both dates set. They may come later, from the flights; a trip cannot travel without them. */
    public function hasDates(): bool
    {
        return $this->departs_on !== null && $this->returns_on !== null;
    }

    /** "30 Nov – 14 Dec 2026", or what is known so far. */
    public function datesLabel(string $from = 'j M', string $to = 'j M Y'): string
    {
        return match (true) {
            $this->hasDates() => $this->departs_on->format($from).' – '.$this->returns_on->format($to),
            $this->departs_on !== null => 'departs '.$this->departs_on->format($to).', return date not set',
            default => 'dates not set',
        };
    }

    /** "30 Nov", or "date not set". */
    public function departsLabel(string $format = 'j M'): string
    {
        return $this->departs_on?->format($format) ?? 'date not set';
    }

    /** Any booking at all, cancelled ones included: each carries money and pilgrims' history. */
    public function hasBookings(): bool
    {
        return $this->bookings()->exists();
    }

    public function seatsTaken(): int
    {
        return $this->capacity_held + $this->capacity_confirmed;
    }

    public function seatsAvailable(): int
    {
        return max(0, $this->capacity_total - $this->seatsTaken());
    }

    /** "MLE → JED → Makkah → Madinah → MED → MLE", from the stops. */
    public function routeSummary(): string
    {
        $stops = $this->stops->map(fn (TripStop $stop): string => $stop->cityLabel())->all();

        if ($stops === []) {
            return '';
        }

        return implode(' → ', array_merge(['MLE'], $stops, ['MLE']));
    }

    public function hotelIn(string $city): ?TripHotel
    {
        return $this->hotels->firstWhere('city', $city);
    }

    public function getLabelAttribute(): string
    {
        return $this->name.' ('.$this->datesLabel().')';
    }
}
