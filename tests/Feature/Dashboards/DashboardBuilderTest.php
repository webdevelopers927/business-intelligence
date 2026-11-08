<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/** @return array{0: User, 1: Organization, 2: array<string, int>} */
function builderFixture(Role $role = Role::Analyst): array
{
    [$org, $ids] = seedFixture();
    $user = User::factory()->create();
    Membership::query()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

    return [$user, $org, $ids];
}

function dashboardFor(Organization $org, User $owner, string $visibility = 'private'): Dashboard
{
    return asTenant($org, fn () => Dashboard::query()->create(['owner_id' => $owner->id, 'name' => 'Branch ops', 'visibility' => $visibility]));
}

function addWidget($test, User $user, Organization $org, Dashboard $d, array $data)
{
    return $test->actingAs($user)->post(route('dashboards.widgets.store', ['organization' => $org->slug, 'dashboard' => $d->id]), $data);
}

it('creates a dashboard and opens it in edit mode', function () {
    [$user, $org] = builderFixture();

    $response = $this->actingAs($user)->post(route('dashboards.store', ['organization' => $org->slug]), ['name' => 'Branch ops', 'visibility' => 'private']);

    $dashboard = asTenant($org, fn () => Dashboard::query()->sole());
    $response->assertRedirect(route('dashboards.show', ['organization' => $org->slug, 'dashboard' => $dashboard->id, 'edit' => 1]));
    expect($dashboard->owner_id)->toBe($user->id)
        ->and(AuditLog::query()->where('action', 'dashboard.created')->exists())->toBeTrue();

    $this->actingAs($user)->get(route('dashboards.show', ['organization' => $org->slug, 'dashboard' => $dashboard->id, 'edit' => 1]))
        ->assertInertia(fn (AssertableInertia $p) => $p->component('dashboards/Show')->where('startEditing', true)->where('dashboard.can.update', true));
});

it('validates widget configuration against metric and type', function () {
    [$user, $org] = builderFixture();
    $d = dashboardFor($org, $user);

    addWidget($this, $user, $org, $d, ['type' => 'kpi', 'title' => 'Revenue', 'metric' => 'revenue', 'dimension' => 'branch'])->assertSessionHasNoErrors();
    addWidget($this, $user, $org, $d, ['type' => 'bar', 'title' => 'Profit by category', 'metric' => 'net_profit', 'dimension' => 'category'])
        ->assertSessionHasErrors(['dimension' => "Net profit can't be broken down by product category."]);
    addWidget($this, $user, $org, $d, ['type' => 'line', 'title' => 'x', 'metric' => 'revenue', 'dimension' => 'branch'])->assertSessionHasErrors('dimension');
    addWidget($this, $user, $org, $d, ['type' => 'gauge', 'title' => 'x', 'metric' => 'revenue'])->assertSessionHasErrors('type');
    addWidget($this, $user, $org, $d, ['type' => 'kpi', 'title' => 'x', 'metric' => 'footfall'])->assertSessionHasErrors('metric');
    addWidget($this, $user, $org, $d, ['type' => 'kpi', 'title' => 'x', 'metric' => 'revenue', 'range_mode' => 'fixed', 'range_preset' => 'forever'])->assertSessionHasErrors('range_preset');

    $widget = asTenant($org, fn () => DashboardWidget::query()->sole());
    // KPI widgets carry no dimension even if one was submitted; size comes from the type.
    expect($widget->config['dimension'])->toBeNull()->and([$widget->w, $widget->h])->toBe([3, 2]);
});

