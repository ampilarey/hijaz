<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/** No delete verb: an account that closes is deactivated; its entries stay. */
class AccountPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'account';
    }
}
