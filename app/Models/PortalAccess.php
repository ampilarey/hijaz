<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's way into their own booking: a long random token in a link,
 * stored hashed, good for a while, revocable. No password, no account.
 *
 * @property int $booking_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $first_used_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 */
class PortalAccess extends Model
{
    /** @var list<string> */
    protected $fillable = ['booking_id', 'token_hash', 'expires_at', 'issued_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'first_used_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function whyNot(): ?string
    {
        return match (true) {
            $this->revoked_at !== null => 'That link was cancelled by the office. Ask for a new one.',
            $this->expires_at->isPast() => 'That link has expired. Ask the office for a new one.',
            default => null,
        };
    }

    /** @param  Builder<PortalAccess>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
