<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stop on the route, in order: Jeddah, Makkah, Madinah.
 *
 * @property int $trip_id
 * @property int $sort_order
 * @property string $city
 * @property Carbon|null $arrives_on
 * @property Carbon|null $departs_on
 * @property int|null $trip_hotel_id
 * @property string|null $transport
 */
class TripStop extends Model
{
    /** @var array<string, string> */
    public const CITIES = [
        'jeddah' => 'Jeddah',
        'makkah' => 'Makkah',
        'madinah' => 'Madinah',
        'taif' => 'Taif',
        'other' => 'Other',
    ];

    /** @var array<string, string> */
    public const TRANSPORT = [
        'flight' => 'Flight',
        'bus' => 'Bus',
        'train' => 'Haramain train',
        'car' => 'Car',
    ];

    /** @var list<string> */
    protected $fillable = ['trip_id', 'sort_order', 'city', 'arrives_on', 'departs_on', 'trip_hotel_id', 'transport', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['arrives_on' => 'date', 'departs_on' => 'date', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<TripHotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(TripHotel::class, 'trip_hotel_id');
    }

    public function cityLabel(): string
    {
        return self::CITIES[$this->city] ?? ucfirst($this->city);
    }
}
