<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class RoomPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'room';
    }

    public function assign(User $user): bool
    {
        return $user->can('room.assign');
    }
}
