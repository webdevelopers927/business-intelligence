<?php

use App\Domain\Analytics\AnalyticsCache;
use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Domain\Analytics\Query\Granularity;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function engine(Organization $org, Closure $fn): mixed
{
    return asTenant($org, fn () => $fn(app(AnalyticsEngine::class)));
}

it('calculates revenue, costs, profit and margins from records', function () {
    [$org] = seedFixture();

    $totals = engine($org, fn (AnalyticsEngine $e) => $e->totals(october(), new Filters));

    expect($totals)->toMatchArray([
        'revenue' => 340.0,
        'cogs' => 200.0,
        'gross_profit' => 140.0,
        'gross_margin' => 41.18,
        'operating_expenses' => 130.0,
        'net_profit' => 10.0,
        'profit_margin' => 2.94,
        'expense_ratio' => 38.24,
        'orders' => 2.0,
        'units_sold' => 4.0,
        'average_order_value' => 170.0,
    ]);
});

it('compares with the previous period, including a negative base', function () {
    [$org] = seedFixture();

    $kpis = engine($org, fn (AnalyticsEngine $e) => $e->kpis(october(), new Filters, ['revenue', 'net_profit', 'operating_expenses', 'orders']));

    // Previous period for Oct 1–31 is Aug 31 – Sep 30.
    expect($kpis['revenue'])->toMatchArray(['value' => 340.0, 'previous' => 100.0, 'change' => 240.0])
        ->and($kpis['net_profit'])->toMatchArray(['value' => 10.0, 'previous' => -10.0, 'change' => 200.0])
        ->and($kpis['operating_expenses'])->toMatchArray(['value' => 130.0, 'previous' => 70.0, 'change' => 85.71])
        ->and($kpis['orders'])->toMatchArray(['previous' => 1.0, 'change' => 100.0]);
});

