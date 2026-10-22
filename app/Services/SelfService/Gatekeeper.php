<?php

namespace App\Services\SelfService;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\PortalAccess;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The door to the customer portal. The office's link lets a customer in
 * once (the token is stored hashed; the link is the only copy); from inside
 * they set a password and sign in after that with their mobile number.
 */
final class Gatekeeper
{
    public const SESSION_KEY = 'my.booking_id';

    public const CUSTOMER_KEY = 'my.customer_id';

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

    /**
     * The booking on screen: for a customer signed in with their password,
     * the one they chose (or their latest); for a visitor from a link, that
     * link's booking while the link is good.
     */
    public function current(): ?Booking
    {
        if (($customer = $this->signedInCustomer()) !== null) {
            $chosen = session()->get(self::SESSION_KEY);
            $bookings = $this->bookingsOf($customer);

            return $bookings->firstWhere('id', $chosen) ?? $bookings->first();
        }

        $accessId = session()->get(self::SESSION_KEY.'.access');
        $access = $accessId ? PortalAccess::query()->with('booking')->find($accessId) : null;

        if ($access === null || ! $access->isLive()) {
            session()->forget([self::SESSION_KEY, self::SESSION_KEY.'.access']);

            return null;
        }

        $access->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $access->booking;
    }

    /** Whoever is on the portal, by password or by link. */
    public function customer(): ?Customer
    {
        return $this->signedInCustomer() ?? $this->current()?->customer;
    }

    public function signedInCustomer(): ?Customer
    {
        $id = session()->get(self::CUSTOMER_KEY);

        return $id ? Customer::query()->find($id) : null;
    }

    /** @return Collection<int, Booking> newest first; cancelled bookings are left out. */
    public function bookingsOf(Customer $customer): Collection
    {
        return $customer->bookings()->whereIn('status', [Booking::DRAFT, Booking::CONFIRMED, Booking::COMPLETED])->with('trip')->orderByDesc('id')->get();
    }

    /** Mobile number and password. Null when either is wrong; the message never says which. */
    public function attempt(string $phone, string $password): ?Customer
    {
        $number = Phone::normalise($phone);

        if ($number === null) {
            return null;
        }

        return Customer::query()->whereNotNull('portal_password')->get()
            // The family's phone or its contact person's own mobile, either one signs in.
            ->first(fn (Customer $c): bool => in_array($number, [Phone::normalise((string) $c->phone), Phone::normalise((string) $c->contactPhone())], true) && Hash::check($password, (string) $c->portal_password));
    }

    public function signIn(Customer $customer): void
    {
        session()->regenerate();
        session()->forget([self::SESSION_KEY, self::SESSION_KEY.'.access']);
        session()->put(self::CUSTOMER_KEY, $customer->getKey());
        $customer->forceFill(['portal_last_login_at' => now()])->saveQuietly();
    }

    /** Which booking to show, when the customer has more than one. Only their own. */
    public function choose(Booking $booking): bool
    {
        $customer = $this->signedInCustomer();

        if ($customer === null || $booking->customer_id !== $customer->getKey()) {
            return false;
        }

        session()->put(self::SESSION_KEY, $booking->getKey());

        return true;
    }

    /** Set from inside the portal; from then on the customer signs in with their mobile number. */
    public function setPassword(Customer $customer, string $password): void
    {
        $customer->forceFill(['portal_password' => Hash::make($password), 'portal_password_set_at' => now()])->save();
        $this->signIn($customer);
    }

    public function signOut(): void
    {
        session()->forget([self::SESSION_KEY, self::SESSION_KEY.'.access', self::CUSTOMER_KEY]);
        session()->invalidate();
    }

    public function revokeAll(Booking $booking): int
    {
        return $booking->accesses()->live()->get()->each(fn (PortalAccess $a) => $a->forceFill(['revoked_at' => now()])->save())->count();
    }
}
