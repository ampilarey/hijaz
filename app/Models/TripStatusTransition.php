<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $reason
 */
class TripStatusTransition extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['trip_id', 'from_status', 'to_status', 'user_id', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
