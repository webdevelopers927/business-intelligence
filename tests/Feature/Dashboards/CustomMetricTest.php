<?php

use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Domain\Metrics\CustomMetricService;
use App\Enums\Role;
use App\Models\Dashboard;
use App\Models\Membership;
use App\Models\Metric;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/** @return array{0: User, 1: Organization, 2: array<string, int>} */
function metricFixture(Role $role = Role::Analyst): array
{
    [$org, $ids] = seedFixture();
    $user = User::factory()->create();
    Membership::query()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

    return [$user, $org, $ids];
}

function metricData(array $overrides = []): array
{
    return [
        'name' => 'Profit per order',
        'description' => null,
        'formula' => 'net_profit/orders',
        'format' => 'currency',
        'decimals' => 0,
        'polarity' => 'up_good',
        'visibility' => 'organization',
        ...$overrides,
    ];
}

it('creates a metric with a generated key and canonical formula', function () {
    [$user, $org] = metricFixture();

    $this->actingAs($user)->post(route('metrics.store', ['organization' => $org->slug]), metricData())->assertRedirect();

    $metric = asTenant($org, fn () => Metric::query()->sole());
    expect($metric->key)->toBe('profit_per_order')
        ->and($metric->formula)->toBe('net_profit / orders')
        ->and($metric->dependencies)->toBe(['net_profit', 'orders']);
});

it('computes custom metrics, including metrics built on other custom metrics', function () {
    [$user, $org] = metricFixture();
    asTenant($org, function () use ($user) {
        $svc = app(CustomMetricService::class);
        $svc->create($user, metricData());
        $svc->create($user, metricData(['name' => 'Double profit per order', 'formula' => 'profit_per_order * 2']));
        // Acronyms must produce a usable key (regression: "c_o_g_s_share").
        expect($svc->create($user, metricData(['name' => 'COGS share', 'formula' => 'cogs / revenue * 100', 'format' => 'percent']))->key)->toBe('cogs_share');
    }, Role::Analyst);

    $totals = asTenant($org, fn () => app(AnalyticsEngine::class)->totals(october(), new Filters));
    $byBranch = asTenant($org, fn () => app(AnalyticsEngine::class)->breakdown(october(), new Filters, 'branch', ['profit_per_order']));

    // October: net profit 10, orders 2, COGS 200, revenue 340.
    expect($totals['profit_per_order'])->toBe(5.0)
        ->and($totals['double_profit_per_order'])->toBe(10.0)
        ->and(round($totals['cogs_share'], 2))->toBe(58.82)
        // Lahore: net 10 over 1 order; Karachi: (90 − 60 − 0) / 1 = 30.
        ->and(collect($byBranch)->pluck('values.profit_per_order', 'label')->all())->toMatchArray(['Lahore' => 10.0, 'Karachi' => 30.0]);
});

it('returns null for a custom ratio with a zero denominator', function () {
    [$user, $org] = metricFixture();
    asTenant($org, fn () => app(CustomMetricService::class)->create($user, metricData()), Role::Analyst);
    $june = DateRange::custom(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'));

    expect(asTenant($org, fn () => app(AnalyticsEngine::class)->totals($june, new Filters)['profit_per_order']))->toBeNull();
});

it('rejects unknown references and cycles', function () {
    [$user, $org] = metricFixture();

    asTenant($org, function () use ($user) {
        $svc = app(CustomMetricService::class);

        expect(fn () => $svc->create($user, metricData(['formula' => 'revenue / footfall'])))
            ->toThrow(ValidationException::class);

        $a = $svc->create($user, metricData(['name' => 'Alpha', 'formula' => 'revenue * 2']));
        $svc->create($user, metricData(['name' => 'Beta', 'formula' => 'alpha + 1']));

        expect(fn () => $svc->update($a, $user, metricData(['name' => 'Alpha', 'formula' => 'beta * 2'])))
            ->toThrow(ValidationException::class, 'refers back to itself');
    }, Role::Analyst);
});

it('keeps private metrics private to their author', function () {
    [$author, $org] = metricFixture();
    [$other] = memberOf(Role::Analyst, $org);
    asTenant($org, fn () => app(CustomMetricService::class)->create($author, metricData(['visibility' => 'private'])), Role::Analyst);

    $this->actingAs($other)
        ->postJson(route('metrics.preview', ['organization' => $org->slug]), ['formula' => 'profit_per_order * 2'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Unknown metric: profit_per_order.');

    $this->actingAs($other)->get(route('metrics.index', ['organization' => $org->slug]))
        ->assertInertia(fn (AssertableInertia $p) => $p->component('metrics/Index')->has('custom', 0));
});

it('previews a formula against live data and reports errors', function () {
    [$user, $org] = metricFixture();

    $this->actingAs($user)
        ->postJson(route('metrics.preview', ['organization' => $org->slug]), ['formula' => 'revenue - cogs'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['value', 'inputs' => ['revenue', 'cogs'], 'range']]);

    $this->actingAs($user)
        ->postJson(route('metrics.preview', ['organization' => $org->slug]), ['formula' => 'revenue +'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The formula ends unexpectedly — a value is missing.');
});

it('blocks deleting a metric that a widget or another metric uses', function () {
    [$user, $org] = metricFixture();
    $metric = asTenant($org, function () use ($user) {
        $metric = app(CustomMetricService::class)->create($user, metricData());
        $dashboard = Dashboard::query()->create(['owner_id' => $user->id, 'name' => 'Ops', 'visibility' => 'private']);
        $dashboard->widgets()->create(['type' => 'kpi', 'title' => 'PPO', 'config' => ['metric' => 'profit_per_order'], 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2]);

        return $metric;
    }, Role::Analyst);

    $this->actingAs($user)
        ->delete(route('metrics.destroy', ['organization' => $org->slug, 'metric' => $metric->id]))
        ->assertSessionHasErrors(['metric' => 'This metric is used by 1 dashboard widget. Remove those uses first.']);

    expect(asTenant($org, fn () => Metric::query()->count()))->toBe(1);
});

it('lets viewers see metrics but not define them', function () {
    [$viewer, $org] = metricFixture(Role::Viewer);

    $this->actingAs($viewer)->get(route('metrics.index', ['organization' => $org->slug]))->assertOk();
    $this->actingAs($viewer)->get(route('metrics.create', ['organization' => $org->slug]))->assertForbidden();
    $this->actingAs($viewer)->post(route('metrics.store', ['organization' => $org->slug]), metricData())->assertForbidden();
});
