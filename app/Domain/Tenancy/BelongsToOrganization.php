<?php

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned.
 *
 * - Every query is constrained to the current organization (fails closed).
 * - New records get organization_id from the context; an explicit value that
 *   differs from the context is rejected rather than silently accepted.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $explicit = $model->getAttribute('organization_id');

            if (! $context->has()) {
                if ($context->isBypassed() && $explicit !== null) {
                    return;
                }

                throw new TenantNotResolved('Cannot create '.class_basename($model).' without a tenant context.');
            }

            if ($explicit !== null && (int) $explicit !== $context->id()) {
                throw new TenantNotResolved('Refusing to create '.class_basename($model).' for a different organization.');
            }

            $model->setAttribute('organization_id', $context->id());
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
