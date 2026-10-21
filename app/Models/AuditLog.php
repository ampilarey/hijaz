<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An immutable record of one change. Written by AuditObserver (and a few
 * controllers for downloads), never edited: a trail that can be rewritten
 * proves nothing.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $user_name
 * @property string|null $user_email
 * @property string $event
 * @property string $channel
 * @property string $auditable_type
 * @property string $auditable_id The record's key: a number, or a setting's name.
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 */
class AuditLog extends Model
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    public const DOWNLOADED = 'downloaded';

    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param  Builder<AuditLog>  $query */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function getSubjectAttribute(): string
    {
        return class_basename($this->auditable_type);
    }

    /** "#12" for a numbered record, the key itself for a setting. */
    public function getRecordLabelAttribute(): string
    {
        $key = (string) $this->auditable_id;

        return ctype_digit($key) ? '#'.$key : $key;
    }

    /** Who did it, as recorded at the time; null means the system. */
    public function getActorAttribute(): string
    {
        return $this->user_name ?? 'System';
    }
}
