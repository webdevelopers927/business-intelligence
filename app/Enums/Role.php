<?php

namespace App\Enums;

/**
 * Organization membership role. Stored on organization_user.role.
 *
 * The permission matrix lives here (see docs/ARCHITECTURE.md §5) so that it is
 * versioned with the code that enforces it.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Analyst = 'analyst';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Administrator',
            self::Analyst => 'Analyst',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full access, including billing and ownership transfer.',
            self::Admin => 'Manages members, settings, data sources and analytics.',
            self::Analyst => 'Imports data, builds dashboards, metrics, reports and alerts.',
            self::Viewer => 'Views shared dashboards and reports, applies filters, exports.',
        };
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Admin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $p) => ! in_array($p, [Permission::BillingView, Permission::MembersManageOwners], true),
            )),
            self::Analyst => [
                Permission::SourcesView,
                Permission::ImportsCreate,
                Permission::MetricsManage,
                Permission::DashboardsCreate,
                Permission::DashboardsView,
                Permission::ReportsCreate,
                Permission::ReportsExport,
                Permission::AlertsManage,
                Permission::AiUse,
            ],
            self::Viewer => [
                Permission::DashboardsView,
                Permission::ReportsExport,
                Permission::AiUse,
            ],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** Higher rank can manage lower ranks. */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 40,
            self::Admin => 30,
            self::Analyst => 20,
            self::Viewer => 10,
        };
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r) => [
            'value' => $r->value,
            'label' => $r->label(),
            'description' => $r->description(),
        ], self::cases());
    }
}
