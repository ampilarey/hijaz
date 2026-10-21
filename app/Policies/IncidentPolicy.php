<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/** No update or delete verb: an incident is raised, then resolved with what was done. */
class IncidentPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'incident';
    }

    public function resolve(User $user): bool
    {
        return $user->can('incident.resolve');
    }
}
