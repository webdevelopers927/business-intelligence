<?php

use App\Domain\Audit\Redactor;
use App\Enums\Role;
use App\Enums\Theme;
use App\Models\AuditLog;

it('updates organization settings and audits which fields changed', function () {
    [$admin, $org] = memberOf(Role::Admin);

    $this->actingAs($admin)
        ->patch(route('settings.organization.update', ['organization' => $org->slug]), [
            'name' => 'Nexora Group',
            'industry' => 'distribution',
            'size' => '51_200',
            'timezone' => 'Asia/Karachi',
            'currency' => 'USD',
            'fiscal_year_start_month' => 7,
        ])
        ->assertRedirect()
        ->assertSessionHas('toast');

    $org->refresh();
    expect($org->name)->toBe('Nexora Group')->and($org->currency)->toBe('USD');

    $log = AuditLog::query()->where('action', 'organization.settings_updated')->sole();
    expect($log->organization_id)->toBe($org->id)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->metadata['changed'])->toContain('name', 'industry', 'size', 'currency');
});

it('validates organization settings', function () {
    [$owner, $org] = memberOf(Role::Owner);

    $this->actingAs($owner)
        ->patch(route('settings.organization.update', ['organization' => $org->slug]), [
            'name' => '', 'industry' => 'retail', 'size' => '1_10',
            'timezone' => 'Mars/Olympus', 'currency' => 'pk', 'fiscal_year_start_month' => 13,
        ])
        ->assertSessionHasErrors(['name', 'timezone', 'currency', 'fiscal_year_start_month']);
});

it('stores the theme preference', function () {
    [$user] = memberOf();

    $this->actingAs($user)->patch(route('profile.theme'), ['theme' => 'dark'])->assertRedirect();
    expect($user->fresh()->theme)->toBe(Theme::Dark);

    $this->actingAs($user)->patch(route('profile.theme'), ['theme' => 'neon'])->assertSessionHasErrors('theme');
});

it('updates profile information through Fortify', function () {
    [$user] = memberOf();

    $this->actingAs($user)
        ->put('/user/profile-information', ['name' => 'Bilal Ahmed', 'email' => 'bilal@example.test'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->only('name', 'email'))->toBe(['name' => 'Bilal Ahmed', 'email' => 'bilal@example.test']);
});

it('redacts secrets recursively before auditing', function () {
    $clean = Redactor::redact([
        'host' => 'db.internal',
        'password' => 'hunter2',
        'nested' => ['api_key' => 'sk-123', 'Authorization' => 'Bearer x', 'port' => 5432],
        'client_secret' => 'abc',
    ]);

    expect($clean)->toBe([
        'host' => 'db.internal',
        'password' => Redactor::MASK,
        'nested' => ['api_key' => Redactor::MASK, 'Authorization' => Redactor::MASK, 'port' => 5432],
        'client_secret' => Redactor::MASK,
    ]);
});

it('lists inbox notifications for the current organization only', function () {
    [$user, $org] = memberOf();
    [, $other] = memberOf();
    $user->notifications()->createMany([
        ['id' => (string) str()->uuid(), 'type' => 'test', 'organization_id' => $org->id, 'data' => ['title' => 'Mine']],
        ['id' => (string) str()->uuid(), 'type' => 'test', 'organization_id' => $other->id, 'data' => ['title' => 'Other org']],
        ['id' => (string) str()->uuid(), 'type' => 'test', 'organization_id' => null, 'data' => ['title' => 'Personal']],
    ]);

    $titles = $this->actingAs($user)
        ->getJson(route('notifications.index', ['organization' => $org->slug]))
        ->assertOk()
        ->json('data.*.title');

    expect($titles)->toEqualCanonicalizing(['Mine', 'Personal']);

    $this->actingAs($user)->postJson(route('notifications.read', ['organization' => $org->slug]))->assertJsonPath('data.updated', 2);
});
