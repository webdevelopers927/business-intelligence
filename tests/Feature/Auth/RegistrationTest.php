<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('renders the registration page, prefilled from an invitation link', function () {
    $this->get('/register?email=amina%40example.test')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Register')
            ->where('email', 'amina@example.test'));
});

it('registers a user and continues to organization onboarding', function () {
    $this->post('/register', [
        'name' => 'Amina Qureshi',
        'email' => 'Amina@Example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect('/app');

    $user = User::query()->sole();
    expect($user->email)->toBe('amina@example.test');
    $this->assertAuthenticatedAs($user);

    $this->get('/app')->assertRedirect(route('onboarding.create'));
});

it('rejects a duplicate email and mismatched confirmation', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->post('/register', [
        'name' => 'Someone',
        'email' => 'taken@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'something-else',
    ])->assertSessionHasErrors(['email', 'password']);

    expect(User::query()->count())->toBe(1);
    $this->assertGuest();
});
