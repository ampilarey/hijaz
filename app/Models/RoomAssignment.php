<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One traveller in one room. */
class RoomAssignment extends Model
{
    /** @var list<string> */
    protected $fillable = ['room_id', 'booking_pilgrim_id', 'assigned_by'];

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<BookingPilgrim, $this> */
    public function bookingPilgrim(): BelongsTo
    {
        return $this->belongsTo(BookingPilgrim::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