it('serves widget data from the analytics engine using dashboard filters', function () {
    [$user, $org, $ids] = builderFixture();
    $d = dashboardFor($org, $user);
    addWidget($this, $user, $org, $d, ['type' => 'kpi', 'title' => 'Revenue', 'metric' => 'revenue']);
    addWidget($this, $user, $org, $d, ['type' => 'bar', 'title' => 'By branch', 'metric' => 'revenue', 'dimension' => 'branch']);
    addWidget($this, $user, $org, $d, ['type' => 'line', 'title' => 'Trend', 'metric' => 'revenue', 'dimension' => 'date']);
    addWidget($this, $user, $org, $d, ['type' => 'kpi', 'title' => 'Karachi', 'metric' => 'revenue', 'branches' => [$ids['khi']]]);
    [$kpi, $bar, $line, $khi] = asTenant($org, fn () => DashboardWidget::query()->orderBy('id')->get()->all());

    $url = fn (DashboardWidget $w, array $q = []) => route('dashboards.widgets.data', ['organization' => $org->slug, 'dashboard' => $d->id, 'widget' => $w->id, 'range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31', ...$q]);

    $this->actingAs($user)->getJson($url($kpi))->assertJsonPath('data.kind', 'kpi')->assertJsonPath('data.kpi.value', 340)->assertJsonPath('data.kpi.change', 240);
    $this->actingAs($user)->getJson($url($bar))->assertJsonPath('data.rows.0.label', 'Lahore')->assertJsonPath('data.rows.0.values.revenue', 250);
    $this->actingAs($user)->getJson($url($line))->assertJsonCount(31, 'data.series.keys');
    // Dashboard branch filter applies…
    $this->actingAs($user)->getJson($url($kpi, ['branches' => (string) $ids['lhr']]))->assertJsonPath('data.kpi.value', 250);
    // …unless the widget sets its own.
    $this->actingAs($user)->getJson($url($khi, ['branches' => (string) $ids['lhr']]))->assertJsonPath('data.kpi.value', 90);
});

it('persists the layout and restores it on reload', function () {
    [$user, $org] = builderFixture();
    $d = dashboardFor($org, $user);
    addWidget($this, $user, $org, $d, ['type' => 'kpi', 'title' => 'A', 'metric' => 'revenue']);
    addWidget($this, $user, $org, $d, ['type' => 'line', 'title' => 'B', 'metric' => 'revenue', 'dimension' => 'date']);
    [$a, $b] = asTenant($org, fn () => DashboardWidget::query()->orderBy('id')->get()->all());

    $this->actingAs($user)->putJson(route('dashboards.layout', ['organization' => $org->slug, 'dashboard' => $d->id]), ['items' => [
        ['id' => $a->id, 'x' => 9, 'y' => 0, 'w' => 3, 'h' => 2],
        ['id' => $b->id, 'x' => 11, 'y' => 2, 'w' => 1, 'h' => 99],  // too narrow, too tall, off the grid
    ]])->assertOk();

    $this->actingAs($user)->get(route('dashboards.show', ['organization' => $org->slug, 'dashboard' => $d->id]))
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('widgets.0.x', 9)
            ->where('widgets.1.w', 3)   // clamped to the line chart's minimum width
            ->where('widgets.1.x', 9)   // pulled back inside 12 columns
            ->where('widgets.1.h', 24)); // capped height
});

it('rejects layout entries for widgets of another dashboard', function () {
    [$user, $org] = builderFixture();
    $mine = dashboardFor($org, $user);
    $other = dashboardFor($org, $user);
    addWidget($this, $user, $org, $other, ['type' => 'kpi', 'title' => 'Other', 'metric' => 'revenue']);
    $foreign = asTenant($org, fn () => DashboardWidget::query()->sole());

    $this->actingAs($user)->putJson(route('dashboards.layout', ['organization' => $org->slug, 'dashboard' => $mine->id]), ['items' => [['id' => $foreign->id, 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2]]])
        ->assertStatus(422);
    $this->actingAs($user)->getJson(route('dashboards.widgets.data', ['organization' => $org->slug, 'dashboard' => $mine->id, 'widget' => $foreign->id]))->assertNotFound();
    $this->actingAs($user)->delete(route('dashboards.widgets.destroy', ['organization' => $org->slug, 'dashboard' => $mine->id, 'widget' => $foreign->id]))->assertNotFound();
});

