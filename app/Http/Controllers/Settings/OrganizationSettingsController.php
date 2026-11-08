<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Organizations\OrganizationService;
use App\Domain\Tenancy\TenantContext;
use App\Enums\Industry;
use App\Enums\OrganizationSize;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateOrganizationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class OrganizationSettingsController extends Controller
{
    public function edit(TenantContext $tenant): Response
    {
        Gate::authorize(Permission::OrgSettingsManage->value);
        $org = $tenant->organization();

        return Inertia::render('settings/Organization', [
            'organization' => [
                'name' => $org->name,
                'slug' => $org->slug,
                'industry' => $org->industry->value,
                'size' => $org->size->value,
                'timezone' => $org->timezone,
                'currency' => $org->currency,
                'fiscal_year_start_month' => $org->fiscal_year_start_month,
                'created_at' => $org->created_at?->toIso8601String(),
            ],
            'industries' => Industry::options(),
            'sizes' => OrganizationSize::options(),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(UpdateOrganizationRequest $request, TenantContext $tenant, OrganizationService $organizations): RedirectResponse
    {
        $organizations->update($tenant->organization(), [
            ...$request->safe()->except(['industry', 'size']),
            'industry' => $request->enum('industry', Industry::class),
            'size' => $request->enum('size', OrganizationSize::class),
        ]);

        $this->toast('Organization settings saved.');

        return back();
    }
}
