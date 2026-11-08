<?php

namespace App\Http\Controllers\App;

use App\Domain\Dashboards\DashboardService;
use App\Domain\Dashboards\WidgetConfig;
use App\Domain\Dashboards\WidgetDataService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Support\AnalyticsQuery;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Widgets are always resolved through their dashboard (scoped bindings), so
 * another dashboard's widget id is a 404 even within the same organization.
 */
final class DashboardWidgetController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboards,
        private readonly WidgetConfig $config,
    ) {}

    public function store(Request $request, Dashboard $dashboard): RedirectResponse
    {
        Gate::authorize('update', $dashboard);
        /** @var User $user */
        $user = $request->user();

        if ($dashboard->widgets()->count() >= 40) {
            return back()->withErrors(['type' => 'A dashboard can hold up to 40 widgets.']);
        }

        $this->dashboards->addWidget($dashboard, $this->config->normalize($request->all(), $user));
        $this->toast('Widget added.');

        return back();
    }

    public function update(Request $request, Dashboard $dashboard, DashboardWidget $widget): RedirectResponse
    {
        Gate::authorize('update', $dashboard);
        /** @var User $user */
        $user = $request->user();

        $this->dashboards->updateWidget($widget, $this->config->normalize($request->all(), $user));
        $this->toast('Widget updated.');

        return back();
    }

    public function destroy(Dashboard $dashboard, DashboardWidget $widget): RedirectResponse
    {
        Gate::authorize('update', $dashboard);
        $widget->delete();
        $dashboard->touch();
        $this->toast('Widget removed.');

        return back();
    }

    public function data(Request $request, Dashboard $dashboard, DashboardWidget $widget, WidgetDataService $data, TenantContext $tenant): JsonResponse
    {
        Gate::authorize('view', $dashboard);

        try {
            return response()->json(['data' => $data->data($widget, AnalyticsQuery::fromRequest($request, $tenant))]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'widget_config_invalid'], 422);
        }
    }
}
