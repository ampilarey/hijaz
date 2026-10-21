<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/** No delete verb: a category with history is deactivated, not removed. */
class LedgerCategoryPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'ledgerCategory';
    }
}
