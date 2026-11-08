<?php

namespace App\Domain\Organizations;

use App\Domain\Audit\AuditLogger;
use App\Enums\Role;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MembershipService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function changeRole(Organization $organization, Membership $membership, Role $newRole): Membership
    {
        return DB::transaction(function () use ($organization, $membership, $newRole): Membership {
            $membership = $this->lock($organization, $membership);
            $previous = $membership->role;

            if ($previous === $newRole) {
                return $membership;
            }

            if ($previous === Role::Owner) {
                $this->assertAnotherOwnerExists($organization, 'role');
            }

            $membership->update(['role' => $newRole]);

            $this->audit->record('member.role_changed', $membership, [
                'user_id' => $membership->user_id,
                'from' => $previous->value,
                'to' => $newRole->value,
            ], $organization->id);

            return $membership;
        });
    }

    public function remove(Organization $organization, Membership $membership): void
    {
        DB::transaction(function () use ($organization, $membership): void {
            $membership = $this->lock($organization, $membership);

            if ($membership->role === Role::Owner) {
                $this->assertAnotherOwnerExists($organization, 'member');
            }

            $membership->delete();

            $membership->user()->where('current_organization_id', $organization->id)
                ->update(['current_organization_id' => null]);

            $this->audit->record('member.removed', $membership, ['user_id' => $membership->user_id], $organization->id);
        });
    }

    private function lock(Organization $organization, Membership $membership): Membership
    {
        // Lock every owner row too, so two concurrent demotions cannot both
        // pass the "another owner exists" check.
        Membership::query()
            ->where('organization_id', $organization->id)
            ->where('role', Role::Owner->value)
            ->lockForUpdate()
            ->get();

        return Membership::query()
            ->where('organization_id', $organization->id)
            ->whereKey($membership->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertAnotherOwnerExists(Organization $organization, string $field): void
    {
        if ($organization->ownerCount() <= 1) {
            throw ValidationException::withMessages([
                $field => 'An organization must keep at least one owner. Promote another member to owner first.',
            ]);
        }
    }
}
