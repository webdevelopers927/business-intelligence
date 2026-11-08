<?php

namespace App\Domain\Tenancy;

/**
 * Implemented by queued jobs that act on tenant data. Combine with the
 * RestoreTenantContext job middleware, which sets the context around handle().
 */
interface TenantAware
{
    public function organizationId(): int;
}
