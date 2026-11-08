<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OrganizationSwitchController extends Controller
{
    public function __invoke(Request $request, string $slug): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = Organization::query()->where('slug', $slug)->first();

        abort_if($organization === null || $user->membershipIn($organization) === null, 404);

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return redirect()->route('dashboard', ['organization' => $organization->slug]);
    }
}
