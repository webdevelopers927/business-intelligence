<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('shows the onboarding form with industry and size options', function () {
    $this->actingAs(User::factory()->create())
        ->get('/onboarding/organization')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('onboarding/CreateOrganization')
            ->has('industries', 7)
            ->has('sizes', 5)
            ->where('isAdditional', false));
});

it('creates the organization with the creator as owner and lands on its dashboard', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/onboarding/organization', [
        'name' => 'Nexora Group',
        'industry' => 'distribution',
        'size' => '51_200',
    ]);

    $org = Organization::query()->sole();
    $response->assertRedirect(route('dashboard', ['organization' => 'nexora-group']));

    expect($org->slug)->toBe('nexora-group')
        ->and($org->currency)->toBe('PKR')
        ->and($user->fresh()->current_organization_id)->toBe($org->id)
        ->and($user->membershipIn($org)?->role)->toBe(Role::Owner)
        ->and(AuditLog::query()->where('action', 'organization.created')->exists())->toBeTrue();
});

it('generates unique slugs and avoids reserved words', function () {
    $user = User::factory()->create();
    $payload = ['industry' => 'retail', 'size' => '1_10'];

    $this->actingAs($user)->post('/onboarding/organization', ['name' => 'Acme', ...$payload]);
    $this->actingAs($user)->post('/onboarding/organization', ['name' => 'Acme', ...$payload]);
    $this->actingAs($user)->post('/onboarding/organization', ['name' => 'Settings', ...$payload]);

    expect(Organization::query()->orderBy('id')->pluck('slug')->all())
        ->toBe(['acme', 'acme-2', 'settings-org']);
});

it('validates the onboarding input', function () {
    $this->actingAs(User::factory()->create())
        ->post('/onboarding/organization', ['name' => '', 'industry' => 'mining', 'size' => 'huge'])
        ->assertSessionHasErrors(['name', 'industry', 'size']);

    expect(Organization::query()->count())->toBe(0);
});

it('shows the setup checklist on a new organization dashboard', function () {
    [$user, $org] = memberOf(Role::Owner);

    $this->actingAs($user)
        ->get(route('dashboard', ['organization' => $org->slug]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/Overview')
            ->where('steps.0.done', true)
            ->where('steps.1.done', false)
            ->where('tenant.slug', $org->slug)
            ->where('tenant.role', 'owner'));
});
