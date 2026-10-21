<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps the five resource actions onto `<resource>.<action>` permissions.
 *
 * Each policy names its resource and inherits the rest, so adding a model
 * cannot ship a policy that forgets one method and quietly denies, or
 * worse allows, that action. A permission that is not in Access::PERMISSIONS
 * does not exist, so a policy naming a resource with no `delete` verb denies
 * deletion by construction.
 */
trait ChecksPermissions
{
    abstract protected function resource(): string;

    public function viewAny(User $user): bool
    {
        return $user->can($this->resource().'.viewAny');
    }

    public function view(User $user, ?Model $record = null): bool
    {
        return $user->can($this->resource().'.view');
    }

    public function create(User $user): bool
    {
        return $user->can($this->resource().'.create');
    }

    public function update(User $user, ?Model $record = null): bool
    {
        return $user->can($this->resource().'.update');
    }

    public function delete(User $user, ?Model $record = null): bool
    {
        return $user->can($this->resource().'.delete');
    }
}
