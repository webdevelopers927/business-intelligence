<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('renders the login page', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login'));
});

it('signs in with valid credentials and lands on the organization dashboard', function () {
    [$user, $org] = memberOf(Role::Analyst);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/app');

    $this->assertAuthenticatedAs($user);
    $this->get('/app')->assertRedirect(route('dashboard', ['organization' => $org->slug]));
});

it('sends a signed-in user without an organization to onboarding', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/app')->assertRedirect(route('onboarding.create'));
});

it('rejects an invalid password', function () {
    $user = User::factory()->create();

    $this->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rate limits repeated failed sign-in attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(429);
});

it('signs out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

it('records successful and failed sign-ins in the audit log without the password', function () {
    [$user, $org] = memberOf();

    $this->post('/login', ['email' => $user->email, 'password' => 'nope']);
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $failed = AuditLog::query()->where('action', 'auth.login_failed')->sole();
    expect($failed->metadata)->toBe(['email' => $user->email]);

    $login = AuditLog::query()->where('action', 'auth.login')->sole();
    expect($login->user_id)->toBe($user->id)
        ->and($login->organization_id)->toBe($org->id);
});

it('sends an already signed-in user from guest pages to their workspace', function () {
    [$user, $org] = memberOf();

    // Regression: these used to 500 (route('dashboard') without {organization}).
    $this->actingAs($user)->get('/login')->assertRedirect('/app');
    $this->actingAs($user)->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/app');
    $this->actingAs($user)->get('/register')->assertRedirect('/app');
    $this->actingAs($user)->get('/forgot-password')->assertRedirect('/app');

    $this->actingAs($user)->get('/app')->assertRedirect(route('dashboard', ['organization' => $org->slug]));
});

it('keeps guests out of the application', function () {
    $this->get('/app')->assertRedirect('/login');
    $this->get('/profile')->assertRedirect('/login');
});
