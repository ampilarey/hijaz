<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * One person at one count. The time is the phone's, so a mark made with no
 * signal keeps the moment it was made.
 *
 * @property int $roll_call_id
 * @property int $booking_pilgrim_id
 * @property string $state
 * @property Carbon $marked_at
 */
class RollCallMark extends Model
{
    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    public const EXCUSED = 'excused';

    /** @var array<string, string> */
    public const STATES = [self::PRESENT => 'Here', self::ABSENT => 'Not here', self::EXCUSED => 'Excused'];

    /** @var list<string> */
    protected $fillable = ['roll_call_id', 'booking_pilgrim_id', 'state', 'note', 'marked_by', 'marked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['marked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (RollCallMark $m): void {
            $m->marked_by ??= Auth::id();
            $m->marked_at ??= now();
        });
    }

    /** @return BelongsTo<RollCall, $this> */
    public function rollCall(): BelongsTo
    {
        return $this->belongsTo(RollCall::class);
    }

    /** @return BelongsTo<BookingPilgrim, $this> */
    public function bookingPilgrim(): BelongsTo
    {
        return $this->belongsTo(BookingPilgrim::class);
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state] ?? $this->state;
    }
}
