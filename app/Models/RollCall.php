<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * One head count at one moment where somebody could be left behind: a
 * coach door, a hotel lobby, the airport. For one bus or the whole trip.
 *
 * @property int $trip_id
 * @property int|null $bus_group_id
 * @property string $moment
 * @property Carbon $taken_at
 * @property Carbon|null $closed_at
 */
class RollCall extends Model
{
    /** @var list<string> */
    protected $fillable = ['trip_id', 'bus_group_id', 'moment', 'taken_by', 'taken_at', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (RollCall $r): void {
            $r->taken_by ??= Auth::id();
            $r->taken_at ??= now();
        });
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<BusGroup, $this> */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(BusGroup::class, 'bus_group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    /** @return HasMany<RollCallMark, $this> */
    public function marks(): HasMany
    {
        return $this->hasMany(RollCallMark::class);
    }

    /** Everyone who should be here: the bus's members, or every confirmed traveller. @return Collection<int, BookingPilgrim> */
    public function expected(): Collection
    {
        $query = BookingPilgrim::query()
            ->whereIn('booking_id', Booking::query()->where('trip_id', $this->trip_id)->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])->select('id'))
            ->with(['pilgrim', 'booking.customer']);

        if ($this->bus_group_id !== null) {
            $query->where('bus_group_id', $this->bus_group_id);
        }

        return $query->get()->sortBy(fn (BookingPilgrim $bp) => $bp->booking->customer->name.' '.$bp->pilgrim->name_en)->values();
    }

    /** @return Collection<int, BookingPilgrim> */
    public function unmarked(): Collection
    {
        $marked = $this->marks->pluck('booking_pilgrim_id');

        return $this->expected()->reject(fn (BookingPilgrim $bp): bool => $marked->contains($bp->getKey()))->values();
    }

    /** Not marked, or marked absent: the people to go and find. @return Collection<int, BookingPilgrim> */
    public function unaccountedFor(): Collection
    {
        $absent = $this->marks->where('state', RollCallMark::ABSENT)->map(fn (RollCallMark $m) => $m->bookingPilgrim)->filter();

        return $this->unmarked()->concat($absent)->unique('id')->values();
    }

    public function countIn(string $state): int
    {
        return $this->marks->where('state', $state)->count();
    }

    public function isSettled(): bool
    {
        return $this->unaccountedFor()->isEmpty();
    }
}
