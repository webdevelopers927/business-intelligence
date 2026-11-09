<?php

use App\Domain\Alerts\AlertEvaluator;
use App\Enums\Role;
use App\Models\Alert;
use App\Models\AlertExecution;
use App\Models\DataSource;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AlertTriggered;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * Fixture revenue (seedFixture): Sep 10 = 100; Oct 5 + Oct 6 = 340.
 * Evaluated "now" = 1 Nov 2026, so the 30-day window is Oct 2 – Oct 31
 * and the preceding window is Sep 2 – Oct 1.
 */

/** @return array{0: User, 1: Organization} */
function alertFixture(): array
{
    [$org] = seedFixture();
    $user = User::factory()->create();
    Membership::query()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => Role::Analyst, 'joined_at' => now()]);

    return [$user, $org];
}

function makeAlert(Organization $org, User $user, array $attributes): Alert
{
    return asTenant($org, fn () => Alert::query()->create([
        'name' => 'Test alert', 'kind' => 'metric', 'metric_key' => 'revenue', 'condition' => 'below', 'threshold' => 500,
        'window' => 'month', 'frequency' => 'daily', 'recipients' => [$user->id], 'is_active' => true, 'created_by' => $user->id,
        ...$attributes,
    ]));
}

function evaluateAt(Organization $org, Alert $alert, string $at = '2026-11-01 09:00'): AlertExecution
{
    return asTenant($org, fn () => app(AlertEvaluator::class)->evaluate($alert->fresh(), CarbonImmutable::parse($at, $org->timezone)));
}

beforeEach(fn () => Notification::fake());

it('notifies once when a threshold is crossed and records the resolution', function () {
    [$user, $org] = alertFixture();
    $alert = makeAlert($org, $user, []);

    $first = evaluateAt($org, $alert);
    expect($first->outcome)->toBe('triggered')->and($first->notified)->toBeTrue()
        ->and((float) $first->observed_value)->toBe(340.0)
        ->and($first->message)->toContain('Revenue')->toContain('falls below');
    Notification::assertSentToTimes($user, AlertTriggered::class, 1);

    $second = evaluateAt($org, $alert, '2026-11-01 10:00');
    expect($second->outcome)->toBe('triggered')->and($second->notified)->toBeFalse();
    Notification::assertSentToTimes($user, AlertTriggered::class, 1);

    asTenant($org, fn () => $alert->fresh()->forceFill(['threshold' => 100])->save());
    $third = evaluateAt($org, $alert, '2026-11-01 11:00');
    expect($third->outcome)->toBe('resolved')->and(asTenant($org, fn () => $alert->fresh()->state))->toBe('ok');

    asTenant($org, fn () => $alert->fresh()->forceFill(['threshold' => 1000])->save());
    expect(evaluateAt($org, $alert, '2026-11-01 12:00')->notified)->toBeTrue();
    Notification::assertSentToTimes($user, AlertTriggered::class, 2);
});

it('compares percentage changes against the preceding window', function () {
    [$user, $org] = alertFixture();

    $rise = evaluateAt($org, makeAlert($org, $user, ['condition' => 'rises_by_pct', 'threshold' => 100]));
    expect($rise->outcome)->toBe('triggered')->and((float) $rise->observed_value)->toBe(240.0)
        ->and($rise->message)->toContain('rose 240.0%');

    $drop = evaluateAt($org, makeAlert($org, $user, ['condition' => 'drops_by_pct', 'threshold' => 10]));
    expect($drop->outcome)->toBe('ok');
});

it('applies the alert branch filter', function () {
    [$user, $org] = alertFixture();
    $khi = DB::table('branches')->where('organization_id', $org->id)->where('code', 'KHI')->value('id');

    // Karachi alone made 90 in October.
    $alert = makeAlert($org, $user, ['condition' => 'below', 'threshold' => 100, 'filters' => ['branches' => [$khi]]]);
    expect(evaluateAt($org, $alert)->outcome)->toBe('triggered');
});

