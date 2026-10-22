<?php

namespace App\Models;

use App\Exceptions\IllegalBookingTransition;
use App\Support\Currency;
use App\Support\Money;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A customer's seats on a trip. The lines are the money, each in its own
 * currency; `totals` and `paid` are cached sums per currency, re-derived
 * under a lock whenever a line or an allocation changes.
 *
 * @property int $id
 * @property string $reference
 * @property int $trip_id
 * @property int $customer_id
 * @property string $status
 * @property int $seats
 * @property array<string, int>|null $totals
 * @property array<string, int>|null $paid
 * @property array<string, mixed>|null $trip_snapshot
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 */
class Booking extends Model implements TakesPayments
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    public const DRAFT = 'draft';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    public const COMPLETED = 'completed';

    /** @var array<string, string> */
    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::CONFIRMED => 'Confirmed',
        self::CANCELLED => 'Cancelled',
        self::COMPLETED => 'Completed',
    ];

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::DRAFT => [self::CONFIRMED, self::CANCELLED],
        self::CONFIRMED => [self::COMPLETED, self::CANCELLED],
        self::CANCELLED => [],
        self::COMPLETED => [],
    ];

    /** @var list<string> */
    protected $fillable = ['trip_id', 'customer_id', 'notes', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT, 'seats' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['totals' => 'array', 'paid' => 'array', 'trip_snapshot' => 'array', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $b): void {
            $b->created_by ??= Auth::id();
            $b->reference = $b->reference ?: 'PENDING';
        });

        static::created(function (Booking $b): void {
            if ($b->reference === 'PENDING') {
                $b->forceFill(['reference' => sprintf('%s-B-%s-%04d', config('booking.reference_prefix', 'HJZ'), now()->format('Y'), $b->getKey())])->saveQuietly();
            }
        });
    }

    // ── Relations ────────────────────────────────────────────────────────

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<BookingPilgrim, $this> */
    public function pilgrims(): HasMany
    {
        return $this->hasMany(BookingPilgrim::class)->orderByDesc('is_lead')->orderBy('id');
    }

    /** @return HasMany<BookingLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BookingLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Discount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    /** @return HasMany<PaymentSchedule, $this> */
    public function schedule(): HasMany
    {
        return $this->hasMany(PaymentSchedule::class)->orderBy('due_on');
    }

    /** @return MorphMany<Document, $this> Signed agreement scans. */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** @return HasOne<Agreement, $this> */
    public function agreement(): HasOne
    {
        return $this->hasOne(Agreement::class);
    }

    /** @return MorphMany<Payment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->orderByDesc('id');
    }

    /** @return HasMany<SeatHold, $this> */
    public function seatHolds(): HasMany
    {
        return $this->hasMany(SeatHold::class);
    }

    /** @return HasMany<BookingStatusTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(BookingStatusTransition::class)->orderBy('id');
    }

    /** @return HasMany<PortalAccess, $this> */
    public function accesses(): HasMany
    {
        return $this->hasMany(PortalAccess::class);
    }

    /** @return HasMany<ExcursionBooking, $this> */
    public function excursionBookings(): HasMany
    {
        return $this->hasMany(ExcursionBooking::class);
    }

    // ── Status ───────────────────────────────────────────────────────────

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function transitionTo(string $status, ?string $reason = null): void
    {
        if (! $this->canTransitionTo($status)) {
            throw IllegalBookingTransition::from($this->status, $status);
        }

        $from = $this->status;
        $this->forceFill(array_filter([
            'status' => $status,
            'confirmed_at' => $status === self::CONFIRMED ? now() : null,
            'cancelled_at' => $status === self::CANCELLED ? now() : null,
            'cancellation_reason' => $status === self::CANCELLED ? $reason : null,
        ], fn ($v) => $v !== null))->save();

        $this->transitions()->create(['from_status' => $from, 'to_status' => $status, 'user_id' => Auth::id(), 'reason' => $reason]);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isLive(): bool
    {
        return in_array($this->status, [self::DRAFT, self::CONFIRMED], true);
    }

    /** @param  Builder<Booking>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', [self::DRAFT, self::CONFIRMED]);
    }

    // ── Money ────────────────────────────────────────────────────────────

    /** @return list<string> */
    public function currencies(): array
    {
        return array_values(array_unique(array_merge(array_keys($this->totals ?? []), array_keys($this->paid ?? []))));
    }

    public function total(string $currency): Money
    {
        return Money::ofMinor((int) ($this->totals[$currency] ?? 0), $currency);
    }

    public function paidIn(string $currency): Money
    {
        return Money::ofMinor((int) ($this->paid[$currency] ?? 0), $currency);
    }

    public function balance(string $currency): Money
    {
        return $this->total($currency)->subtract($this->paidIn($currency));
    }

    /** @return list<Money> */
    public function balances(): array
    {
        return array_values(array_filter(
            array_map(fn (string $c): Money => $this->balance($c), $this->currencies()),
            fn (Money $m): bool => ! $m->isZero(),
        ));
    }

    public function isPaidUp(): bool
    {
        foreach ($this->currencies() as $currency) {
            if ($this->balance($currency)->minor > 0) {
                return false;
            }
        }

        return true;
    }

    /** "MVR 28,500 and USD 300 due", for lists and messages. */
    public function balanceSummary(): string
    {
        $due = array_filter($this->balances(), fn (Money $m): bool => $m->minor > 0);

        if ($this->awaitsPrice()) {
            return $due === [] ? 'price not set yet' : implode(' and ', array_map(fn (Money $m): string => $m->format(), $due)).' due · price not set for everyone';
        }

        if ($due === []) {
            return $this->currencies() === [] ? 'nothing priced yet' : 'paid in full';
        }

        return implode(' and ', array_map(fn (Money $m): string => $m->format(), $due)).' due';
    }

    /** Someone on it has no fare yet: the trip had no price for their room when they were registered. */
    public function awaitsPrice(): bool
    {
        return $this->pilgrims()->whereNull('price_tier_id')->where('amount_minor', 0)->exists();
    }

    /** Re-derive the per-currency totals from the lines. The ledger does the same for paid. */
    public function recalculateTotals(): void
    {
        $totals = [];

        foreach ($this->lines()->get() as $line) {
            $totals[$line->currency] = ($totals[$line->currency] ?? 0) + $line->amount_minor;
        }

        ksort($totals);
        $this->forceFill(['totals' => $totals ?: null])->save();
    }

    public function paymentCurrencies(): array
    {
        return $this->currencies() ?: [Currency::MVR];
    }

    public function storePaid(array $paidByCurrency): void
    {
        ksort($paidByCurrency);
        $this->forceFill(['paid' => $paidByCurrency ?: null])->save();
    }

    public function payableLabel(): string
    {
        return $this->reference.' · '.($this->customer?->name ?? '');
    }

    public function getLabelAttribute(): string
    {
        return $this->reference.' · '.($this->customer?->name ?? '').' · '.($this->trip?->name ?? '');
    }
}
