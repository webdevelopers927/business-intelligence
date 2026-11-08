<?php

use App\Domain\Tenancy\TenantContext;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Create a user who is a member of a fresh organization with the given role.
 *
 * @return array{0: User, 1: Organization}
 */
function memberOf(Role $role = Role::Owner, ?Organization $organization = null): array
{
    $user = User::factory()->create();

    if ($organization === null) {
        $organization = Organization::factory()->withMember($user, $role)->create();
    } else {
        $organization->memberships()->create(['user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);
    }

    return [$user, $organization];
}

require_once __DIR__.'/Support/AnalyticsFixture.php';

/** Run a callback inside the given organization's tenant context. */
function asTenant(Organization $organization, callable $callback, Role $role = Role::Owner): mixed
{
    return app(TenantContext::class)->runAs($organization, $callback, $role);
}
