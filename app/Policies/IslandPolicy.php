<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/** No delete verb: an island that closes is deactivated, and addresses keep pointing at it. */
class IslandPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'island';
    }
}
