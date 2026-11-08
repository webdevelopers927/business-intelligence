<?php

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Closure;

/**
 * Job middleware: restores the tenant context for a TenantAware job so that
 * tenant scopes apply inside the worker exactly as they do in a request.
 */
final class RestoreTenantContext
{
    public function handle(TenantAware $job, Closure $next): mixed
    {
        $organization = Organization::query()->findOrFail($job->organizationId());

        return app(TenantContext::class)->runAs($organization, fn () => $next($job));
    }
}
