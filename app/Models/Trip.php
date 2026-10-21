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
 * @property Carbon $departs_on
 * @property Carbon $returns_on
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
        static::creating(function (Trip $trip): void {
            $trip->code = $trip->code ?: static::nextCode($trip->departs_on);
            $trip->created_by ??= Auth::id();
        });
    }

    /** "UMR-2027-07", with a suffix when a month already has one. */
    public static function nextCode(Carbon|string $departsOn): string
    {
        $base = 'UMR-'.Carbon::parse($departsOn)->format('Y-m');
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
        return max(0, (int) $this->departs_on->diffInDays($this->returns_on));
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
        return $this->name.' ('.$this->departs_on->format('j M').' – '.$this->returns_on->format('j M Y').')';
    }
}