it('detects failed imports since the previous check', function () {
    [$user, $org] = alertFixture();
    $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00'));
    $alert = makeAlert($org, $user, ['kind' => 'import_failed', 'metric_key' => null, 'condition' => null, 'threshold' => null, 'window' => null]);

    expect(evaluateAt($org, $alert)->outcome)->toBe('ok');

    DB::table('imports')->insert([
        'organization_id' => $org->id, 'dataset_type' => 'sales', 'original_filename' => 'october.csv', 'disk' => 'local', 'path' => 'x',
        'size_bytes' => 10, 'checksum_sha256' => str_repeat('a', 64), 'headers' => '[]', 'status' => 'failed',
        'completed_at' => '2026-11-01 09:30:00', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $execution = evaluateAt($org, $alert, '2026-11-01 10:00');
    expect($execution->outcome)->toBe('triggered')->and($execution->message)->toContain('october.csv');
});

it('flags active data sources that stopped synchronizing', function () {
    [$user, $org] = alertFixture();
    $alert = makeAlert($org, $user, ['kind' => 'source_stale', 'metric_key' => null, 'condition' => null, 'threshold' => 24, 'window' => null]);
    $source = fn (string $name, string $status, string $synced) => asTenant($org, fn () => DataSource::query()
        ->create(['name' => $name, 'type' => 'csv', 'status' => $status])->forceFill(['last_synced_at' => $synced])->save());
    $source('POS feed', 'active', '2026-10-30 09:00:00');
    $source('Paused feed', 'disabled', '2026-01-01 00:00:00');

    $execution = evaluateAt($org, $alert);
    expect($execution->outcome)->toBe('triggered')->and($execution->message)->toContain('POS feed')->not->toContain('Paused feed');
});

it('evaluates only due, active alerts across organizations', function () {
    [$userA, $orgA] = alertFixture();
    [$userB, $orgB] = alertFixture();

    $dueA = makeAlert($orgA, $userA, ['next_evaluation_at' => now()->subMinute()]);
    $paused = makeAlert($orgA, $userA, ['is_active' => false, 'next_evaluation_at' => now()->subMinute()]);
    $notDue = makeAlert($orgA, $userA, ['next_evaluation_at' => now()->addHour()]);
    $dueB = makeAlert($orgB, $userB, ['next_evaluation_at' => null]);

    $this->artisan('alerts:evaluate')->expectsOutput('Evaluated 2 alert(s).')->assertSuccessful();

    $evaluated = fn (Organization $org, Alert $a) => asTenant($org, fn () => $a->fresh()->last_evaluated_at !== null);
    expect($evaluated($orgA, $dueA))->toBeTrue()
        ->and($evaluated($orgB, $dueB))->toBeTrue()
        ->and($evaluated($orgA, $paused))->toBeFalse()
        ->and($evaluated($orgA, $notDue))->toBeFalse();
    // Each organization's recipients only hear about their own alert.
    Notification::assertNotSentTo($userB, AlertTriggered::class, fn ($n) => $n->organizationId() === $orgA->id);
});

it('manages alerts over HTTP with tenant-scoped recipients', function () {
    [$user, $org] = alertFixture();
    [$outsider, $otherOrg] = memberOf();
    $base = ['name' => 'Revenue watch', 'kind' => 'metric', 'metric_key' => 'revenue', 'condition' => 'below', 'threshold' => 1000, 'window' => 'week', 'frequency' => 'daily'];

    $this->actingAs($user)->post(route('alerts.store', ['organization' => $org->slug]), [...$base, 'recipients' => [$outsider->id]])->assertSessionHasErrors('recipients.0');
    $this->actingAs($user)->post(route('alerts.store', ['organization' => $org->slug]), [...$base, 'condition' => 'drops_by_pct', 'threshold' => -5, 'recipients' => [$user->id]])->assertSessionHasErrors('threshold');
    $this->actingAs($user)->post(route('alerts.store', ['organization' => $org->slug]), [...$base, 'recipients' => [$user->id]])->assertRedirect();

    $alert = asTenant($org, fn () => Alert::query()->sole());
    $params = ['organization' => $org->slug, 'alert' => $alert->id];
    $this->actingAs($user)->get(route('alerts.show', $params))->assertOk();
    $this->actingAs($user)->post(route('alerts.evaluate', $params))->assertRedirect();
    expect(asTenant($org, fn () => $alert->executions()->count()))->toBe(1);

    $this->actingAs($user)->post(route('alerts.toggle', $params));
    expect(asTenant($org, fn () => $alert->fresh()->is_active))->toBeFalse();

    $this->actingAs($outsider)->get(route('alerts.show', ['organization' => $otherOrg->slug, 'alert' => $alert->id]))->assertNotFound();

    [$viewer] = memberOf(Role::Viewer, $org);
    $this->actingAs($viewer)->get(route('alerts.index', ['organization' => $org->slug]))->assertForbidden();
});
