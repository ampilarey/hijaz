<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/** No delete verb: places nobody takes are cancelled, not erased. */
class ExcursionBookingPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'excursionBooking';
    }
}
