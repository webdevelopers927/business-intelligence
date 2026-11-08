<?php

use App\Domain\Tenancy\RestoreTenantContext;
use App\Domain\Tenancy\TenantAware;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantNotResolved;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Support\Str;

/** Seed one pending invitation inside the given organization. */
function invitationFor(Organization $org, string $email): Invitation
{
    return asTenant($org, fn () => Invitation::query()->create([
        'email' => $email,
        'role' => Role::Viewer,
        'token_hash' => Invitation::hashToken(Str::random(40)),
        'expires_at' => now()->addDay(),
    ]));
}

describe('model layer', function () {
    it('fails closed when tenant data is queried without a tenant context', function () {
        Invitation::query()->get();
    })->throws(TenantNotResolved::class);

    it('scopes queries to the current organization', function () {
        [, $acme] = memberOf();
        [, $globex] = memberOf();
        invitationFor($acme, 'a@acme.test');
        invitationFor($globex, 'g@globex.test');

        $seenByAcme = asTenant($acme, fn () => Invitation::query()->pluck('email')->all());
        $seenByGlobex = asTenant($globex, fn () => Invitation::query()->pluck('email')->all());

        expect($seenByAcme)->toBe(['a@acme.test'])
            ->and($seenByGlobex)->toBe(['g@globex.test']);
    });

    it('stamps organization_id on create and refuses a foreign one', function () {
        [, $acme] = memberOf();
        [, $globex] = memberOf();

        $invitation = invitationFor($acme, 'x@acme.test');
        expect($invitation->organization_id)->toBe($acme->id);

        asTenant($acme, fn () => (new Invitation)->forceFill([
            'organization_id' => $globex->id,
            'email' => 'sneaky@acme.test',
            'role' => Role::Viewer,
            'token_hash' => str_repeat('a', 64),
            'expires_at' => now()->addDay(),
        ])->save());
    })->throws(TenantNotResolved::class, 'different organization');

    it('restores the previous context after runAs, even on exceptions', function () {
        [, $acme] = memberOf();
        $context = app(TenantContext::class);

        try {
            $context->runAs($acme, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        expect($context->has())->toBeFalse();
    });

    it('restores tenant context for queued jobs via job middleware', function () {
        [, $acme] = memberOf();
        invitationFor($acme, 'job@acme.test');
        [, $globex] = memberOf();
        invitationFor($globex, 'other@globex.test');

        $job = new class($acme->id) implements TenantAware
        {
            public array $seen = [];

            public function __construct(private int $orgId) {}

            public function organizationId(): int
            {
                return $this->orgId;
            }
        };

        (new RestoreTenantContext)->handle($job, function ($job) {
            $job->seen = Invitation::query()->pluck('email')->all();
        });

        expect($job->seen)->toBe(['job@acme.test'])
            ->and(app(TenantContext::class)->has())->toBeFalse();
    });
});

describe('HTTP layer', function () {
    it('returns 404 when a user opens an organization they do not belong to', function () {
        [$intruder] = memberOf(Role::Owner);
        [, $victim] = memberOf(Role::Owner);

        $this->actingAs($intruder)
            ->get(route('dashboard', ['organization' => $victim->slug]))
            ->assertNotFound();

        $this->actingAs($intruder)
            ->get(route('settings.members.index', ['organization' => $victim->slug]))
            ->assertNotFound();
    });

    it('returns the same 404 for a non-existent organization (no slug probing)', function () {
        [$user] = memberOf();

        $this->actingAs($user)->get('/app/does-not-exist/dashboard')->assertNotFound();
    });

    it('cannot revoke another organization\'s invitation through its own URL', function () {
        [$attacker, $attackerOrg] = memberOf(Role::Owner);
        [, $victimOrg] = memberOf(Role::Owner);
        $victimInvite = invitationFor($victimOrg, 'target@victim.test');

        $this->actingAs($attacker)
            ->delete(route('settings.invitations.destroy', ['organization' => $attackerOrg->slug, 'invitation' => $victimInvite->id]))
            ->assertNotFound();

        expect(asTenant($victimOrg, fn () => Invitation::query()->whereKey($victimInvite->id)->exists()))->toBeTrue();
    });

    it('cannot change or remove a member of another organization', function () {
        [$attacker, $attackerOrg] = memberOf(Role::Owner);
        [$victim, $victimOrg] = memberOf(Role::Viewer);
        $victimMembership = $victim->membershipIn($victimOrg);

        $this->actingAs($attacker)
            ->patch(route('settings.members.update', ['organization' => $attackerOrg->slug, 'membership' => $victimMembership->id]), ['role' => 'admin'])
            ->assertNotFound();

        $this->actingAs($attacker)
            ->delete(route('settings.members.destroy', ['organization' => $attackerOrg->slug, 'membership' => $victimMembership->id]))
            ->assertNotFound();

        expect($victimMembership->fresh()->role)->toBe(Role::Viewer);
    });

    it('does not leak other organizations in shared props', function () {
        [$user, $mine] = memberOf();
        Organization::factory()->create(['name' => 'Someone Else Ltd']);

        $this->actingAs($user)
            ->get(route('dashboard', ['organization' => $mine->slug]))
            ->assertInertia(fn ($page) => $page
                ->has('auth.organizations', 1)
                ->where('auth.organizations.0.slug', $mine->slug));
    });

    it('only switches to organizations the user belongs to', function () {
        [$user, $first] = memberOf();
        [, $second] = memberOf(Role::Viewer);
        Membership::query()->create(['organization_id' => $second->id, 'user_id' => $user->id, 'role' => Role::Viewer]);
        [, $foreign] = memberOf();

        $this->actingAs($user)->post(route('organizations.switch', ['slug' => $second->slug]))
            ->assertRedirect(route('dashboard', ['organization' => $second->slug]));
        expect($user->fresh()->current_organization_id)->toBe($second->id);

        $this->actingAs($user)->post(route('organizations.switch', ['slug' => $foreign->slug]))->assertNotFound();
        expect($user->fresh()->current_organization_id)->toBe($second->id);
    });
});
