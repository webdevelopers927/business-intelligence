<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\DataSource;
use App\Models\User;

/**
 * Tenant ownership is already enforced by the model scope (cross-tenant
 * lookups 404 before reaching a policy); this decides what a member may do.
 */
class DataSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::SourcesView->value);
    }

    public function view(User $user, DataSource $source): bool
    {
        return $user->can(Permission::SourcesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::SourcesManage->value);
    }

    public function update(User $user, DataSource $source): bool
    {
        return $user->can(Permission::SourcesManage->value);
    }

    public function delete(User $user, DataSource $source): bool
    {
        return $user->can(Permission::SourcesManage->value);
    }
}
