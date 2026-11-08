<?php

namespace App\Domain\Tenancy;

use App\Enums\Role;
use App\Models\Organization;

/**
 * Holds the organization the current request or job acts for.
 *
 * Registered as a *scoped* singleton, so it is reset between requests and
 * queued jobs. Tenant-owned models read it through OrganizationScope, which
 * fails closed: querying a tenant model without a tenant throws.
 */
final class TenantContext
{
    private ?Organization $organization = null;

    private ?Role $role = null;

    private int $bypassDepth = 0;

    public function set(Organization $organization, ?Role $role = null): void
    {
        $this->organization = $organization;
        $this->role = $role;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->role = null;
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    public function organization(): Organization
    {
        return $this->organization ?? throw new TenantNotResolved;
    }

    public function id(): int
    {
        return $this->organization()->getKey();
    }

    /** The acting member's role. Null for system work (jobs, scheduler). */
    public function role(): ?Role
    {
        return $this->role;
    }

    /**
     * Run a callback as the given organization, restoring the previous
     * context afterwards (also on exceptions). Use for jobs, commands, seeders.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Organization $organization, callable $callback, ?Role $role = null): mixed
    {
        [$previousOrg, $previousRole] = [$this->organization, $this->role];
        $this->set($organization, $role);

        try {
            return $callback();
        } finally {
            $this->organization = $previousOrg;
            $this->role = $previousRole;
        }
    }

    /**
     * Explicitly disable tenant scoping for cross-tenant system work
     * (e.g. the alert scheduler selecting due alerts). Grep-able on purpose.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function bypass(callable $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }
}