it('returns null instead of dividing by zero', function () {
    [$org] = seedFixture();
    $empty = DateRange::custom(CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'));

    $totals = engine($org, fn (AnalyticsEngine $e) => $e->totals($empty, new Filters));
    $kpis = engine($org, fn (AnalyticsEngine $e) => $e->kpis($empty, new Filters, ['revenue']));

    expect($totals['revenue'])->toBe(0.0)
        ->and($totals['average_order_value'])->toBeNull()
        ->and($totals['profit_margin'])->toBeNull()
        ->and($kpis['revenue']['change'])->toBeNull();
});

it('applies the branch filter to sales and branch-attributed expenses', function () {
    [$org, $ids] = seedFixture();

    $totals = engine($org, fn (AnalyticsEngine $e) => $e->totals(october(), new Filters(branchIds: [$ids['lhr']])));

    // Head-office costs (no branch) are excluded under a branch filter.
    expect($totals)->toMatchArray(['revenue' => 250.0, 'cogs' => 140.0, 'operating_expenses' => 100.0, 'net_profit' => 10.0, 'orders' => 1.0]);
});

it('applies the category filter to sales and reports it as not applicable to expenses', function () {
    [$org, $ids] = seedFixture();
    $filters = new Filters(categoryIds: [$ids['catX']]);

    $totals = engine($org, fn (AnalyticsEngine $e) => $e->totals(october(), $filters));
    $ignored = engine($org, fn (AnalyticsEngine $e) => $e->ignoredFilters('operating_expenses', $filters));

    expect($totals)->toMatchArray(['revenue' => 290.0, 'cogs' => 180.0, 'units_sold' => 3.0, 'orders' => 2.0])
        ->and($ignored)->toBe(['category'])
        ->and(engine($org, fn (AnalyticsEngine $e) => $e->ignoredFilters('revenue', $filters)))->toBe([]);
});

it('ignores foreign ids in filters', function () {
    [$org] = seedFixture();
    [$other, $otherIds] = seedFixture();

    $totals = engine($org, fn (AnalyticsEngine $e) => $e->totals(october(), new Filters(branchIds: [$otherIds['lhr']])));

    expect($totals['revenue'])->toBe(0.0);
});

it('builds a gap-filled daily series that sums to the total', function () {
    [$org] = seedFixture();

    $series = engine($org, fn (AnalyticsEngine $e) => $e->series(october(), new Filters, ['revenue', 'orders'], Granularity::Day, compare: true));

    expect($series['keys'])->toHaveCount(31)
        ->and($series['keys'][0])->toBe('2026-10-01')
        ->and($series['series']['revenue'][4])->toBe(250.0)   // Oct 5
        ->and($series['series']['revenue'][5])->toBe(90.0)    // Oct 6
        ->and($series['series']['revenue'][6])->toBe(0.0)     // Oct 7: refunded only
        ->and(array_sum($series['series']['revenue']))->toBe(340.0)
        ->and(array_sum($series['previous']['revenue']))->toBe(100.0)
        ->and($series['previous_labels'][0])->toBe('Aug 31');
});

it('aggregates monthly buckets', function () {
    [$org] = seedFixture();
    $range = DateRange::custom(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31'));

    $series = engine($org, fn (AnalyticsEngine $e) => $e->series($range, new Filters, ['revenue', 'operating_expenses', 'net_profit'], Granularity::Month));

    expect($series['keys'])->toBe(['2026-09', '2026-10'])
        ->and($series['series'])->toBe([
            'revenue' => [100.0, 340.0],
            'operating_expenses' => [70.0, 130.0],
            'net_profit' => [-10.0, 10.0],
        ]);
});

it('breaks down by branch and category with stable colors and comparison', function () {
    [$org, $ids] = seedFixture();

    $branches = engine($org, fn (AnalyticsEngine $e) => $e->breakdown(october(), new Filters, 'branch', ['revenue', 'net_profit'], compare: true));
    $categories = engine($org, fn (AnalyticsEngine $e) => $e->breakdown(october(), new Filters, 'category', ['revenue', 'units_sold']));

    expect(array_column($branches, 'label'))->toBe(['Lahore', 'Karachi', 'Head office'])
        ->and($branches[0]['values'])->toBe(['revenue' => 250.0, 'net_profit' => 10.0])
        ->and($branches[0]['previous'])->toBe(['revenue' => 100.0, 'net_profit' => -10.0])
        ->and($branches[2]['values'])->toBe(['revenue' => 0.0, 'net_profit' => -30.0])
        ->and($branches[0]['color_index'])->toBe(0)
        ->and($branches[1]['color_index'])->toBe(1)
        ->and($categories[0])->toMatchArray(['id' => $ids['catX'], 'label' => 'Appliances', 'values' => ['revenue' => 290.0, 'units_sold' => 3.0]]);

    // Filtering to Karachi keeps Karachi's color slot.
    $filtered = engine($org, fn (AnalyticsEngine $e) => $e->breakdown(october(), new Filters(branchIds: [$ids['khi']]), 'branch', ['revenue']));
    expect($filtered[0])->toMatchArray(['label' => 'Karachi', 'color_index' => 1]);
});

it('refuses breakdowns that would mix incompatible facts', function () {
    [$org] = seedFixture();

    engine($org, fn (AnalyticsEngine $e) => $e->breakdown(october(), new Filters, 'category', ['net_profit']));
})->throws(InvalidArgumentException::class, "can't be broken down by [category]");

it('serves cached results until the organization cache version is bumped', function () {
    [$org, $ids] = seedFixture();

    $before = engine($org, fn (AnalyticsEngine $e) => $e->totals(october(), new Filters)['revenue']);
    DB::table('sales_transactions')->insert(['organization_id' => $org->id, 'branch_id' => $ids['lhr'], 'reference' => 'T9', 'occurred_at' => '2026-10-20 07:00:00', 'business_date' => '2026-10-20', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_transaction_items')->insert(['organization_id' => $org->id, 'sales_transaction_id' => DB::getPdo()->lastInsertId(), 'product_id' => $ids['p1'], 'branch_id' => $ids['lhr'], 'business_date' => '2026-10-20', 'quantity' => 1, 'unit_price' => 100, 'unit_cost' => 60, 'discount_amount' => 0, 'net_amount' => 100, 'natural_key_hash' => sha1('T9')]);

    $stale = engine($org, fn (AnalyticsEngine $e) => $e->totals(october(), new Filters)['revenue']);
    app(AnalyticsCache::class)->bump($org->id);
    $fresh = engine($org, fn (AnalyticsEngine $e) => $e->totals(october(), new Filters)['revenue']);

    expect([$before, $stale, $fresh])->toBe([340.0, 340.0, 440.0]);
});

it('never mixes organizations', function () {
    [$orgA] = seedFixture();
    [$orgB] = seedFixture();
    // Extra sale only in B.
    DB::table('sales_transactions')->insert(['organization_id' => $orgB->id, 'branch_id' => DB::table('branches')->where('organization_id', $orgB->id)->value('id'), 'reference' => 'B-ONLY', 'occurred_at' => '2026-10-10 07:00:00', 'business_date' => '2026-10-10', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_transaction_items')->insert(['organization_id' => $orgB->id, 'sales_transaction_id' => DB::getPdo()->lastInsertId(), 'product_id' => DB::table('products')->where('organization_id', $orgB->id)->value('id'), 'branch_id' => DB::table('branches')->where('organization_id', $orgB->id)->value('id'), 'business_date' => '2026-10-10', 'quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 1, 'discount_amount' => 0, 'net_amount' => 1000, 'natural_key_hash' => sha1('B-ONLY')]);

    expect(engine($orgA, fn (AnalyticsEngine $e) => $e->totals(october(), new Filters)['revenue']))->toBe(340.0)
        ->and(engine($orgB, fn (AnalyticsEngine $e) => $e->totals(october(), new Filters)['revenue']))->toBe(1340.0);
});

it('prorates monthly revenue targets to the range', function () {
    [$org, $ids] = seedFixture();
    DB::table('revenue_targets')->insert([
        ['organization_id' => $org->id, 'branch_id' => $ids['lhr'], 'month' => '2026-10-01', 'amount' => 3100, 'created_at' => now(), 'updated_at' => now()],
        ['organization_id' => $org->id, 'branch_id' => null, 'month' => '2026-10-01', 'amount' => 6200, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $firstTen = DateRange::custom(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-10'));

    $targets = engine($org, fn (AnalyticsEngine $e) => $e->targets($firstTen));

    expect(round($targets[(string) $ids['lhr']], 2))->toBe(1000.0)
        ->and(round($targets[''], 2))->toBe(2000.0);
});

it('reports inventory from the latest snapshot and current headcount', function () {
    [$org, $ids] = seedFixture();
    $o = $org->id;
    $now = now();
    $dept = DB::table('departments')->insertGetId(['organization_id' => $o, 'code' => 'SAL', 'name' => 'Sales', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('inventory_records')->insert([
        ['organization_id' => $o, 'product_id' => $ids['p1'], 'branch_id' => $ids['lhr'], 'recorded_on' => '2026-10-01', 'quantity_on_hand' => 99, 'reorder_level' => 5, 'unit_cost' => 60, 'created_at' => $now, 'updated_at' => $now],
        ['organization_id' => $o, 'product_id' => $ids['p1'], 'branch_id' => $ids['lhr'], 'recorded_on' => '2026-11-01', 'quantity_on_hand' => 10, 'reorder_level' => 5, 'unit_cost' => 60, 'created_at' => $now, 'updated_at' => $now],
        ['organization_id' => $o, 'product_id' => $ids['p2'], 'branch_id' => $ids['lhr'], 'recorded_on' => '2026-11-01', 'quantity_on_hand' => 2, 'reorder_level' => 4, 'unit_cost' => 20, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('employees')->insert([
        ['organization_id' => $o, 'employee_code' => 'E1', 'full_name' => 'A', 'department_id' => $dept, 'branch_id' => $ids['lhr'], 'job_title' => 'Associate', 'hired_on' => '2025-01-01', 'terminated_on' => null, 'created_at' => $now, 'updated_at' => $now],
        ['organization_id' => $o, 'employee_code' => 'E2', 'full_name' => 'B', 'department_id' => $dept, 'branch_id' => $ids['lhr'], 'job_title' => 'Associate', 'hired_on' => '2025-01-01', 'terminated_on' => '2026-10-15', 'created_at' => $now, 'updated_at' => $now],
        ['organization_id' => $o, 'employee_code' => 'E3', 'full_name' => 'C', 'department_id' => $dept, 'branch_id' => $ids['khi'], 'job_title' => 'Associate', 'hired_on' => '2026-11-05', 'terminated_on' => null, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $inventory = engine($org, fn (AnalyticsEngine $e) => $e->inventory(CarbonImmutable::parse('2026-11-02'), new Filters));
    $octInventory = engine($org, fn (AnalyticsEngine $e) => $e->inventory(CarbonImmutable::parse('2026-10-20'), new Filters));
    $lowStock = engine($org, fn (AnalyticsEngine $e) => $e->lowStock(CarbonImmutable::parse('2026-11-02'), new Filters));
    $headcount = engine($org, fn (AnalyticsEngine $e) => $e->headcount(CarbonImmutable::parse('2026-10-31'), new Filters));

    expect($inventory)->toMatchArray(['value' => 640.0, 'low_stock' => 1, 'lines' => 2, 'as_of' => '2026-11-01'])
        ->and($octInventory['value'])->toBe(5940.0)
        ->and($lowStock[0])->toMatchArray(['sku' => 'P2', 'on_hand' => 2.0])
        ->and($headcount)->toBe(['total' => 1, 'by_department' => [['label' => 'Sales', 'value' => 1]]]);
});
