<?php

namespace App\Policies;

use App\Models\User;

/** Read only, and only for Admin: a trail nobody can edit or delete from a screen. */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.viewAny');
    }

    public function view(User $user): bool
    {
        return $user->can('audit.viewAny');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user): bool
    {
        return false;
    }

    public function delete(User $user): bool
    {
        return false;
    }
}
