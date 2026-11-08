<?php

namespace App\Http\Controllers\App;

use App\Domain\Tenancy\TenantContext;
use App\Enums\ImportStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\Invitation;
use App\Models\SalesTransaction;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's landing page. Until the organization has imported data
 * it presents the setup checklist; the executive analytics (Phase 4) replace
 * the body once data exists.
 */
final class OverviewController extends Controller
{
    public function __invoke(TenantContext $tenant): Response
    {
        $organization = $tenant->organization();
        $memberCount = $organization->memberships()->count();
        $pendingInvites = Invitation::query()->pending()->count();

        $steps = [
            [
                'key' => 'organization',
                'title' => 'Create your organization',
                'description' => "{$organization->name} is set up with {$organization->currency} as its reporting currency.",
                'done' => true,
                'href' => Gate::allows(Permission::OrgSettingsManage->value) ? route('settings.organization.edit') : null,
                'action' => 'Review settings',
            ],
            [
                'key' => 'team',
                'title' => 'Invite your team',
                'description' => $memberCount > 1 || $pendingInvites > 0
                    ? "{$memberCount} member(s), {$pendingInvites} pending invitation(s)."
                    : 'Bring in analysts and viewers so dashboards have an audience.',
                'done' => $memberCount > 1 || $pendingInvites > 0,
                'href' => Gate::allows(Permission::MembersManage->value) ? route('settings.members.index') : null,
                'action' => 'Invite members',
            ],
        ];

        // Steps for modules that are not yet deployed are omitted rather than
        // shown as dead links.
        if (Route::has('imports.create')) {
            $hasData = Import::query()->whereIn('status', [ImportStatus::Completed, ImportStatus::CompletedWithErrors])->exists()
                || SalesTransaction::query()->exists();

            $steps[] = [
                'key' => 'import',
                'title' => 'Import your first dataset',
                'description' => $hasData
                    ? 'Business data is in place. Analytics are calculated from it.'
                    : 'Upload sales, expenses or inventory as CSV or Excel to populate analytics.',
                'done' => $hasData,
                'href' => Gate::allows(Permission::ImportsCreate->value) ? route('imports.create') : null,
                'action' => 'Start import',
            ];
        }

        return Inertia::render('app/Overview', [
            'steps' => $steps,
        ]);
    }
}
