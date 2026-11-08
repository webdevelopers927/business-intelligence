<?php

namespace App\Domain\Organizations;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvitationService
{
    public const EXPIRES_AFTER_DAYS = 7;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Invite an email address into the current organization. Re-inviting a
     * pending address replaces the previous invitation (new token, new expiry).
     */
    public function invite(string $email, Role $role, User $inviter): Invitation
    {
        $organization = $this->tenant->organization();
        $email = Str::lower(trim($email));

        $alreadyMember = $organization->members()->where('email', $email)->exists();
        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => 'This person is already a member of the organization.']);
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($email, $role, $inviter, $token): Invitation {
            Invitation::query()->where('email', $email)->whereNull('accepted_at')->delete();

            return Invitation::query()->create([
                'email' => $email,
                'role' => $role,
                'token_hash' => Invitation::hashToken($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
            ]);
        });

        $notification = new OrganizationInvitation($organization, $inviter, $role, $token, $organization->id);
        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser !== null) {
            $existingUser->notify($notification);
        } else {
            Notification::route('mail', $email)->notify($notification);
        }

        $this->audit->record('member.invited', $invitation, ['email' => $email, 'role' => $role->value]);

        return $invitation;
    }

    /**
     * Look up a pending invitation by its plain token. Runs without a tenant
     * context (the invitee is not a member yet), hence the explicit bypass.
     */
    public function findPending(string $token): ?Invitation
    {
        return $this->tenant->bypass(fn () => Invitation::query()
            ->with('organization')
            ->where('token_hash', Invitation::hashToken($token))
            ->pending()
            ->first());
    }

    public function accept(string $token, User $user): Organization
    {
        $invitation = $this->findPending($token);

        if ($invitation === null) {
            throw ValidationException::withMessages(['invitation' => 'This invitation is invalid or has expired.']);
        }

        if (Str::lower($user->email) !== $invitation->email) {
            throw ValidationException::withMessages([
                'invitation' => "This invitation was sent to {$invitation->email}. Sign in with that address to accept it.",
            ]);
        }

        /** @var Organization $organization */
        $organization = $invitation->organization;

        return DB::transaction(function () use ($invitation, $organization, $user): Organization {
            $this->tenant->bypass(fn () => $invitation->forceFill(['accepted_at' => now()])->save());

            Membership::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $user->id],
                ['role' => $invitation->role, 'joined_at' => now()],
            );

            $user->forceFill(['current_organization_id' => $organization->id])->save();

            $this->audit->record('member.joined', $invitation, ['role' => $invitation->role->value], $organization->id, $user->id);

            return $organization;
        });
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->delete();
        $this->audit->record('member.invitation_revoked', $invitation, ['email' => $invitation->email]);
    }
}
