<?php

namespace App\Policies;

use App\Models\Trip;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Database\Eloquent\Model;

/**
 * A trip is deleted only while nothing hangs on it: no booking has ever been
 * made. Once one has, the trip is cancelled instead and its history stays.
 */
class TripPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'trip';
    }

    public function delete(User $user, ?Model $record = null): bool
    {
        return $user->can('trip.delete') && ! ($record instanceof Trip && $record->hasBookings());
    }

    public function transition(User $user): bool
    {
        return $user->can('trip.transition');
    }
}
