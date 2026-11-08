<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Organizations\MembershipService;
use App\Domain\Tenancy\TenantContext;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class MemberController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::MembersManage->value);
        /** @var User $actor */
        $actor = $request->user();

        $members = Membership::query()
            ->where('organization_id', $this->tenant->id())
            ->with('user:id,name,email')
            ->orderByRaw("case role when 'owner' then 0 when 'admin' then 1 when 'analyst' then 2 else 3 end")
            ->orderBy('joined_at')
            ->get()
            ->map(fn (Membership $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user->name,
                'email' => $m->user->email,
                'role' => $m->role->value,
                'joined_at' => $m->joined_at?->toIso8601String(),
                'is_self' => $m->user_id === $actor->id,
                'can_manage' => $actor->can('update', $m),
            ]);

        $invitations = Invitation::query()
            ->pending()
            ->with('inviter:id,name')
            ->latest()
            ->get()
            ->map(fn (Invitation $i) => [
                'id' => $i->id,
                'email' => $i->email,
                'role' => $i->role->value,
                'invited_by' => $i->inviter?->name,
                'expires_at' => $i->expires_at->toIso8601String(),
            ]);

        $assignable = array_values(array_filter(
            Role::options(),
            fn (array $r) => $r['value'] !== Role::Owner->value || $actor->can(Permission::MembersManageOwners->value),
        ));

        return Inertia::render('settings/Members', [
            'members' => $members,
            'invitations' => $invitations,
            'roles' => Role::options(),
            'assignableRoles' => $assignable,
        ]);
    }

    public function update(Request $request, int $membership, MembershipService $memberships): RedirectResponse
    {
        $target = $this->find($membership);
        $validated = $request->validate(['role' => ['required', Rule::enum(Role::class)]]);
        $role = Role::from($validated['role']);

        Gate::authorize('update', [$target, $role]);

        $memberships->changeRole($this->tenant->organization(), $target, $role);
        $this->toast("Role updated to {$role->label()}.");

        return back();
    }

    public function destroy(int $membership, MembershipService $memberships): RedirectResponse
    {
        $target = $this->find($membership);
        Gate::authorize('delete', $target);

        $memberships->remove($this->tenant->organization(), $target);
        $this->toast('Member removed.');

        return back();
    }

    /** Membership is the tenancy anchor itself, so it is scoped explicitly. */
    private function find(int $id): Membership
    {
        return Membership::query()
            ->where('organization_id', $this->tenant->id())
            ->whereKey($id)
            ->firstOrFail();
    }
}
