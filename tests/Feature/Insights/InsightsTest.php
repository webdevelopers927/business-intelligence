<?php

use App\Domain\Analytics\AnalyticsCache;
use App\Domain\Insights\AnomalyService;
use App\Domain\Insights\ForecastService;
use App\Enums\Role;
use App\Models\Anomaly;
use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * Nine weeks of daily sales ending 8 Nov 2026 (today = 9 Nov): Lahore sells
 * 1,000 + 100 × weekday-index (with ±20 alternating noise), Karachi a flat 500
 * (±10). On Thursday 5 Nov Lahore sells 5,000 — the planted anomaly.
 */
function seedDailySales(): array
{
    [, $org] = memberOf();
    $ids = asTenant($org, function () use ($org) {
        $now = now();
        $o = $org->id;
        $lhr = DB::table('branches')->insertGetId(['organization_id' => $o, 'code' => 'LHR', 'name' => 'Lahore', 'created_at' => $now, 'updated_at' => $now]);
        $khi = DB::table('branches')->insertGetId(['organization_id' => $o, 'code' => 'KHI', 'name' => 'Karachi', 'created_at' => $now, 'updated_at' => $now]);
        $cat = DB::table('product_categories')->insertGetId(['organization_id' => $o, 'name' => 'General', 'created_at' => $now, 'updated_at' => $now]);
        $product = DB::table('products')->insertGetId(['organization_id' => $o, 'product_category_id' => $cat, 'sku' => 'P1', 'name' => 'Product', 'unit_price' => 1, 'unit_cost' => 0.5, 'created_at' => $now, 'updated_at' => $now]);

        $sale = function (string $date, int $branch, float $amount, string $ref) use ($o, $now, $product) {
            $id = DB::table('sales_transactions')->insertGetId(['organization_id' => $o, 'branch_id' => $branch, 'reference' => $ref, 'occurred_at' => "{$date} 10:00:00", 'business_date' => $date, 'status' => 'completed', 'net_amount' => $amount, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('sales_transaction_items')->insert(['organization_id' => $o, 'sales_transaction_id' => $id, 'product_id' => $product, 'branch_id' => $branch, 'business_date' => $date,
                'quantity' => 1, 'unit_price' => $amount, 'unit_cost' => $amount / 2, 'discount_amount' => 0, 'net_amount' => $amount, 'natural_key_hash' => sha1($ref)]);
        };

        $day = CarbonImmutable::parse('2026-09-07'); // Monday, 9 weeks before 9 Nov
        for ($i = 0; $day->lte(CarbonImmutable::parse('2026-11-08')); $i++, $day = $day->addDay()) {
            $date = $day->toDateString();
            $lahore = $date === '2026-11-05' ? 5000.0 : 1000 + 100 * ($day->dayOfWeekIso - 1) + ($i % 2 ? 20 : -20);
            $sale($date, $lhr, $lahore, "L{$i}");
            $sale($date, $khi, 500 + ($i % 2 ? 10 : -10), "K{$i}");
        }

        return compact('lhr', 'khi');
    });

    return [$org, $ids];
}

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-09 09:00', 'Asia/Karachi')));

it('detects the planted anomaly, explains it and names the driving branch', function () {
    [$org] = seedDailySales();

    $result = asTenant($org, fn () => app(AnomalyService::class)->scan());
    $revenue = asTenant($org, fn () => Anomaly::query()->where('metric_key', 'revenue')->get());

    expect($result['detected'])->toBeGreaterThanOrEqual(1)
        ->and($revenue)->toHaveCount(1)
        ->and($revenue[0]->business_date->toDateString())->toBe('2026-11-05')
        ->and($revenue[0]->severity)->toBe('critical')
        ->and($revenue[0]->baseline)->toBe('weekday')
        ->and((float) $revenue[0]->actual)->toBe(5510.0) // Lahore 5,000 + Karachi 510
        ->and($revenue[0]->explanation)->toContain('Thursday')->toContain('Lahore');
});

it('is idempotent, clears anomalies that no longer hold, and stays inside the organization', function () {
    [$org] = seedDailySales();
    [, $other] = memberOf();

    asTenant($org, fn () => app(AnomalyService::class)->scan());
    $second = asTenant($org, fn () => app(AnomalyService::class)->scan());
    expect($second['removed'])->toBe(0);

    // Correct the planted value (e.g. a fixed re-import): the anomaly disappears.
    DB::table('sales_transactions')->where('organization_id', $org->id)->where('business_date', '2026-11-05')->where('net_amount', 5000)->update(['net_amount' => 1320]);
    DB::table('sales_transaction_items')->where('organization_id', $org->id)->where('business_date', '2026-11-05')->where('net_amount', 5000)->update(['net_amount' => 1320, 'unit_price' => 1320]);
    app(AnalyticsCache::class)->bump($org->id);
    asTenant($org, fn () => app(AnomalyService::class)->scan());

    expect(asTenant($org, fn () => Anomaly::query()->where('metric_key', 'revenue')->count()))->toBe(0)
        ->and(asTenant($other, fn () => Anomaly::query()->count()))->toBe(0);
});

it('forecasts from complete months only and says when history is too short', function () {
    [$org] = seedDailySales();

    $f = asTenant($org, fn () => app(ForecastService::class)->forecast('revenue', 3));
    // Sep and Oct are complete; November is in progress → only 2 months → not enough.
    expect($f['months'])->toBe(2)->and($f['forecast'])->toBe([])->and($f['message'])->toContain('at least 3');

    [$fixtureOrg] = seedFixture(); // Sep + Oct 2026 data; no seasonal method without 24 months
    $g = asTenant($fixtureOrg, fn () => app(ForecastService::class)->forecast('revenue', 3, 'seasonal_naive'));
    expect($g['message'])->not->toBeNull();
});

it('shows insights to every member but lets only analysts and up run a scan', function () {
    [$org] = seedDailySales();
    $viewer = User::factory()->create();
    Membership::query()->create(['organization_id' => $org->id, 'user_id' => $viewer->id, 'role' => Role::Viewer, 'joined_at' => now()]);
    $analyst = User::factory()->create();
    Membership::query()->create(['organization_id' => $org->id, 'user_id' => $analyst->id, 'role' => Role::Analyst, 'joined_at' => now()]);
    $params = ['organization' => $org->slug];

    $this->actingAs($viewer)->get(route('insights.index', $params))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('insights/Index')->where('canScan', false)->has('anomalies', 0));
    $this->actingAs($viewer)->post(route('insights.scan', $params))->assertForbidden();

    $this->actingAs($analyst)->post(route('insights.scan', $params))->assertRedirect();
    $this->actingAs($analyst)->get(route('insights.index', $params))
        ->assertInertia(fn (AssertableInertia $p) => $p->where('anomalies.0.date', '2026-11-05')->where('anomalies.0.severity', 'critical')
            ->where('anomalies.0.polarity', 'up_good')
            // Regression: a bare UTC string was read as local time by browsers ("5 hours ago" right after a scan).
            ->where('lastScan', fn ($v) => str_ends_with((string) $v, '+00:00')));

    $this->actingAs($analyst)->get(route('insights.index', [...$params, 'forecast' => 'raw_sql']))->assertSessionHasErrors('forecast');
});

it('scans every organization from the nightly command', function () {
    [$org] = seedDailySales();
    [, $empty] = memberOf();

    $this->artisan('insights:detect-anomalies')->assertSuccessful();

    expect(asTenant($org, fn () => Anomaly::query()->count()))->toBeGreaterThan(0)
        ->and(asTenant($empty, fn () => Anomaly::query()->count()))->toBe(0);
});
