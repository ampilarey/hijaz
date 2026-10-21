<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * A customer's places on an additional trip, priced in riyal and paid in
 * Saudi Arabia. Its own payable so it has its own invoice and trail.
 *
 * @property int $id
 * @property string $reference
 * @property int $trip_excursion_id
 * @property int $booking_id
 * @property int $customer_id
 * @property string $currency
 * @property int $total_minor
 * @property int $paid_minor
 * @property string $status
 */
class ExcursionBooking extends Model implements TakesPayments
{
    public const OPEN = 'open';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $fillable = ['trip_excursion_id', 'booking_id', 'customer_id', 'currency', 'notes', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::OPEN, 'currency' => 'SAR', 'total_minor' => 0, 'paid_minor' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['total_minor' => 'integer', 'paid_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (ExcursionBooking $x): void {
            $x->created_by ??= Auth::id();
            $x->reference = $x->reference ?: 'PENDING';
        });

        static::created(function (ExcursionBooking $x): void {
            if ($x->reference === 'PENDING') {
                $x->forceFill(['reference' => sprintf('%s-X-%s-%04d', config('booking.reference_prefix', 'HJZ'), now()->format('Y'), $x->getKey())])->saveQuietly();
            }
        });
    }

    /** @return BelongsTo<TripExcursion, $this> */
    public function excursion(): BelongsTo
    {
        return $this->belongsTo(TripExcursion::class, 'trip_excursion_id');
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<ExcursionParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ExcursionParticipant::class);
    }

    /** @return MorphMany<Payment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->orderByDesc('id');
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    public function paid(): Money
    {
        return Money::ofMinor($this->paid_minor, $this->currency);
    }

    public function balance(): Money
    {
        return $this->total()->subtract($this->paid());
    }

    public function recalculateTotal(): void
    {
        $this->forceFill(['total_minor' => (int) $this->participants()->sum('amount_minor')])->save();
    }

    public function paymentCurrencies(): array
    {
        return [$this->currency];
    }

    public function storePaid(array $paidByCurrency): void
    {
        $paid = (int) ($paidByCurrency[$this->currency] ?? 0);
        $this->forceFill([
            'paid_minor' => $paid,
            'status' => $this->status === self::CANCELLED ? self::CANCELLED : ($paid >= $this->total_minor && $this->total_minor > 0 ? self::PAID : self::OPEN),
        ])->save();
    }

    public function payableLabel(): string
    {
        return $this->reference.' · '.($this->excursion?->title ?? 'excursion').' · '.($this->customer?->name ?? '');
    }
}
