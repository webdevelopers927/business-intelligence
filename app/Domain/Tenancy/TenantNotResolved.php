<?php

namespace App\Domain\Tenancy;

use LogicException;

/**
 * Thrown when tenant-owned data is accessed without a tenant context.
 * This is a programming error, never a user error: it prevents a missing
 * context from silently turning into an unscoped, cross-tenant query.
 */
final class TenantNotResolved extends LogicException
{
    public function __construct(string $message = 'Tenant-owned data was accessed without a tenant context.')
    {
        parent::__construct($message);
    }
}
