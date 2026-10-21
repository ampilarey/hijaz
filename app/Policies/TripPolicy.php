<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/** No delete verb: a trip that will not run is cancelled, and its history stays. */
class TripPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'trip';
    }

    public function transition(User $user): bool
    {
        return $user->can('trip.transition');
    }
}
