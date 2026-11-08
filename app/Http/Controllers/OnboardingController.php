<?php

namespace App\Http\Controllers;

use App\Domain\Organizations\OrganizationService;
use App\Enums\Industry;
use App\Enums\OrganizationSize;
use App\Http\Requests\StoreOrganizationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OnboardingController extends Controller
{
    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('onboarding/CreateOrganization', [
            'industries' => Industry::options(),
            'sizes' => OrganizationSize::options(),
            'isAdditional' => $user->organizations()->exists(),
        ]);
    }

    public function store(StoreOrganizationRequest $request, OrganizationService $organizations): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $organization = $organizations->create(
            $user,
            $request->string('name')->trim()->value(),
            $request->enum('industry', Industry::class),
            $request->enum('size', OrganizationSize::class),
        );

        $this->toast("{$organization->name} is ready.");

        return redirect()->route('dashboard', ['organization' => $organization->slug]);
    }
}
