<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One coach on one trip, with its leader. Members are booking pilgrims.
 *
 * @property int $trip_id
 * @property string $name
 * @property int|null $capacity
 * @property int|null $leader_user_id
 * @property string|null $leader_name
 * @property string|null $leader_phone
 * @property string|null $colour
 */
class BusGroup extends Model
{
    /** @var list<string> */
    protected $fillable = ['trip_id', 'trip_group_id', 'name', 'capacity', 'leader_user_id', 'leader_name', 'leader_phone', 'colour', 'sort_order', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    /** @return BelongsTo<Trip, $this> */
    /** @return BelongsTo<TripGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TripGroup::class, 'trip_group_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<User, $this> */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    /** @return HasMany<BookingPilgrim, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(BookingPilgrim::class);
    }

    public function leaderLabel(): ?string
    {
        return $this->leader_name ?: $this->leader?->name;
    }

    public function seatsFree(): ?int
    {
        return $this->capacity === null ? null : $this->capacity - $this->members()->count();
    }

    public function isOverfull(): bool
    {
        return $this->capacity !== null && $this->members()->count() > $this->capacity;
    }
}
