<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $trip_id
 * @property string $title
 * @property Carbon|null $due_on
 * @property bool $is_blocking
 * @property Carbon|null $done_at
 * @property int|null $done_by
 */
class TripChecklistItem extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['trip_id', 'title', 'due_on', 'is_blocking', 'notes', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_on' => 'date', 'is_blocking' => 'boolean', 'done_at' => 'datetime', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<User, $this> */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isDone() && $this->due_on !== null && $this->due_on->lt(today());
    }

    public function markDone(User $by): void
    {
        $this->forceFill(['done_at' => now(), 'done_by' => $by->getKey()])->save();
    }

    public function markNotDone(): void
    {
        $this->forceFill(['done_at' => null, 'done_by' => null])->save();
    }

    /** @param  Builder<TripChecklistItem>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('done_at');
    }
}
