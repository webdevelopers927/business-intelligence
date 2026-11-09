<?php

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Report;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Notifications\ReportReady;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/** @return array{0: User, 1: Organization, 2: array<string, int>} */
function reportFixture(Role $role = Role::Analyst): array
{
    [$org, $ids] = seedFixture();
    $user = User::factory()->create();
    Membership::query()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

    return [$user, $org, $ids];
}

function octoberParams(array $overrides = []): array
{
    return array_replace_recursive(['range' => ['preset' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']], $overrides);
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 9)->setTime(10, 0));
    Storage::fake('local');
});

it('previews a branch comparison with real totals, sorted on request', function () {
    [$user, $org] = reportFixture();

    $res = $this->actingAs($user)->postJson(route('reports.preview', ['organization' => $org->slug]), [
        'type' => 'branch_comparison',
        'parameters' => octoberParams(['sort' => ['column' => 'revenue', 'direction' => 'desc']]),
    ])->assertOk();

    $rows = collect($res->json('data.rows'))->keyBy('branch');
    expect((float) $rows['Lahore']['revenue'])->toBe(250.0)
        ->and((float) $rows['Lahore']['gross_profit'])->toBe(110.0)
        ->and((float) $rows['Karachi']['revenue'])->toBe(90.0)
        ->and($res->json('data.rows.0.branch'))->toBe('Lahore')
        ->and($res->json('data.period'))->toContain('Oct');

    $asc = $this->actingAs($user)->postJson(route('reports.preview', ['organization' => $org->slug]), [
        'type' => 'branch_comparison',
        'parameters' => octoberParams(['columns' => ['branch', 'revenue'], 'sort' => ['column' => 'revenue', 'direction' => 'asc']]),
    ])->assertOk();

    expect(array_column($asc->json('data.columns'), 'key'))->toBe(['branch', 'revenue'])
        ->and(array_keys($asc->json('data.rows.0')))->toContain('branch', 'revenue');
    $revenues = array_map('floatval', array_column($asc->json('data.rows'), 'revenue'));
    $sorted = $revenues;
    sort($sorted);
    expect($revenues)->toBe($sorted);
});

it('lists every transaction in the period, including refunds, in sales performance', function () {
    [$user, $org] = reportFixture();

    $res = $this->actingAs($user)->postJson(route('reports.preview', ['organization' => $org->slug]), [
        'type' => 'sales_performance',
        'parameters' => octoberParams(),
    ])->assertOk();

    expect($res->json('data.meta.total'))->toBe(3)
        ->and(array_column($res->json('data.rows'), 'reference'))->toEqualCanonicalizing(['T1', 'T2', 'T3']);
});

