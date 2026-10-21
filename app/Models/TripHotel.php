<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $trip_id
 * @property string $city
 * @property string $name
 * @property string|null $address
 * @property int|null $distance_to_haram_m
 * @property Carbon $check_in_on
 * @property Carbon $check_out_on
 * @property string|null $booking_reference
 * @property string|null $contact_name
 * @property string|null $contact_phone
 * @property list<string>|null $room_types
 */
class TripHotel extends Model
{
    use HasFactory;

    public const MAKKAH = 'makkah';

    public const MADINAH = 'madinah';

    public const JEDDAH = 'jeddah';

    /** @var array<string, string> */
    public const CITIES = [
        self::MAKKAH => 'Makkah',
        self::MADINAH => 'Madinah',
        self::JEDDAH => 'Jeddah',
    ];

    /** @var list<string> */
    protected $fillable = ['trip_id', 'city', 'name', 'address', 'distance_to_haram_m', 'check_in_on', 'check_out_on', 'booking_reference', 'contact_name', 'contact_phone', 'room_types', 'notes', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['check_in_on' => 'date', 'check_out_on' => 'date', 'room_types' => 'array', 'distance_to_haram_m' => 'integer'];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('sort_order')->orderBy('label');
    }

    public function nights(): int
    {
        return max(0, (int) $this->check_in_on->diffInDays($this->check_out_on));
    }

    public function cityLabel(): string
    {
        return self::CITIES[$this->city] ?? ucfirst($this->city);
    }
}
