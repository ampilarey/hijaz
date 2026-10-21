<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * A customer card or luggage tag issued to a traveller. The QR on it holds
 * a random token, never an ID number, so a lost tag gives nothing away;
 * scanning it in the portal opens the traveller.
 *
 * @property int $booking_pilgrim_id
 * @property string $kind
 * @property string $serial
 * @property string $qr_token
 * @property Carbon $issued_at
 * @property int $printed_count
 * @property Carbon|null $revoked_at
 */
class Card extends Model
{
    public const CUSTOMER = 'customer';

    public const LUGGAGE = 'luggage';

    /** @var array<string, string> */
    public const KINDS = [self::CUSTOMER => 'Customer card', self::LUGGAGE => 'Luggage tag'];

    /** @var list<string> */
    protected $fillable = ['booking_pilgrim_id', 'kind'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'revoked_at' => 'datetime', 'printed_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Card $card): void {
            $card->issued_by ??= Auth::id();
            $card->issued_at ??= now();
            $card->qr_token = $card->qr_token ?: Str::random(40);
            $card->serial = $card->serial ?: 'PENDING-'.Str::random(6);
        });

        static::created(function (Card $card): void {
            if (str_starts_with($card->serial, 'PENDING-')) {
                $card->forceFill(['serial' => sprintf('HJZ-%s-%06d', $card->kind === self::LUGGAGE ? 'L' : 'C', $card->getKey())])->saveQuietly();
            }
        });
    }

    /** @return BelongsTo<BookingPilgrim, $this> */
    public function bookingPilgrim(): BelongsTo
    {
        return $this->belongsTo(BookingPilgrim::class);
    }

    /** @param  Builder<Card>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** What the QR holds: the scan page with this card's token. */
    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/portal/scan?t='.$this->qr_token;
    }
}
