<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The people of a trip who travel on one flight out and one flight home.
 * Each group has its own buses.
 *
 * @property int $id
 * @property int $trip_id
 * @property string $name
 * @property int|null $outbound_flight_id
 * @property int|null $return_flight_id
 */
class TripGroup extends Model
{
    /** @var list<string> */
    protected $fillable = ['trip_id', 'name', 'outbound_flight_id', 'return_flight_id', 'notes', 'sort_order'];

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

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

    /** @return HasMany<BookingPilgrim, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(BookingPilgrim::class);
    }

    /** @return HasMany<BusGroup, $this> */
    public function buses(): HasMany
    {
        return $this->hasMany(BusGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    /** "SV 789 30 Nov / SV 788 14 Dec", or what is known. */
    public function flightsLabel(): string
    {
        $part = fn (?TripFlight $f): string => $f ? trim($f->flight_number).' '.$f->departs_at->format('j M') : 'no flight yet';

        return $part($this->outboundFlight).' / '.$part($this->returnFlight);
    }
}
