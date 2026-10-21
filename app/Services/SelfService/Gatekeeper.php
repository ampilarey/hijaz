<?php

namespace App\Services\SelfService;

use App\Models\Booking;
use App\Models\PortalAccess;
use App\Models\User;
use Illuminate\Support\Str;

/** Issues, finds and spends the links customers use to see their own booking. The token is stored hashed; the link is the only copy. */
final class Gatekeeper
{
    public const SESSION_KEY = 'my.booking_id';

    /** @return array{access: PortalAccess, url: string} */
    public function issue(Booking $booking, ?User $by = null): array
    {
        $token = Str::random(48);

        $access = $booking->accesses()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('booking.self_service_days', 30)),
            'issued_by' => $by?->getKey(),
        ]);

        return ['access' => $access, 'url' => route('my.enter', ['token' => $token])];
    }

    public function find(string $token): ?PortalAccess
    {
        if (! preg_match('/^[A-Za-z0-9]{40,64}$/', $token)) {
            return null;
        }

        return PortalAccess::query()->where('token_hash', hash('sha256', $token))->with('booking')->first();
    }

    public function admit(PortalAccess $access): void
    {
        $access->forceFill(['first_used_at' => $access->first_used_at ?? now(), 'last_seen_at' => now()])->save();
        session()->regenerate();
        session()->put(self::SESSION_KEY, $access->booking_id);
        session()->put(self::SESSION_KEY.'.access', $access->getKey());
    }

    /** The booking of the current visitor, if their link is still good. */
    public function current(): ?Booking
    {
        $accessId = session()->get(self::SESSION_KEY.'.access');
        $access = $accessId ? PortalAccess::query()->with('booking')->find($accessId) : null;

        if ($access === null || ! $access->isLive()) {
            session()->forget([self::SESSION_KEY, self::SESSION_KEY.'.access']);

            return null;
        }

        $access->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $access->booking;
    }

    public function revokeAll(Booking $booking): int
    {
        return $booking->accesses()->live()->get()->each(fn (PortalAccess $a) => $a->forceFill(['revoked_at' => now()])->save())->count();
    }
}
