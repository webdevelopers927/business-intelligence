<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves /app/{organization}/... into the tenant context.
 *
 * Runs before SubstituteBindings (see bootstrap/app.php), so every implicit
 * route-model binding on the route is already tenant-scoped. Non-members get
 * a 404 — the same response as a non-existent organization — so slugs of
 * other tenants can't be probed.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = $route?->parameter('organization');
        $user = $request->user();

        abort_if(! is_string($slug) || $user === null, 404);

        $organization = Organization::query()->where('slug', $slug)->first();
        $membership = $organization !== null ? $user->membershipIn($organization) : null;

        abort_if($organization === null || $membership === null, 404);

        $this->tenant->set($organization, $membership->role);

        // Controllers read the organization from TenantContext, never from
        // the URL, and route('...') calls get the slug filled in automatically.
        $route->forgetParameter('organization');
        URL::defaults(['organization' => $organization->slug]);

        if ($user->current_organization_id !== $organization->id) {
            $user->forceFill(['current_organization_id' => $organization->id])->saveQuietly();
        }

        return $next($request);
    }
}
