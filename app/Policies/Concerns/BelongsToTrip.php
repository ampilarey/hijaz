<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Flights, hotels, stops, prices and excursions are part of the trip: editing them is editing the trip. */
trait BelongsToTrip
{
    public function viewAny(User $user): bool
    {
        return $user->can('trip.view');
    }

    public function view(User $user, ?Model $record = null): bool
    {
        return $user->can('trip.view');
    }

    public function create(User $user): bool
    {
        return $user->can('trip.update');
    }

    public function update(User $user, ?Model $record = null): bool
    {
        return $user->can('trip.update');
    }

    public function delete(User $user, ?Model $record = null): bool
    {
        return $user->can('trip.update');
    }
}
