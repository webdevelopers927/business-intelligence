<?php

namespace App\Domain\Organizations;

use App\Domain\Audit\AuditLogger;
use App\Enums\Industry;
use App\Enums\OrganizationSize;
use App\Enums\Role;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrganizationService
{
    /** Slugs that would be confusing or collide with top-level paths. */
    private const RESERVED_SLUGS = ['app', 'api', 'admin', 'settings', 'new', 'onboarding', 'login', 'register'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(User $owner, string $name, Industry $industry, OrganizationSize $size): Organization
    {
        return DB::transaction(function () use ($owner, $name, $industry, $size): Organization {
            $organization = Organization::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'industry' => $industry,
                'size' => $size,
                'onboarded_at' => now(),
            ]);

            Membership::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
                'role' => Role::Owner,
                'joined_at' => now(),
            ]);

            $owner->forceFill(['current_organization_id' => $organization->id])->save();

            $this->audit->record('organization.created', $organization, ['name' => $name], $organization->id, $owner->id);

            return $organization;
        });
    }

    /**
     * @param  array{name?: string, industry?: Industry, size?: OrganizationSize, timezone?: string, currency?: string, fiscal_year_start_month?: int}  $attributes
     */
    public function update(Organization $organization, array $attributes): Organization
    {
        return DB::transaction(function () use ($organization, $attributes): Organization {
            $organization->fill($attributes);
            $changed = array_keys($organization->getDirty());

            if ($changed !== []) {
                $organization->save();
                $this->audit->record('organization.settings_updated', $organization, ['changed' => $changed], $organization->id);
            }

            return $organization;
        });
    }

    public function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 40, '') ?: 'org';

        if (in_array($base, self::RESERVED_SLUGS, true) || strlen($base) < 3) {
            $base = Str::limit($base.'-org', 40, '');
        }

        $slug = $base;
        $suffix = 2;

        while (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
