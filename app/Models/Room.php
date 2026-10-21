<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One room in one hotel stay. It knows its beds and who is in it; whether
 * that is allowed is the rooming checker's question.
 *
 * @property int $trip_hotel_id
 * @property string $label
 * @property int $capacity
 * @property string|null $gender
 */
class Room extends Model
{
    public const MALE = 'male';

    public const FEMALE = 'female';

    /** Mixed on purpose: a couple and their children. */
    public const FAMILY = 'family';

    /** @var array<string, string> */
    public const GENDERS = [self::MALE => 'Men', self::FEMALE => 'Women', self::FAMILY => 'Family'];

    /** @var list<string> */
    protected $fillable = ['trip_hotel_id', 'label', 'capacity', 'gender', 'notes', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    /** @return BelongsTo<TripHotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(TripHotel::class, 'trip_hotel_id');
    }

    /** @return HasMany<RoomAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class);
    }

    /** @return Collection<int, BookingPilgrim> */
    public function occupants(): Collection
    {
        return $this->assignments->map(fn (RoomAssignment $a): ?BookingPilgrim => $a->bookingPilgrim)->filter()->values();
    }

    public function bedsFree(): int
    {
        return $this->capacity - $this->assignments->count();
    }

    public function isFull(): bool
    {
        return $this->bedsFree() <= 0;
    }

    public function isOverfull(): bool
    {
        return $this->bedsFree() < 0;
    }

    public function genderLabel(): string
    {
        return self::GENDERS[$this->gender] ?? 'Not set';
    }

    /** "1204 · 4 beds · family" */
    public function describe(): string
    {
        return $this->label.' · '.$this->capacity.' bed'.($this->capacity === 1 ? '' : 's').' · '.strtolower($this->genderLabel());
    }
}
