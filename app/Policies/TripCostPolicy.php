<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/** No delete verb: a cost entered and thought better of is corrected, not removed. */
class TripCostPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'cost';
    }
}
