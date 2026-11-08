<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Organizations\InvitationService;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class InvitationController extends Controller
{
    public function store(Request $request, InvitationService $invitations): RedirectResponse
    {
        Gate::authorize(Permission::MembersManage->value);
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)],
        ]);
        $role = Role::from($validated['role']);

        if ($role === Role::Owner) {
            Gate::authorize(Permission::MembersManageOwners->value);
        }

        $invitations->invite($validated['email'], $role, $actor);
        $this->toast("Invitation sent to {$validated['email']}.");

        return back();
    }

    /** Route-model binding is tenant-scoped: another org's invitation is a 404. */
    public function destroy(Invitation $invitation, InvitationService $invitations): RedirectResponse
    {
        Gate::authorize(Permission::MembersManage->value);

        $invitations->revoke($invitation);
        $this->toast('Invitation revoked.');

        return back();
    }
}
