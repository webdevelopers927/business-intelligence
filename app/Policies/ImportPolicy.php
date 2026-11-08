<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Import;
use App\Models\User;

class ImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::SourcesView->value);
    }

    public function view(User $user, Import $import): bool
    {
        return $user->can(Permission::SourcesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ImportsCreate->value);
    }

    /** Mapping a draft: its uploader, or anyone who manages sources. */
    public function update(User $user, Import $import): bool
    {
        return $user->can(Permission::ImportsCreate->value)
            && ($import->created_by === $user->id || $user->can(Permission::SourcesManage->value));
    }
}
