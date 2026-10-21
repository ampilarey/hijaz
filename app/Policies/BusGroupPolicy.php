<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class BusGroupPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'bus';
    }

    public function assign(User $user): bool
    {
        return $user->can('bus.assign');
    }
}
