<?php

namespace App\Enums;

/**
 * Organization-level abilities. Registered as Gates in AuthServiceProvider
 * and shared with the frontend (auth.permissions) so the UI can hide actions
 * the server would reject anyway. The server remains authoritative.
 */
enum Permission: string
{
    case OrgSettingsManage = 'org.settings.manage';
    case BillingView = 'org.billing.view';
    case MembersManage = 'members.manage';
    case MembersManageOwners = 'members.manage_owners';
    case SourcesView = 'sources.view';
    case SourcesManage = 'sources.manage';
    case ImportsCreate = 'imports.create';
    case MetricsManage = 'metrics.manage';
    case DashboardsCreate = 'dashboards.create';
    case DashboardsView = 'dashboards.view';
    case ReportsCreate = 'reports.create';
    case ReportsExport = 'reports.export';
    case AlertsManage = 'alerts.manage';
    case AuditView = 'audit.view';
    case AiUse = 'ai.use';
}
