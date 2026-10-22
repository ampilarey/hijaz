<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A booking's agreement with the customer. The wording is copied in when the
 * agreement is first shown, so what was signed never changes afterwards.
 * Signed either on screen (a drawn signature, the typed name, the time and
 * the device) or by a scan of a paper copy kept on the booking.
 *
 * @property int $id
 * @property int $booking_id
 * @property string $terms
 * @property string $terms_hash
 * @property string|null $method
 * @property string|null $signed_name
 * @property string|null $signature
 * @property Carbon|null $signed_at
 */
class Agreement extends Model
{
    public const DIGITAL = 'digital';

    public const SCAN = 'scan';

    /** @var list<string> */
    protected $fillable = ['booking_id', 'terms', 'terms_hash'];

    /** @var list<string> */
    protected $hidden = ['signature'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['signature' => 'encrypted', 'signed_at' => 'datetime'];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** The booking's agreement, made with today's wording if it has none yet. */
    public static function for(Booking $booking): self
    {
        $terms = self::wording($booking);

        return self::query()->firstOrCreate(['booking_id' => $booking->getKey()], ['terms' => $terms, 'terms_hash' => hash('sha256', $terms)]);
    }

    /** The wording from Settings, with the booking's details filled in. */
    public static function wording(Booking $booking): string
    {
        $booking->loadMissing(['customer', 'trip']);

        return strtr((string) Settings::get(Settings::AGREEMENT_TERMS), [
            '{company}' => (string) Settings::get(Settings::COMPANY_NAME),
            '{customer}' => $booking->customer->name,
            '{booking}' => $booking->reference,
            '{trip}' => $booking->trip->name,
        ]);
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    /** Unsigned agreements take today's wording, so an edit in Settings reaches them. */
    public function refreshWording(): void
    {
        if ($this->isSigned()) {
            return;
        }

        $terms = self::wording($this->booking);

        if ($terms !== $this->terms) {
            $this->forceFill(['terms' => $terms, 'terms_hash' => hash('sha256', $terms)])->save();
        }
    }

    public function statusLabel(): string
    {
        return match (true) {
            ! $this->isSigned() => 'Not signed yet',
            $this->method === self::SCAN => 'Signed copy uploaded '.$this->signed_at->format('j M Y'),
            default => 'Signed on screen by '.$this->signed_name.' on '.$this->signed_at->format('j M Y, H:i'),
        };
    }
}
