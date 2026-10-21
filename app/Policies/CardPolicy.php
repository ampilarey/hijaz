<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/** No update or delete verb: a card is issued, printed, and if lost revoked by issuing another. */
class CardPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'card';
    }

    public function scan(User $user): bool
    {
        return $user->can('card.scan');
    }
}