it('rejects unknown report types and columns are limited to the catalog', function () {
    [$user, $org] = reportFixture();

    $this->actingAs($user)->postJson(route('reports.preview', ['organization' => $org->slug]), ['type' => 'raw_sql', 'parameters' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('type');

    $res = $this->actingAs($user)->postJson(route('reports.preview', ['organization' => $org->slug]), [
        'type' => 'branch_comparison',
        'parameters' => octoberParams(['columns' => ['branch', 'password', 'revenue']]),
    ])->assertOk();
    expect(array_column($res->json('data.columns'), 'key'))->toBe(['branch', 'revenue']);
});

it('exports CSV with a BOM, labelled headers and formula-safe text', function () {
    [$user, $org, $ids] = reportFixture();
    DB::table('products')->where('id', $ids['p1'])->update(['name' => '=HYPERLINK("http://x")']);

    $res = $this->actingAs($user)->postJson(route('reports.export', ['organization' => $org->slug]), [
        'type' => 'product_performance', 'format' => 'csv', 'parameters' => octoberParams(),
    ])->assertOk();

    expect($res->json('data.status'))->toBe('completed');
    $download = $this->actingAs($user)->get($res->json('data.download_url'))->assertOk();
    $csv = $download->streamedContent();

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain('Product')->toContain('Revenue')
        ->and($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toContain(',=HYPERLINK');

    $run = asTenant($org, fn () => ReportRun::query()->sole());
    expect($run->row_count)->toBe(2)->and($run->trigger)->toBe('manual');
    $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'action' => 'report.generated']);
});

it('exports real XLSX and PDF files', function (string $format, string $magic) {
    [$user, $org] = reportFixture();

    $res = $this->actingAs($user)->postJson(route('reports.export', ['organization' => $org->slug]), [
        'type' => 'branch_comparison', 'format' => $format, 'parameters' => octoberParams(),
    ])->assertOk();

    $body = $this->actingAs($user)->get($res->json('data.download_url'))->assertOk()->streamedContent();
    expect(str_starts_with($body, $magic))->toBeTrue();
})->with([['xlsx', 'PK'], ['pdf', '%PDF']]);

it('names downloads in organization time and keeps PDFs small', function () {
    [$user, $org] = reportFixture();

    $url = $this->actingAs($user)->postJson(route('reports.export', ['organization' => $org->slug]), [
        'type' => 'branch_comparison', 'format' => 'pdf', 'parameters' => octoberParams(),
    ])->json('data.download_url');
    $response = $this->actingAs($user)->get($url)->assertOk();

    $local = now()->setTimezone($org->timezone)->format('Ymd-Hi');
    expect($response->headers->get('content-disposition'))->toContain("branch-comparison-{$local}.pdf")
        ->and(strlen($response->streamedContent()))->toBeLessThan(150_000);
});

it('queues large exports and notifies the requester when ready', function () {
    Notification::fake();
    config(['orbit.reports.queue_threshold' => 1]);
    [$user, $org] = reportFixture();

    $this->actingAs($user)->postJson(route('reports.export', ['organization' => $org->slug]), [
        'type' => 'sales_performance', 'format' => 'xlsx', 'parameters' => octoberParams(),
    ])->assertOk()->assertJsonPath('data.download_url', null);

    $run = asTenant($org, fn () => ReportRun::query()->sole());
    expect($run->trigger)->toBe('queued')->and($run->status)->toBe('completed')->and($run->row_count)->toBe(3);
    Notification::assertSentTo($user, ReportReady::class);
});

it('keeps report runs inside their organization', function () {
    [$user, $org] = reportFixture();
    [$other, $otherOrg] = memberOf(Role::Owner);

    $url = $this->actingAs($user)->postJson(route('reports.export', ['organization' => $org->slug]), [
        'type' => 'branch_comparison', 'format' => 'csv', 'parameters' => octoberParams(),
    ])->json('data.download_url');
    $runId = asTenant($org, fn () => ReportRun::query()->sole()->id);

    // Same id through another organization's URL → not found.
    $this->actingAs($other)->get(route('reports.runs.download', ['organization' => $otherOrg->slug, 'run' => $runId]))->assertNotFound();
    // Not a member of the owning organization → not found.
    $this->actingAs($other)->get($url)->assertNotFound();
});

it('lets viewers preview and export but not save reports', function () {
    [$viewer, $org] = reportFixture(Role::Viewer);

    $this->actingAs($viewer)->get(route('reports.index', ['organization' => $org->slug]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('reports/Index')->where('canCreate', false));
    $this->actingAs($viewer)->postJson(route('reports.preview', ['organization' => $org->slug]), ['type' => 'branch_comparison', 'parameters' => octoberParams()])->assertOk();
    $this->actingAs($viewer)->post(route('reports.store', ['organization' => $org->slug]), ['name' => 'Mine', 'type' => 'branch_comparison', 'parameters' => []])->assertForbidden();
});

it('saves reports; only the author or an admin can change them', function () {
    [$author, $org] = reportFixture();
    [$colleague] = memberOf(Role::Analyst, $org);
    [$admin] = memberOf(Role::Admin, $org);

    $this->actingAs($author)->post(route('reports.store', ['organization' => $org->slug]), [
        'name' => 'October scorecard', 'type' => 'branch_comparison', 'parameters' => octoberParams(['columns' => ['branch', 'revenue']]),
    ])->assertRedirect();

    $report = asTenant($org, fn () => Report::query()->sole());
    expect($report->parameters['columns'])->toBe(['branch', 'revenue'])
        ->and($report->parameters['range'])->toBe(['preset' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']);

    $params = ['organization' => $org->slug, 'report' => $report->id];
    $this->actingAs($colleague)->patch(route('reports.update', $params), ['name' => 'Hijacked', 'parameters' => []])->assertForbidden();
    $this->actingAs($colleague)->get(route('reports.show', $params))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('can.save', false)->where('can.saveAs', true));

    $this->actingAs($admin)->patch(route('reports.update', $params), ['name' => 'Branch scorecard', 'parameters' => octoberParams()])->assertRedirect();
    expect(asTenant($org, fn () => $report->fresh()->name))->toBe('Branch scorecard');

    [, $otherOrg] = memberOf();
    $this->actingAs($author)->get(route('reports.show', ['organization' => $otherOrg->slug, 'report' => $report->id]))->assertNotFound();
});

it('schedules a report for organization members only and delivers it when due', function () {
    Notification::fake();
    [$author, $org] = reportFixture();
    [$outsider] = memberOf();

    $report = asTenant($org, fn () => Report::query()->create(['name' => 'Scorecard', 'type' => 'branch_comparison', 'parameters' => ['range' => ['preset' => 'last_month']], 'created_by' => $author->id]));
    $params = ['organization' => $org->slug, 'report' => $report->id];
    $schedule = ['frequency' => 'weekly', 'day_of_week' => 1, 'run_at' => '08:00', 'format' => 'csv', 'is_active' => true];

    $this->actingAs($author)->put(route('reports.schedule', $params), [...$schedule, 'recipients' => [$outsider->id]])->assertSessionHasErrors('recipients.0');

    $this->actingAs($author)->put(route('reports.schedule', $params), [...$schedule, 'recipients' => [$author->id]])->assertSessionHasNoErrors();
    $saved = asTenant($org, fn () => ReportSchedule::query()->sole());
    // Monday 9 Nov 10:00 has passed 08:00, so the next run is Monday 16 Nov 08:00 (organization time).
    expect($saved->next_run_at->setTimezone($org->timezone)->format('Y-m-d H:i'))->toBe('2026-11-16 08:00');

    $this->artisan('reports:run-scheduled')->assertSuccessful();
    expect(asTenant($org, fn () => ReportRun::query()->count()))->toBe(0);

    $this->travelTo($saved->next_run_at->addMinute());
    $this->artisan('reports:run-scheduled')->assertSuccessful();

    $run = asTenant($org, fn () => ReportRun::query()->sole());
    expect($run->trigger)->toBe('schedule')->and($run->status)->toBe('completed')
        ->and($run->parameters['range']['preset'])->toBe('last_month');
    Notification::assertSentTo($author, ReportReady::class);
    expect(asTenant($org, fn () => $saved->fresh()->next_run_at->setTimezone($org->timezone)->format('Y-m-d H:i')))->toBe('2026-11-23 08:00');
});
