<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/** No update and no delete verb: an entry is voided with a reason and re-entered. */
class LedgerEntryPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'ledger';
    }

    public function void(User $user): bool
    {
        return $user->can('ledger.void');
    }
}
