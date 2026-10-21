<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/** No delete verb: a customer with history stays; one created by mistake is merged. */
class CustomerPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'customer';
    }
}
