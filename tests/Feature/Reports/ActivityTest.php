<?php

use App\Domain\Audit\AuditLogger;
use App\Enums\Role;
use Inertia\Testing\AssertableInertia;

it('shows owners and admins their own organization activity only', function () {
    [$admin, $org] = memberOf(Role::Admin);
    [, $otherOrg] = memberOf();
    $log = app(AuditLogger::class);
    $log->record('report.saved', null, ['name' => 'Ours'], $org->id, $admin->id);
    $log->record('alert.created', null, ['name' => 'Also ours'], $org->id, $admin->id);
    $log->record('report.saved', null, ['name' => 'Theirs'], $otherOrg->id);

    $this->actingAs($admin)->get(route('activity.index', ['organization' => $org->slug]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('activity/Index')
            ->where('logs.data', fn ($rows) => collect($rows)->pluck('metadata.name')->filter()->sort()->values()->all() === ['Also ours', 'Ours']));

    $this->actingAs($admin)->get(route('activity.index', ['organization' => $org->slug, 'area' => 'report']))
        ->assertInertia(fn (AssertableInertia $p) => $p->has('logs.data', 1)->where('logs.data.0.area', 'Reports')->where('logs.data.0.actor', $admin->name));
});

it('hides the audit log from analysts and viewers', function (Role $role) {
    [$user, $org] = memberOf($role);

    $this->actingAs($user)->get(route('activity.index', ['organization' => $org->slug]))->assertForbidden();
})->with([Role::Analyst, Role::Viewer]);
