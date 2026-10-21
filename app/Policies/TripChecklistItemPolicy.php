<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class TripChecklistItemPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'checklist';
    }

    public function tick(User $user): bool
    {
        return $user->can('checklist.tick');
    }
}
