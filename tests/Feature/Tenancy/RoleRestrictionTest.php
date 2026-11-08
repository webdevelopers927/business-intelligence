<?php

use App\Domain\Organizations\MembershipService;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Membership;
use Illuminate\Validation\ValidationException;

it('applies the permission matrix per role', function (Role $role, array $allowed, array $denied) {
    foreach ($allowed as $p) {
        expect($role->can($p))->toBeTrue("{$role->value} should have {$p->value}");
    }
    foreach ($denied as $p) {
        expect($role->can($p))->toBeFalse("{$role->value} should not have {$p->value}");
    }
})->with([
    'owner' => [Role::Owner, Permission::cases(), []],
    'admin' => [Role::Admin, [Permission::MembersManage, Permission::SourcesManage, Permission::AuditView], [Permission::BillingView, Permission::MembersManageOwners]],
    'analyst' => [Role::Analyst, [Permission::ImportsCreate, Permission::DashboardsCreate, Permission::MetricsManage], [Permission::MembersManage, Permission::SourcesManage, Permission::OrgSettingsManage]],
    'viewer' => [Role::Viewer, [Permission::DashboardsView, Permission::ReportsExport], [Permission::ImportsCreate, Permission::DashboardsCreate, Permission::SourcesView, Permission::MembersManage]],
]);

it('forbids settings pages for roles without permission', function (Role $role, int $orgSettings, int $members) {
    [$user, $org] = memberOf($role);

    $this->actingAs($user)->get(route('settings.organization.edit', ['organization' => $org->slug]))->assertStatus($orgSettings);
    $this->actingAs($user)->get(route('settings.members.index', ['organization' => $org->slug]))->assertStatus($members);
})->with([
    'owner' => [Role::Owner, 200, 200],
    'admin' => [Role::Admin, 200, 200],
    'analyst' => [Role::Analyst, 403, 403],
    'viewer' => [Role::Viewer, 403, 403],
]);

it('forbids a viewer from updating organization settings', function () {
    [$user, $org] = memberOf(Role::Viewer);

    $this->actingAs($user)
        ->patch(route('settings.organization.update', ['organization' => $org->slug]), [
            'name' => 'Hijacked', 'industry' => 'retail', 'size' => '1_10',
            'timezone' => 'UTC', 'currency' => 'USD', 'fiscal_year_start_month' => 1,
        ])->assertForbidden();

    expect($org->fresh()->name)->not->toBe('Hijacked');
});

it('shares only the acting role\'s permissions with the frontend', function () {
    [$user, $org] = memberOf(Role::Viewer);

    $this->actingAs($user)
        ->get(route('dashboard', ['organization' => $org->slug]))
        ->assertInertia(fn ($page) => $page->where('tenant.permissions', ['dashboards.view', 'reports.export', 'ai.use']));
});

it('lets an admin manage analysts but not owners', function () {
    [$admin, $org] = memberOf(Role::Admin);
    [$owner] = memberOf(Role::Owner, $org);
    [$analyst] = memberOf(Role::Analyst, $org);

    $analystMembership = $analyst->membershipIn($org);
    $ownerMembership = $owner->membershipIn($org);

    $this->actingAs($admin)
        ->patch(route('settings.members.update', ['organization' => $org->slug, 'membership' => $analystMembership->id]), ['role' => 'viewer'])
        ->assertRedirect();
    expect($analystMembership->fresh()->role)->toBe(Role::Viewer);

    // Cannot promote to owner…
    $this->actingAs($admin)
        ->patch(route('settings.members.update', ['organization' => $org->slug, 'membership' => $analystMembership->id]), ['role' => 'owner'])
        ->assertForbidden();
    // …nor touch an existing owner.
    $this->actingAs($admin)
        ->delete(route('settings.members.destroy', ['organization' => $org->slug, 'membership' => $ownerMembership->id]))
        ->assertForbidden();

    expect($ownerMembership->fresh())->not->toBeNull();
});

it('does not let a member edit their own membership from the members screen', function () {
    [$owner, $org] = memberOf(Role::Owner);

    $this->actingAs($owner)
        ->patch(route('settings.members.update', ['organization' => $org->slug, 'membership' => $owner->membershipIn($org)->id]), ['role' => 'viewer'])
        ->assertForbidden();
});

it('keeps at least one owner', function () {
    [$owner, $org] = memberOf(Role::Owner);
    $service = app(MembershipService::class);

    expect(fn () => $service->changeRole($org, $owner->membershipIn($org), Role::Admin))
        ->toThrow(ValidationException::class, 'at least one owner');
    expect(fn () => $service->remove($org, $owner->membershipIn($org)))
        ->toThrow(ValidationException::class);

    // With a second owner, demotion is allowed.
    [$second] = memberOf(Role::Owner, $org);
    $service->changeRole($org, $owner->membershipIn($org), Role::Admin);
    expect($owner->membershipIn($org)->role)->toBe(Role::Admin)
        ->and(Membership::query()->where('organization_id', $org->id)->where('role', 'owner')->pluck('user_id')->all())->toBe([$second->id]);
});

it('lets an owner remove a member, which also clears their current organization', function () {
    [$owner, $org] = memberOf(Role::Owner);
    [$analyst] = memberOf(Role::Analyst, $org);
    $analyst->forceFill(['current_organization_id' => $org->id])->save();

    $this->actingAs($owner)
        ->delete(route('settings.members.destroy', ['organization' => $org->slug, 'membership' => $analyst->membershipIn($org)->id]))
        ->assertRedirect();

    expect($analyst->membershipIn($org))->toBeNull()
        ->and($analyst->fresh()->current_organization_id)->toBeNull();

    $this->actingAs($analyst)->get(route('dashboard', ['organization' => $org->slug]))->assertNotFound();
});
