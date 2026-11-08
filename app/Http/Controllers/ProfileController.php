<?php

namespace App\Http\Controllers;

use App\Enums\Theme;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profile page. Name/email and password updates are handled by Fortify's
 * routes (user-profile-information.update, user-password.update); this
 * controller renders the page and stores the theme preference.
 */
final class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('profile/Edit', [
            'profile' => ['name' => $user->name, 'email' => $user->email, 'theme' => $user->theme->value],
            'backUrl' => $user->landingOrganization() ? route('dashboard', ['organization' => $user->landingOrganization()->slug]) : null,
        ]);
    }

    public function updateTheme(Request $request): RedirectResponse
    {
        $validated = $request->validate(['theme' => ['required', Rule::enum(Theme::class)]]);

        /** @var User $user */
        $user = $request->user();
        $user->update(['theme' => $validated['theme']]);

        return back();
    }
}
