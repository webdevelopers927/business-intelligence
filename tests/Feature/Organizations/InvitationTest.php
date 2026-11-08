<?php

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\OrganizationInvitation;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/**
 * Invite through the real endpoint and capture the plain token from the
 * outgoing notification (only its hash is stored).
 */
function inviteAndCaptureToken($test, User $inviter, string $slug, string $email, string $role = 'analyst'): string
{
    Notification::fake();
    $test->actingAs($inviter)
        ->post(route('settings.invitations.store', ['organization' => $slug]), ['email' => $email, 'role' => $role])
        ->assertRedirect();

    $token = null;
    $capture = function (OrganizationInvitation $n) use (&$token) {
        $token = basename(parse_url($n->toArray(new AnonymousNotifiable)['url'], PHP_URL_PATH));

        return true;
    };

    $existing = User::query()->where('email', $email)->first();
    $existing
        ? Notification::assertSentTo($existing, OrganizationInvitation::class, $capture)
        : Notification::assertSentOnDemand(OrganizationInvitation::class, $capture);

    return $token;
}

it('invites by email and stores only a hash of the token', function () {
    [$owner, $org] = memberOf(Role::Owner);

    $token = inviteAndCaptureToken($this, $owner, $org->slug, 'New.Hire@Example.test');

    $invitation = asTenant($org, fn () => Invitation::query()->sole());
    expect($invitation->email)->toBe('new.hire@example.test')
        ->and($invitation->role)->toBe(Role::Analyst)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toContain($token);
});

it('lets the invitee register and accept, joining with the invited role', function () {
    [$owner, $org] = memberOf(Role::Owner);
    $token = inviteAndCaptureToken($this, $owner, $org->slug, 'joiner@example.test', 'viewer');
    auth()->logout();

    // Opening the link while signed out remembers the invitation…
    $this->get(route('invitations.show', $token))
        ->assertInertia(fn ($page) => $page->component('invitations/Accept')->where('invitation.email', 'joiner@example.test'))
        ->assertSessionHas('pending_invitation', $token);

    // …registration lands on /app, which routes back to the invitation.
    $this->post('/register', [
        'name' => 'Joiner', 'email' => 'joiner@example.test',
        'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
    ]);
    $this->get('/app')->assertRedirect(route('invitations.show', $token));

    $this->post(route('invitations.accept', $token))
        ->assertRedirect(route('dashboard', ['organization' => $org->slug]));

    $joiner = User::query()->where('email', 'joiner@example.test')->sole();
    expect($joiner->membershipIn($org)?->role)->toBe(Role::Viewer);

    // Single use.
    $this->post(route('invitations.accept', $token))->assertSessionHasErrors('invitation');
});

it('refuses acceptance from a different account', function () {
    [$owner, $org] = memberOf(Role::Owner);
    $token = inviteAndCaptureToken($this, $owner, $org->slug, 'intended@example.test');
    $someoneElse = User::factory()->create(['email' => 'other@example.test']);

    $this->actingAs($someoneElse)->post(route('invitations.accept', $token))->assertSessionHasErrors('invitation');

    expect($someoneElse->membershipIn($org))->toBeNull();
});

it('refuses an expired invitation', function () {
    [$owner, $org] = memberOf(Role::Owner);
    $token = inviteAndCaptureToken($this, $owner, $org->slug, 'late@example.test');
    $this->travel(8)->days();
    $late = User::factory()->create(['email' => 'late@example.test']);

    $this->actingAs($late)->post(route('invitations.accept', $token))->assertSessionHasErrors('invitation');
    $this->get(route('invitations.show', $token))->assertInertia(fn ($page) => $page->where('invitation', null));
});

it('notifies existing users in-app as well as by email', function () {
    [$owner, $org] = memberOf(Role::Owner);
    $existing = User::factory()->create(['email' => 'known@example.test']);

    inviteAndCaptureToken($this, $owner, $org->slug, 'known@example.test');

    Notification::assertSentTo($existing, OrganizationInvitation::class, fn ($n, $channels) => count($channels) === 2);
});

it('rejects inviting an existing member and lets only owners invite owners', function () {
    [$admin, $org] = memberOf(Role::Admin);
    [$member] = memberOf(Role::Viewer, $org);

    $this->actingAs($admin)
        ->post(route('settings.invitations.store', ['organization' => $org->slug]), ['email' => $member->email, 'role' => 'analyst'])
        ->assertSessionHasErrors('email');

    $this->actingAs($admin)
        ->post(route('settings.invitations.store', ['organization' => $org->slug]), ['email' => 'boss@example.test', 'role' => 'owner'])
        ->assertForbidden();
});

it('forbids analysts and viewers from inviting', function (Role $role) {
    [$user, $org] = memberOf($role);

    $this->actingAs($user)
        ->post(route('settings.invitations.store', ['organization' => $org->slug]), ['email' => 'x@example.test', 'role' => 'viewer'])
        ->assertForbidden();
})->with([Role::Analyst, Role::Viewer]);
