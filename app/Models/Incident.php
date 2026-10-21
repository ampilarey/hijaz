<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Something that went wrong on the ground: who, what, how bad, where, and
 * what was done about it.
 *
 * @property string|null $client_uuid
 * @property string|null $reference
 * @property int $trip_id
 * @property int|null $booking_pilgrim_id
 * @property string $severity
 * @property string $category
 * @property string $summary
 * @property string|null $detail
 * @property string|null $location
 * @property Carbon $happened_at
 * @property string $status
 * @property Carbon|null $resolved_at
 * @property string|null $resolution
 */
class Incident extends Model
{
    public const MINOR = 'minor';

    public const SERIOUS = 'serious';

    public const EMERGENCY = 'emergency';

    /** @var array<string, string> */
    public const SEVERITIES = [self::EMERGENCY => 'Emergency', self::SERIOUS => 'Serious', self::MINOR => 'Minor'];

    /** @var array<string, string> */
    public const CATEGORIES = [
        'medical' => 'Medical',
        'lost_document' => 'Lost passport or document',
        'missing_person' => 'Missing person',
        'transport' => 'Transport',
        'accommodation' => 'Hotel or room',
        'lost_luggage' => 'Lost luggage',
        'conduct' => 'Conduct',
        'other' => 'Other',
    ];

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    /** @var list<string> */
    protected $fillable = ['client_uuid', 'trip_id', 'booking_pilgrim_id', 'severity', 'category', 'summary', 'detail', 'location', 'happened_at', 'recorded_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::OPEN, 'category' => 'other'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['happened_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Incident $i): void {
            $i->recorded_by ??= Auth::id();
            $i->happened_at ??= now();
        });

        static::created(function (Incident $i): void {
            if ($i->reference === null) {
                $i->forceFill(['reference' => sprintf('INC-%s-%04d', now()->format('Y'), $i->getKey())])->saveQuietly();
            }
        });
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<BookingPilgrim, $this> */
    public function bookingPilgrim(): BelongsTo
    {
        return $this->belongsTo(BookingPilgrim::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function resolve(string $resolution, ?User $by = null): void
    {
        $this->forceFill(['status' => self::RESOLVED, 'resolved_at' => now(), 'resolved_by' => ($by ?? Auth::user())?->getKey(), 'resolution' => $resolution])->save();
    }

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity] ?? $this->severity;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /** @param  Builder<Incident>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::OPEN);
    }
}
