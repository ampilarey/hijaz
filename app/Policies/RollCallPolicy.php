<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/** No update or delete verb: a count is taken and kept; a wrong mark is marked again. */
class RollCallPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'rollCall';
    }

    public function mark(User $user): bool
    {
        return $user->can('rollCall.mark');
    }
}
