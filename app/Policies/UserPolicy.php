<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Illuminate\Database\Eloquent\Model;

class UserPolicy
{
    use ChecksPermissions {
        delete as deleteByPermission;
    }

    protected function resource(): string
    {
        return 'user';
    }

    /** Nobody deletes themselves from a screen, and the last Admin cannot go at all. */
    public function delete(User $user, ?Model $record = null): bool
    {
        if ($record instanceof User && ($record->is($user) || $record->isTheLastAdmin())) {
            return false;
        }

        return $this->deleteByPermission($user, $record);
    }
}
