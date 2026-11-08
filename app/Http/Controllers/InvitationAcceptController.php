<?php

namespace App\Http\Controllers;

use App\Domain\Organizations\InvitationService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class InvitationAcceptController extends Controller
{
    public function show(Request $request, string $token, InvitationService $invitations): Response
    {
        $invitation = $invitations->findPending($token);
        /** @var User|null $user */
        $user = $request->user();

        if ($invitation !== null && $user === null) {
            // Remember the invitation across register/login; /app picks it up.
            $request->session()->put('pending_invitation', $token);
        }

        return Inertia::render('invitations/Accept', [
            'token' => $token,
            'invitation' => $invitation === null ? null : [
                'organization' => $invitation->organization?->name,
                'email' => $invitation->email,
                'role' => $invitation->role->label(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            'emailMatches' => $invitation !== null && $user !== null
                && Str::lower($user->email) === $invitation->email,
        ]);
    }

    public function store(Request $request, string $token, InvitationService $invitations): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = $invitations->accept($token, $user);

        $request->session()->forget('pending_invitation');
        $this->toast("You joined {$organization->name}.");

        return redirect()->route('dashboard', ['organization' => $organization->slug]);
    }
}