it('applies visibility and sharing rules per role', function () {
    [$owner, $org] = builderFixture(Role::Analyst);
    [$analyst] = memberOf(Role::Analyst, $org);
    [$viewer] = memberOf(Role::Viewer, $org);
    [$admin] = memberOf(Role::Admin, $org);
    $d = dashboardFor($org, $owner);
    $show = fn (User $u) => $this->actingAs($u)->get(route('dashboards.show', ['organization' => $org->slug, 'dashboard' => $d->id]));
    $rename = fn (User $u) => $this->actingAs($u)->patch(route('dashboards.update', ['organization' => $org->slug, 'dashboard' => $d->id]), ['name' => 'Renamed']);

    // Private: owner and admins only.
    $show($owner)->assertOk();
    $show($admin)->assertOk();
    $show($analyst)->assertForbidden();
    $show($viewer)->assertForbidden();

    // Organization-wide: everyone views; only owner/admins edit.
    $this->actingAs($owner)->put(route('dashboards.share', ['organization' => $org->slug, 'dashboard' => $d->id]), ['visibility' => 'organization'])->assertSessionHasNoErrors();
    $show($viewer)->assertOk();
    $rename($analyst)->assertForbidden();

    // Specific members: view vs edit; an edit share does not let a viewer build.
    $this->actingAs($owner)->put(route('dashboards.share', ['organization' => $org->slug, 'dashboard' => $d->id]), [
        'visibility' => 'shared',
        'shares' => [['user_id' => $analyst->id, 'permission' => 'edit'], ['user_id' => $viewer->id, 'permission' => 'edit']],
    ])->assertSessionHasNoErrors();
    $rename($analyst)->assertRedirect();
    $rename($viewer)->assertForbidden();
    $show($viewer)->assertOk();

    // Only the owner or admins delete and share.
    $this->actingAs($analyst)->delete(route('dashboards.destroy', ['organization' => $org->slug, 'dashboard' => $d->id]))->assertForbidden();
    $this->actingAs($analyst)->put(route('dashboards.share', ['organization' => $org->slug, 'dashboard' => $d->id]), ['visibility' => 'private'])->assertForbidden();

    expect(AuditLog::query()->where('action', 'dashboard.shared')->count())->toBe(2);
});

it('lists only the dashboards a member may see', function () {
    [$owner, $org] = builderFixture(Role::Analyst);
    [$viewer] = memberOf(Role::Viewer, $org);
    dashboardFor($org, $owner, 'private');
    dashboardFor($org, $owner, 'organization');

    $this->actingAs($viewer)->get(route('dashboards.index', ['organization' => $org->slug]))
        ->assertInertia(fn (AssertableInertia $p) => $p->component('dashboards/Index')->has('dashboards', 1)->where('canCreate', false));
    $this->actingAs($owner)->get(route('dashboards.index', ['organization' => $org->slug]))
        ->assertInertia(fn (AssertableInertia $p) => $p->has('dashboards', 2)->where('canCreate', true));
});

it('only shares with members of the organization', function () {
    [$owner, $org] = builderFixture();
    [$outsider] = memberOf();
    $d = dashboardFor($org, $owner);

    $this->actingAs($owner)->put(route('dashboards.share', ['organization' => $org->slug, 'dashboard' => $d->id]), [
        'visibility' => 'shared', 'shares' => [['user_id' => $outsider->id, 'permission' => 'view']],
    ])->assertSessionHasErrors('shares');
});

it('duplicates a visible dashboard as a private copy with its widgets', function () {
    [$owner, $org] = builderFixture();
    [$analyst] = memberOf(Role::Analyst, $org);
    $d = dashboardFor($org, $owner, 'organization');
    addWidget($this, $owner, $org, $d, ['type' => 'kpi', 'title' => 'Revenue', 'metric' => 'revenue']);

    $this->actingAs($analyst)->post(route('dashboards.duplicate', ['organization' => $org->slug, 'dashboard' => $d->id]))->assertRedirect();

    $copy = asTenant($org, fn () => Dashboard::query()->where('owner_id', $analyst->id)->with('widgets')->sole());
    expect($copy->name)->toBe('Branch ops (copy)')
        ->and($copy->visibility)->toBe('private')
        ->and($copy->widgets)->toHaveCount(1);
});

it('keeps dashboards inside their organization', function () {
    [$owner, $orgA] = builderFixture();
    $d = dashboardFor($orgA, $owner, 'organization');
    addWidget($this, $owner, $orgA, $d, ['type' => 'kpi', 'title' => 'Revenue', 'metric' => 'revenue']);
    $w = asTenant($orgA, fn () => DashboardWidget::query()->sole());
    [$intruder, $orgB] = memberOf(Role::Owner);

    $params = ['organization' => $orgB->slug, 'dashboard' => $d->id];
    $this->actingAs($intruder)->get(route('dashboards.show', $params))->assertNotFound();
    $this->actingAs($intruder)->getJson(route('dashboards.widgets.data', [...$params, 'widget' => $w->id]))->assertNotFound();
    $this->actingAs($intruder)->putJson(route('dashboards.layout', $params), ['items' => []])->assertNotFound();
});
