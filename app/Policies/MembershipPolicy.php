<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Membership;
use App\Models\User;

/**
 * Object-level rules for managing another member. The organization-level
 * permission is checked first; then:
 *  - nobody edits their own membership from the members screen;
 *  - only owners can modify owners or grant the owner role.
 */
class MembershipPolicy
{
    public function update(User $actor, Membership $target, ?Role $newRole = null): bool
    {
        if (! $actor->can(Permission::MembersManage->value) || $target->user_id === $actor->id) {
            return false;
        }

        $touchesOwner = $target->role === Role::Owner || $newRole === Role::Owner;

        return ! $touchesOwner || $actor->can(Permission::MembersManageOwners->value);
    }

    public function delete(User $actor, Membership $target): bool
    {
        return $this->update($actor, $target);
    }
}
