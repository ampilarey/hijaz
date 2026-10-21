<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $trip_id
 * @property string $type
 * @property int $file_count
 * @property int $matched_count
 * @property string $status
 */
class DocumentBatch extends Model
{
    public const REVIEW = 'review';

    public const CONFIRMED = 'confirmed';

    public const DISCARDED = 'discarded';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return HasMany<DocumentBatchItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DocumentBatchItem::class)->orderBy('id');
    }
}
