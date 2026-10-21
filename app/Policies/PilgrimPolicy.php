<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class PilgrimPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'pilgrim';
    }

    public function verify(User $user): bool
    {
        return $user->can('pilgrim.verify');
    }
}
