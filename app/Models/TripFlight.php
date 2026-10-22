<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $direction
 * @property string $airline
 * @property string $flight_number
 * @property string $from_airport
 * @property string $to_airport
 * @property Carbon $departs_at
 * @property Carbon|null $arrives_at
 * @property string|null $pnr
 * @property int|null $seats
 */
class TripFlight extends Model
{
    use HasFactory;

    public const OUTBOUND = 'outbound';

    public const CONNECTING = 'connecting';

    public const RETURN = 'return';

    /** @var array<string, string> */
    public const DIRECTIONS = [
        self::OUTBOUND => 'Outbound',
        self::CONNECTING => 'Connecting',
        self::RETURN => 'Return',
    ];

    /** @var list<string> */
    protected $fillable = ['trip_id', 'direction', 'airline', 'flight_number', 'from_airport', 'to_airport', 'departs_at', 'arrives_at', 'pnr', 'seats', 'notes', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['departs_at' => 'datetime', 'arrives_at' => 'datetime', 'seats' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(fn (TripFlight $flight) => $flight->trip->syncDatesFromFlights());
        static::deleted(fn (TripFlight $flight) => $flight->trip?->syncDatesFromFlights());
    }

    /** "SV 789 MLE → JED · 30 Nov 23:40", for a picker. */
    public function describe(): string
    {
        return $this->label().' · '.$this->departs_at->format('j M H:i');
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function label(): string
    {
        return trim($this->flight_number).' '.$this->from_airport.' → '.$this->to_airport;
    }
}
