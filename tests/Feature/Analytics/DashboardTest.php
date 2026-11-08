<?php

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/** Fixture organization plus a member with the given role. @return array{0: User, 1: Organization, 2: array<string, int>} */
function dashboardFixture(Role $role = Role::Owner): array
{
    [$org, $ids] = seedFixture();
    $user = User::factory()->create();
    Membership::query()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

    return [$user, $org, $ids];
}

function octoberUrl(Organization $org, array $extra = []): string
{
    return route('dashboard', ['organization' => $org->slug, 'range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31', ...$extra]);
}

it('shows the setup checklist until the organization has sales data', function () {
    [$user, $org] = memberOf();

    $this->actingAs($user)->get(route('dashboard', ['organization' => $org->slug]))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('app/Overview'));
});

it('renders KPIs from the database with comparison and filter state', function () {
    [$user, $org] = dashboardFixture();

    $this->actingAs($user)->get(octoberUrl($org))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/Executive')
            ->where('filters.range', 'custom')
            ->where('period.current.label', 'Oct 1–31, 2026')
            ->where('period.previous.label', 'Aug 31 – Sep 30, 2026')
            ->where('kpis.0.key', 'revenue')
            ->where('kpis.0.value', 340)
            ->where('kpis.0.previous', 100)
            ->where('kpis.0.change', 240)
            ->where('kpis.1.key', 'net_profit')
            ->where('kpis.1.value', 10)
            ->where('kpis.2.key', 'operating_expenses')
            ->where('kpis.3.key', 'orders')
            ->where('kpis.3.value', 2)
            ->missing('trend')
            ->missing('breakdowns'));
});

it('loads every deferred panel from the same numbers', function () {
    [$user, $org, $ids] = dashboardFixture();

    $this->actingAs($user)->get(octoberUrl($org))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(['trend', 'breakdowns', 'tables', 'insights'], fn (AssertableInertia $reload) => $reload
                ->has('trend.revenue.keys', 31)
                ->where('trend.revenue.series.revenue.4', 250)
                ->has('trend.revenue_vs_expenses.keys', 12)
                ->where('trend.revenue_vs_expenses.series.revenue.11', 340)
                ->where('trend.revenue_vs_expenses.series.operating_expenses.11', 130)
                ->where('breakdowns.branches.0.label', 'Lahore')
                ->where('breakdowns.branches.0.values.revenue', 250)
                ->has('breakdowns.branches', 2) // head office excluded from branch performance
                ->where('breakdowns.categories.0.share', 85.3)
                ->where('tables.top_products.0.sku', 'P1')
                ->where('tables.top_products.0.revenue', 290)
                ->has('tables.recent_transactions', 3) // all statuses are listed
                ->where('tables.recent_transactions.0.reference', 'T3')
                ->where('tables.recent_transactions.0.status', 'refunded')
                ->where('insights.0.kind', 'revenue_change')
                ->where('insights.0.title', 'Revenue up 240.0%')));
});

it('applies branch and category filters from the URL and drops foreign ids', function () {
    [$user, $org, $ids] = dashboardFixture();
    [, $otherIds] = seedFixture();

    $this->actingAs($user)->get(octoberUrl($org, ['branches' => "{$ids['lhr']},{$otherIds['khi']}"]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.branches', [$ids['lhr']])
            ->where('kpis.0.value', 250));

    $this->actingAs($user)->get(octoberUrl($org, ['categories' => (string) $ids['catX']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis.0.value', 290)
            ->where('kpis.2.ignored_filters', ['category']));
});

it('falls back to this month for an invalid range', function () {
    [$user, $org] = dashboardFixture();

    $this->actingAs($user)->get(route('dashboard', ['organization' => $org->slug, 'range' => 'bogus', 'from' => 'x']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.range', 'this_month'));
});

it('lets viewers read the dashboard and explorer', function () {
    [$viewer, $org] = dashboardFixture(Role::Viewer);

    $this->actingAs($viewer)->get(octoberUrl($org))->assertOk();
    $this->actingAs($viewer)->get(route('explore', ['organization' => $org->slug]))->assertOk();
});

it('drills from months to branches to categories to transactions', function () {
    [$user, $org, $ids] = dashboardFixture();
    $explore = fn (array $params) => route('explore', ['organization' => $org->slug, ...$params]);

    $this->actingAs($user)->get($explore(['month' => '2026-10']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/Explore')
            ->where('level', 'branches')
            ->where('crumbs.1.label', 'October 2026')
            ->where('totals.revenue.value', 340)
            ->loadDeferredProps(fn (AssertableInertia $r) => $r->where('data.0.label', 'Lahore')->where('data.0.values.revenue', 250)));

    $this->actingAs($user)->get($explore(['month' => '2026-10', 'branch' => $ids['lhr']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('level', 'categories')
            ->where('crumbs.2.label', 'Lahore')
            ->where('totals.revenue.value', 250)
            ->loadDeferredProps(fn (AssertableInertia $r) => $r->has('data', 2)->where('data.0.values.revenue', 200)));

    $this->actingAs($user)->get($explore(['month' => '2026-10', 'branch' => $ids['lhr'], 'category' => $ids['catX']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('level', 'transactions')
            ->where('crumbs.3.label', 'Appliances')
            ->loadDeferredProps(fn (AssertableInertia $r) => $r
                ->has('data.rows', 1)
                ->where('data.rows.0.reference', 'T1')
                ->where('data.rows.0.amount', 200)
                ->where('data.meta.total', 1)));
});

it('ignores invalid or foreign drill-down parameters', function () {
    [$user, $org] = dashboardFixture();
    [, $otherIds] = seedFixture();

    $this->actingAs($user)->get(route('explore', ['organization' => $org->slug, 'month' => 'nope']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('level', 'months'));

    $this->actingAs($user)->get(route('explore', ['organization' => $org->slug, 'month' => '2026-10', 'branch' => $otherIds['lhr']]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('level', 'branches')->has('crumbs', 2));
});

it('keeps another organization\'s analytics out of reach', function () {
    [, $orgA] = dashboardFixture();
    [$intruder] = memberOf();

    $this->actingAs($intruder)->get(route('dashboard', ['organization' => $orgA->slug]))->assertNotFound();
    $this->actingAs($intruder)->get(route('explore', ['organization' => $orgA->slug]))->assertNotFound();
    expect(DB::table('sales_transactions')->where('organization_id', $orgA->id)->count())->toBe(4);
});
