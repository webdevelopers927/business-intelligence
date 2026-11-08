<?php

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Dashboard;
use App\Models\User;

/**
 * Who sees and changes a dashboard (tenancy is enforced before this runs):
 *  - view:   its owner; anyone if visibility is "organization"; members it is
 *            shared with; organization owners/admins (governance).
 *  - update: its owner; members with an edit share who can build dashboards;
 *            organization owners/admins.
 *  - delete / share: its owner or organization owners/admins.
 */
class DashboardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::DashboardsView->value);
    }

    public function view(User $user, Dashboard $dashboard): bool
    {
        if (! $user->can(Permission::DashboardsView->value)) {
            return false;
        }

        return $this->manages($user, $dashboard)
            || $dashboard->visibility === 'organization'
            || ($dashboard->visibility === 'shared' && $dashboard->shareFor($user) !== null);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::DashboardsCreate->value);
    }

    public function update(User $user, Dashboard $dashboard): bool
    {
        return $this->manages($user, $dashboard)
            || ($dashboard->visibility === 'shared'
                && $dashboard->shareFor($user)?->permission === 'edit'
                && $user->can(Permission::DashboardsCreate->value));
    }

    public function delete(User $user, Dashboard $dashboard): bool
    {
        return $this->manages($user, $dashboard);
    }

    public function share(User $user, Dashboard $dashboard): bool
    {
        return $this->manages($user, $dashboard);
    }

    public function duplicate(User $user, Dashboard $dashboard): bool
    {
        return $this->view($user, $dashboard) && $this->create($user);
    }

    private function manages(User $user, Dashboard $dashboard): bool
    {
        if ($dashboard->owner_id === $user->id) {
            return true;
        }

        $role = app(TenantContext::class)->role();

        return $role === Role::Owner || $role === Role::Admin;
    }
}
