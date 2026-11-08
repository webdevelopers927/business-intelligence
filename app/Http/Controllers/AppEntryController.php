<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /app — where every sign-in lands. Routes the user to a pending
 * invitation, their last organization, or onboarding.
 */
final class AppEntryController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (is_string($token = $request->session()->pull('pending_invitation'))) {
            return redirect()->route('invitations.show', $token);
        }

        $organization = $user->landingOrganization();

        return $organization === null
            ? redirect()->route('onboarding.create')
            : redirect()->route('dashboard', ['organization' => $organization->slug]);
    }
}
