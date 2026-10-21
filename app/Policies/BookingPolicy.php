<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/** No delete verb: a booking nobody wants is cancelled and keeps its trail. */
class BookingPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'booking';
    }

    public function confirm(User $user): bool
    {
        return $user->can('booking.confirm');
    }

    public function cancel(User $user): bool
    {
        return $user->can('booking.cancel');
    }

    public function discount(User $user): bool
    {
        return $user->can('booking.discount');
    }
}
