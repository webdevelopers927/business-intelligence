# ORBIT — Technical Architecture

> Status: Planning baseline · v0.1 · 2026-11-08
> Related: [DATABASE_DESIGN.md](DATABASE_DESIGN.md) · [ROUTES.md](ROUTES.md) · [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md)

## 1. Environment assessment (Phase 0)

| Item | Found | Consequence |
|---|---|---|
| Repository | `C:\Orbit` empty, not a git repo | Greenfield; `git init` at scaffold time |
| PHP on PATH | XAMPP 8.2.12, no `pdo_pgsql` | **Not used.** Below 8.3 requirement |
| PHP (Herd) | 8.4.26 — `pdo_mysql`, `pdo_pgsql`, `redis`, `intl`, `gd`, `zip`, `sodium`, OPcache | Runtime for ORBIT. No `pcntl` (Windows) |
| Composer | 2.9.7 | OK |
| Node / npm | 24.15 / 11.12 | OK; npm is the package manager |
| Database | MySQL 8.0 service running; no PostgreSQL | **MySQL 8 adopted** (owner decision, see D-01) |
| Redis / Docker | Not installed | `database` drivers locally; Redis + Docker defined in `docker-compose.yml` |
| Laravel | Latest 12.x = 12.71; 13.x available | Laravel 12 per brief (see R-03) |

## 2. Stack

| Layer | Choice | Notes |
|---|---|---|
| Runtime | PHP 8.4, Laravel 12 | |
| Auth backend | Laravel Fortify | Headless, audited auth flows (login, register, reset, password confirm, rate limiting). We supply Inertia views. |
| Server↔client | Inertia.js 2 (`inertiajs/inertia-laravel`, `@inertiajs/vue3`) | Deferred props for chart data; partial reloads for filters |
| UI | Vue 3.5 `<script setup lang="ts">`, TypeScript strict | |
| Styling | Tailwind CSS 4 with CSS-variable tokens | Tokens in `resources/css/tokens.css` |
| Components | shadcn-vue (copied into `resources/js/components/ui`, built on reka-ui) | Restyled to ORBIT tokens; we own the source |
| Charts | Apache ECharts 5 (tree-shaken `echarts/core`) via our own `<EChart>` wrapper | No `vue-echarts` dependency |
| Dashboard grid | `grid-layout-plus` | Vue 3 drag/resize grid; persisted coordinates |
| Icons | `lucide-vue-next` | 16 px default, 1.5 stroke |
| Spreadsheet IO | `openspout/openspout` | Streaming read/write for CSV/XLSX; constant memory |
| PDF | `barryvdh/laravel-dompdf` | Branded report PDFs from Blade |
| Tests | Pest 3 (PHPUnit underneath), Vitest for TS utilities | |
| Quality | Laravel Pint, Larastan (level 6), ESLint + `vue-tsc --noEmit`, Prettier | |
| Infra | Queue: `database` (dev) / Redis (docker, prod). Cache: `database` / Redis. Scheduler: `schedule:work` locally | |

## 3. Code organization — modular monolith

Laravel's conventional top-level folders are kept (controllers, models, policies in their usual places) so the framework's discovery works unchanged. **Business logic** is grouped by domain under `app/Domain/*`:

```
app/
  Domain/
    Tenancy/        TenantContext, BelongsToOrganization trait, OrganizationScope, TenantAware job middleware
    Organizations/  OrganizationService, InvitationService, MembershipService
    DataSources/    Connectors/{FileConnector,MySqlConnector,PostgresConnector,RestConnector}, HostGuard, SyncService
    Imports/        Datasets/{SalesDataset,ExpenseDataset,...}, ImportService, RowValidator, ReferenceResolver,
                    Readers/{SpreadsheetReader}, MappingSuggester, FailedRowExporter
    Analytics/      AnalyticsEngine, Query/{AnalyticsQuery,DateRange,Period,Filters}, Metrics/{MetricRegistry,BaseMetric,...},
                    Dimensions/{DimensionRegistry,...}, ResultSet, AnalyticsCache, Comparison
    Metrics/        Formula/{Lexer,Parser,Ast,Evaluator}, CustomMetricService
    Dashboards/     DashboardService, WidgetDataService, LayoutService
    Reports/        ReportTypes/{SalesPerformance,...}, ReportRunner, Exporters/{Csv,Xlsx,Pdf}
    Alerts/         AlertEvaluator, Conditions/*, AlertService
    Insights/       InsightGenerator (rule-based), AnomalyDetector, Forecaster
    Ai/             AiAnalyst, Tools/*, Providers/AnthropicProvider, AiConfiguration
    Audit/          AuditLogger, Redactor
  Http/Controllers/{App,Marketing,Auth,Api}/...   thin: authorize → validate (FormRequest) → call service → respond
  Http/Requests/... Http/Resources/... (JsonResource / Inertia data transformers)
  Models/ Policies/ Jobs/ Events/ Listeners/ Notifications/ Enums/
```

Rules:
1. Controllers never build queries beyond simple `find`/`paginate`. Aggregations go through `AnalyticsEngine`.
2. Services are plain classes resolved from the container; no repository layer (Eloquent is the repository).
3. Every multi-row write that must be atomic runs in `DB::transaction()`.
4. Enums (`App\Enums\Role`, `ImportStatus`, `DatasetType`, …) are PHP backed enums, mirrored as TS union types.

## 4. Multi-tenancy

**Model:** shared database, `organization_id` column on every tenant-owned table (MySQL, see D-01).

### 4.1 Tenant context
- `App\Domain\Tenancy\TenantContext` — container singleton holding the current `Organization` (and the member's `Role`).
- Resolved by middleware `ResolveTenant` on all `/app/{organization:slug}/*` routes:
  1. Load organization by slug.
  2. Verify the authenticated user has a membership row → else **404**.
  3. `TenantContext::set($org, $membership->role)`; persist `users.current_organization_id` for the post-login redirect.
- URL-scoped tenancy (rather than session-scoped) means two browser tabs can hold two orgs safely, links are shareable, and the tenant is explicit in every request.

### 4.2 Enforcement in the model layer
- Trait `BelongsToOrganization` on every tenant model:
  - Adds global scope `OrganizationScope`: `where {table}.organization_id = TenantContext::id()`.
  - **Fails closed:** if no tenant is set, the scope throws `TenantNotResolved` instead of returning unscoped data. Cross-tenant system work (scheduler, migrations, seeders) must call `TenantContext::runAs($org, fn)` or explicitly `withoutGlobalScope` inside `TenantContext::bypass()` — both grep-able.
  - `creating` hook fills `organization_id` from context and refuses a mismatched explicit value.
- Route-model binding goes through the scoped query, so `/app/acme/dashboards/{id-of-other-org}` → 404.
- Raw query builder usage (`DB::table`) is only allowed inside `Analytics` and `Imports`, where every query starts from `TenantQuery::table('sales_transactions')`, which applies the same where-clause.
- Validation rules that reference IDs use `Rule::exists(...)->where('organization_id', $tenantId)`.

### 4.3 Queued work
- Jobs touching tenant data implement `TenantAware` (carry `organizationId`); job middleware `RestoreTenantContext` sets/clears context around `handle()`.
- Scheduled commands iterate organizations with `TenantContext::runAs`.

### 4.4 Isolation tests (mandatory)
For every tenant resource route: user of org B requests org A's resource by ID → 404; listing endpoints never contain org A rows; analytics queries for org B return zero for org A's data; a job for org A cannot read org B rows.

## 5. Authorization model

Roles are stored on the membership pivot (`organization_user.role`). Permissions are a **static matrix in code** (`App\Enums\Permission`, with the role→permission mapping in `App\Enums\Role::permissions()`). Each permission is registered as a Gate answered by the acting member's role from `TenantContext` (outside a tenant context every gate denies). `Gate::before` is **not** used (owners still go through policies, which grant everything). Object-level rules (e.g. `MembershipPolicy`: nobody edits their own membership; only owners touch owners) live in policies.

| Permission | Owner | Admin | Analyst | Viewer |
|---|:-:|:-:|:-:|:-:|
| org.settings.manage | ✓ | ✓ | | |
| org.billing.view | ✓ | | | |
| members.manage | ✓ | ✓ | | |
| members.manage_owners | ✓ | | | |
| sources.view | ✓ | ✓ | ✓ | |
| sources.manage | ✓ | ✓ | | |
| imports.create | ✓ | ✓ | ✓ | |
| metrics.manage | ✓ | ✓ | ✓ | |
| dashboards.create | ✓ | ✓ | ✓ | |
| dashboards.view (shared/org-visible) | ✓ | ✓ | ✓ | ✓ |
| reports.create | ✓ | ✓ | ✓ | |
| reports.export | ✓ | ✓ | ✓ | ✓ |
| alerts.manage | ✓ | ✓ | ✓ | |
| audit.view | ✓ | ✓ | | |
| ai.use | ✓ | ✓ | ✓ | ✓ |

Object-level rules live in policies, e.g. `DashboardPolicy::update` = owner of dashboard, or edit-share, or Admin/Owner. Permissions are shared to the frontend as `auth.permissions: string[]` so the UI hides actions the server would reject anyway (server remains authoritative).

## 6. Data import & processing engine

### 6.1 Dataset definitions (the controlled model)
Each importable dataset is a class implementing `DatasetDefinition`:

```php
interface DatasetDefinition {
    public function type(): DatasetType;
    /** @return list<FieldDefinition> key, label, type(string|int|decimal|date|enum), required, aliases[], rules */
    public function fields(): array;
    public function naturalKey(array $row): string;          // for duplicate detection
    public function persist(ValidatedChunk $chunk, Import $import): ChunkResult;  // upserts, inside a transaction
}
```

`MappingSuggester` scores source headers against each field's key, label and aliases (normalized: lowercase, strip non-alphanumerics, then exact → alias → token-overlap). The user confirms or overrides; the server re-validates that every required field is mapped exactly once.

### 6.2 Pipeline

```
 Upload (sync request)                     Confirm (sync)               Queue (async)
 ┌──────────────────────────────┐   ┌────────────────────────┐   ┌──────────────────────────────────────┐
 │ validate mime/ext/size       │   │ save import_mappings   │   │ ProcessImport job (TenantAware)       │
 │ store to imports disk        │──▶│ status = pending       │──▶│  status = processing, started_at      │
 │ sha256, row count estimate   │   │ dispatch ProcessImport │   │  stream rows (openspout)              │
 │ read headers + 20-row sample │   │ audit: import.started  │   │  per 500 rows:                        │
 │ suggest mapping              │   └────────────────────────┘   │    map → validate → resolve refs      │
 └──────────────────────────────┘                                │    dedupe (in-file set + DB lookup)   │
                                                                 │    DB::transaction(persist + errors)  │
                                                                 │    update processed/failed counters   │
                                                                 │  finalize: status, completed_at       │
                                                                 │  recompute sales header totals        │
                                                                 │  AnalyticsCache::bump(org)            │
                                                                 │  event ImportFinished → notify, audit │
                                                                 └──────────────────────────────────────┘
```

Details:
- **Streaming:** `SpreadsheetReader` wraps openspout and yields `array<string,string>` rows; memory is O(chunk).
- **Row validation:** `RowValidator` coerces types (dates accept `Y-m-d`, `d/m/Y`, `m/d/Y` — the format is detected from the sample and stored on the mapping; numerics strip thousands separators/currency symbols) then applies field rules. Every rejection writes an `import_errors` row with row number, field, code, message and raw row JSON.
- **Reference resolution:** `ReferenceResolver` preloads per-org lookup maps (branch code/name → id, product SKU → id, etc.) once per job; unknown references fail the row (fact datasets) or are created (dimension datasets).
- **Duplicates:** natural key per dataset (e.g. sales line = `reference + sku`); a Bloom-free approach is enough at this scale: an in-memory set for the file, plus a per-chunk `whereIn(natural_key_hash)` lookup against an indexed `natural_key_hash` column.
- **Idempotency:** each chunk is one transaction; on retry the job resumes from `imports.processed_rows` (rows before it are skipped), and the DB uniqueness on `natural_key_hash` prevents double inserts.
- **Sales header/lines:** CSV rows are line-level; headers are upserted by `(organization_id, reference)`, lines inserted, and header totals recomputed with one aggregate `UPDATE … JOIN` at finalize, so orders split across chunks stay correct.
- **Lineage:** every fact row stores `import_id` and `data_source_id` → enables the "data source" filter and import revert.
- **Status rules:** `failed` = unreadable file / job exception / zero valid rows; `completed_with_errors` = ≥ 1 failed row; else `completed`.
- **Progress:** frontend polls `GET …/imports/{id}/progress` every 1.5 s while `pending|processing` (no websockets needed).

### 6.3 External database & API sources (V1)
Sources sync **into the same dataset pipeline** — the connector is just another row reader:
- `MySqlConnector` / `PostgresConnector`: user selects a table or view from the remote `information_schema` listing; identifiers are validated against that listing and quoted by the grammar; the only statement ORBIT issues is `SELECT <mapped cols> FROM <table> [WHERE <updated_col> > ?] LIMIT/OFFSET` in batches. Session set to read-only (`SET SESSION TRANSACTION READ ONLY` / `default_transaction_read_only`), `PDO::ATTR_TIMEOUT = 5`, statement timeout 30 s.
- `RestConnector`: GET only, `Http::timeout(15)->connectTimeout(5)`, response size cap, JSON path to records.
- `HostGuard` (SSRF defense): resolves the hostname, rejects loopback, link-local (incl. 169.254.169.254), private and reserved ranges unless the host is in `ORBIT_ALLOWED_SOURCE_HOSTS`; the resolved IP is pinned for the connection.
- Credentials live in `data_source_credentials.secret` (`encrypted:array` cast), never in `data_sources.config`, the model's `$hidden`, and resources expose only `has_credentials: bool`.

## 7. Analytics engine

### 7.1 Concepts
- **Base metric** — code-defined aggregate over one fact table with a known set of dimensions:

| Key | Definition | Fact | Polarity |
|---|---|---|---|
| `revenue` | Σ line `net_amount` of `completed` transactions | sales | up-good |
| `cogs` | Σ line `quantity × unit_cost` of completed | sales | up-bad |
| `gross_profit` | revenue − cogs | derived | up-good |
| `operating_expenses` | Σ expense `amount` | expenses | up-bad |
| `net_profit` | gross_profit − operating_expenses | derived | up-good |
| `profit_margin` | net_profit / revenue × 100 | derived | up-good |
| `orders` | count distinct completed transactions | sales | up-good |
| `average_order_value` | revenue / orders | derived | up-good |
| `units_sold` | Σ quantity | sales | up-good |
| `inventory_value` | Σ latest snapshot qty × unit_cost per product/branch | inventory | neutral |
| `low_stock_products` | count latest snapshots with qty ≤ reorder_level | inventory | up-bad |
| `headcount` | employees active at period end | employees | neutral |

Growth metrics (`revenue_growth`, `expense_growth`) are the comparison of a metric to its previous period, not separate aggregates.

- **Dimension** — `date:{day|week|month|quarter}`, `branch`, `department`, `category`, `product`, `expense_category`, `data_source`. Each metric declares which dimensions it supports (e.g. `department` applies to expenses and headcount, not sales). Requesting an unsupported combination is a validation error, not a silent zero.
- **AnalyticsQuery** — immutable value object: `metrics[]`, `dimension?`, `DateRange`, `Filters` (branch_ids, department_ids, category_ids, data_source_ids), `comparison: previous_period|previous_year|none`, `limit`, `sort`.
- **DateRange** — built from a preset or custom dates in the **organization timezone**; `previous()` returns the immediately preceding range of equal length (calendar-aligned for month/quarter/year presets: "this month (1–8 Nov)" compares to "1–8 Oct", not to the full October).

### 7.2 Execution
```
AnalyticsEngine::run(AnalyticsQuery) → ResultSet
  1. Expand derived/custom metrics into required base aggregates (dependency graph, cycle-checked).
  2. Group base aggregates by fact table → one SQL aggregation query per fact table
     (SELECT dim_expr, SUM(...), COUNT(DISTINCT ...) FROM fact JOIN dims WHERE org AND date BETWEEN AND filters GROUP BY dim_expr).
  3. Merge rows by dimension key; evaluate derived metrics per row (Formula Evaluator; ÷0 → null).
  4. If comparison requested, repeat for previous range and attach {previous, delta, delta_pct}.
  5. Fill missing time buckets with zeros (a day with no sales is 0, not absent).
```
- Facts are filtered on `business_date` (DATE in org timezone, written at import) → index-friendly range scans, no timezone math in SQL.
- Time bucketing uses MySQL expressions selected by a small `TimeBucket` class (`DATE_FORMAT`, `YEARWEEK`, `QUARTER`) — isolated so a Postgres port changes one file.
- Composite indexes: `(organization_id, business_date)`, `(organization_id, branch_id, business_date)`, `(organization_id, product_id)`.

### 7.3 Caching
- Key = `analytics:{org_id}:v{org_version}:{sha1(query)}`; TTL 1 h.
- `org_version` lives in cache (`analytics:{org_id}:version`), incremented by `AnalyticsCache::bump()` after imports, import reverts and syncs. Old keys become unreachable and expire — works on the `database` driver (no cache tags needed).
- Queries whose range includes "today" get a 5-minute TTL.

### 7.4 Consumers
Executive dashboard, widget data endpoint, report runner, alert evaluator, insight generator, AI tools and drill-down all call `AnalyticsEngine` — **one source of truth**, so a KPI and the chart beside it cannot disagree.

### 7.5 Custom metric formulas
Hand-written recursive-descent parser (`Lexer → Parser → AST`), grammar:
```
expr   := term (('+'|'-') term)*
term   := factor (('*'|'/') factor)*
factor := NUMBER | IDENT | '(' expr ')' | '-' factor
```
Identifiers must exist in the `MetricRegistry` (base or other custom metrics, cycle-checked). Max 64 tokens, max depth 16. The AST is stored as the canonical formula string plus a dependency list; evaluation is pure arithmetic on already-computed numbers. Nothing is ever passed to `eval`, SQL or JS.

## 8. Executive dashboard data flow
- `GET /app/{org}/dashboard?range=this_month&branch[]=…` → `ExecutiveDashboardController` returns KPIs immediately and charts as **Inertia deferred props** (grouped so the four chart groups load in parallel) → skeletons render instantly, charts fill in.
- Filter changes call `router.reload({ data, only: [...] })` with `preserveState`; URL is the single source of filter state.
- Drill-down is one page (`/app/{org}/explore`) whose state (`month → branch → category → transactions`) is URL query params; each level is a deferred prop. Breadcrumbs pop levels.

## 9. Dashboards builder
- `dashboards` + `dashboard_widgets` (x, y, w, h, type, `config` JSON validated by `WidgetConfigData`) + `dashboard_shares`.
- Layout save = one `PUT …/dashboards/{id}/layout` with `[{id,x,y,w,h}]`, applied in a transaction, debounced 800 ms after drag/resize end. Optimistic UI with rollback on error.
- Widget data: `GET …/dashboards/{d}/widgets/{w}/data?range&filters` → `WidgetDataService` → `AnalyticsEngine`. Each widget loads independently (own loading/empty/error states).

## 10. Reports, alerts, notifications, audit
- **Reports:** a `ReportType` declares available columns, default sort, filters and a `query()` built on the engine (aggregated types) or a paginated fact query (transaction-level types). Exports stream via openspout; PDF via dompdf with the org logo/name. > 5,000 rows → `GenerateReportExport` job → `report_runs.file_path` → notification with signed download URL (expires 24 h).
- **Alerts:** `alerts:evaluate` command every 5 min selects due alerts (`next_evaluation_at <= now`), evaluates per org inside `TenantContext::runAs`, writes `alert_executions`, and notifies only when `state` transitions `ok → triggered`; `triggered → ok` records a resolution. Event alerts listen to `ImportFailed` and a `sources:check-staleness` hourly command.
- **Notifications:** Laravel database notifications with an added indexed `organization_id` column (custom channel `TenantDatabaseChannel`); bell shows the current org's unread count.
- **Audit:** `AuditLogger::record(action, subject, meta)`; `Redactor` strips keys matching `/password|secret|token|key|credential/i` recursively before persistence. Login recorded via the `Login` event listener.

## 11. AI analyst (V1)
- Provider: Claude via the **official Anthropic PHP SDK** (`anthropic-ai/sdk` ^1.6, D-13) behind an `LlmClient` interface (`AnthropicLlmClient`; tests bind a scripted fake). Default model `claude-opus-5-5` (`ORBIT_AI_MODEL`), effort `medium` set explicitly (`ORBIT_AI_EFFORT`); adaptive thinking is always on for this model, so no `thinking` parameter is sent. Server-side refusal fallback is enabled (`fallbacks: "default"`, beta `server-side-fallback-2026-07-01`) so a policy decline is retried on Anthropic's recommended fallback model inside the same call.
- Manual tool loop in `AiAnalyst` (max `ORBIT_AI_MAX_TOOL_ROUNDS`, default 8; the last round sends `tool_choice: none` to force an answer). Tools (`AnalystTools`, JSON schema, `additionalProperties: false`): `get_metrics`, `compare_periods`, `breakdown` (also bottom-N via `order: asc`), `trend`, `list_anomalies`, `forecast`. Every argument is validated (metric keys against the registry, ISO dates, ≤ 3-year spans, branch/category ids checked against the organization's own rows); errors go back to the model as `is_error` tool results. All tools run through AnalyticsEngine/Insights inside the requester's tenant context. There is no SQL tool.
- Transcripts are stored in API wire format and **replayed verbatim** on follow-up questions (append-only; thinking blocks and signatures round-trip unchanged). Refused or cut-off exchanges are shown but not replayed, so the history never contains an unanswered tool call.
- Prompt caching: the stable system block (rules + metric catalog + branches/categories + data coverage) carries a breakpoint; today's date sits in a second, uncached system block; top-level `cache_control` caches the growing conversation across tool rounds. Tool definitions are deterministic.
- The response shows the model's prose (rendered as safe text blocks, never HTML) plus an **Evidence** panel rendered from the actual tool results; forecast evidence is tagged "Estimate". The system prompt requires every figure to come from a tool result and marks tool content as data, not instructions.
- No key → `AiConfiguration::status()` = `unconfigured` → configuration screen (instructions for owners/admins). Provider errors map to user-safe messages (`AiUnavailable`) and leave no half-written conversation. Per-user limit `ORBIT_AI_HOURLY_LIMIT` (default 30/h). Conversations are private to their author.

## 12. Anomalies & forecasting (V1)
- `AnomalyDetector` (pure): z-score against a baseline. **Daily series** (revenue, orders) compare each day with the same weekday over up to 8 previous weeks and need ≥ 4 such values — a mixed-weekday baseline flags every normal Saturday, so nothing is reported before that. **Monthly series** (operating expenses) use the previous 12 months (≥ 6 values): expenses are booked in lumps (rent, payroll at month end), so a daily check would flag every pay day. |z| ≥ 2 = warning, ≥ 3 = critical (`ORBIT_ANOMALY_*_Z`); expected range = μ ± 2σ; flat baselines are never flagged.
- `AnomalyService` scans the last 90 complete days / last 3 complete months, writes `anomalies` idempotently (cleared when they no longer hold), and names the branch that drove each deviation (its own same-baseline gap, shown when it explains ≥ 25 %). Periods before a metric's data begins (`Coverage::start`, per fact) are "no data", not zero. `insights:detect-anomalies` runs nightly at 02:00 per organization; "Scan now" for analysts and up.
- `Forecaster` (pure): moving average (3/6 month), OLS linear trend, seasonal-naive with year-over-year growth when ≥ 24 months exist; `auto` picks the method with the lowest error back-tested on the last 3 known months. Band = point ± 1.96 × residual σ × √h (uncertainty grows with the horizon). `ForecastService` uses complete months only (≤ 36), floors only quantities that can't be negative (revenue, expenses, orders, units), and always returns the method label and back-test error.

## 13. Frontend architecture

```
resources/js/
  app.ts                      Inertia bootstrap, layouts by page namespace
  types/                      domain.d.ts (Organization, Membership, Role, ...), analytics.d.ts, inertia.d.ts (SharedProps)
  lib/                        format.ts (currency, compact, percent, dates), filters.ts (URL ⇄ state), chart-theme.ts, cn.ts
  composables/                useFilters, useDateRange, usePermissions, usePolling, useToast, useTheme, useConfirm
  components/
    ui/                       shadcn-vue primitives restyled (button, input, select, dialog, dropdown-menu, popover, table, tooltip, ...)
    shell/                    AppSidebar, OrgSwitcher, TopBar, Breadcrumbs, UserMenu, NotificationBell
    data/                     DataTable, KpiStrip, KpiStat, DeltaBadge, Sparkline, EmptyState, SkeletonRows, StatusBadge
    charts/                   EChart (wrapper), LineChart, BarChart, DonutChart, ChartPanel, ChartLegend, ChartTableToggle
    filters/                  DateRangePicker, FilterBar, MultiSelectFilter
    forms/                    FormField, FieldError, RequiredMark
  layouts/                    AppLayout, AuthLayout, OnboardingLayout, MarketingLayout
  pages/                      mirrors routes (see ROUTES.md)
```
- `EChart.vue` registers only used ECharts modules, applies the ORBIT theme from CSS variables (re-themes on dark-mode switch), uses `ResizeObserver`, `aria-label`, and exposes `click` for drill-down.
- Server data contracts are typed in `types/` and produced by PHP `Resource`/Data classes; field names snake_case on the wire.

## 14. Decision log

| ID | Decision | Rationale |
|---|---|---|
| D-01 | **MySQL 8 instead of PostgreSQL** | Owner decision (2026-11-08); MySQL 8 already installed. Features needed (window functions, JSON, CTEs) exist in MySQL 8. SQL dialect isolated in `TimeBucket` + migrations. |
| D-01a | **SQLite during development, MySQL 8 at integration** | Owner decision (2026-11-08). All dialect-specific SQL (date bucketing, upserts) goes through driver-aware helpers (`TimeBucket`, query builder `upsert`); the suite is run on both drivers at the switch. |
| D-02 | `database` cache/queue/session drivers locally; Redis in docker/prod | No Redis on the dev machine. Cache invalidation designed without tags (version keys) so both drivers work. |
| D-03 | Roles as pivot enum + code permission matrix, not `spatie/laravel-permission` | Four fixed roles; avoids a teams-mode dependency and 5 extra tables. Spec's `roles/permissions` tables evaluated and intentionally not created. |
| D-04 | URL-scoped tenancy `/app/{org-slug}` | Explicit tenant per request, multi-tab safe, shareable links. |
| D-05 | Single `metrics` table for custom metrics; base metrics in code | Spec's `metrics` + `metric_definitions` collapse into one: base definitions are code (versioned with the SQL that implements them). |
| D-06 | `sales_transactions` + `sales_transaction_items` | Order-level metrics (orders, AOV, status) and line-level metrics (units, product mix, COGS) both need a home. |
| D-07 | `business_date` column on facts | Org-timezone date at write time → fast indexed range filters, no TZ conversion in aggregates. |
| D-08 | openspout over maatwebsite/excel | Streaming reader/writer with constant memory; smaller surface. |
| D-09 | Own `EChart` wrapper, no `vue-echarts` | ~80 lines; full control over theming and tree-shaking. |
| D-10 | Fortify for auth backend | Security-sensitive flows from a maintained first-party package; we own all UI. |
| D-11 | Sanctum deferred | No token API consumers yet; same-origin session auth covers Inertia + JSON endpoints. Added when a public API is introduced. |
| D-12 | Polling for import progress | 1.5 s polling is adequate for a progress bar and needs no websocket server. |
| D-13 | Official Anthropic PHP SDK for the AI analyst (supersedes the raw-`Http` plan in §11) | Typed errors, retries and current API features (refusal fallbacks) without hand-maintaining request shapes; wrapped behind `LlmClient` so tests and a future provider swap don't touch the analyst loop. |

## 15. Risks

| ID | Risk | Mitigation |
|---|---|---|
| R-01 | PATH `php` is 8.2 — wrong binary used by tools/IDE | Scripts and docs invoke Herd PHP 8.4 explicitly; recommend putting `…\herd\bin` first on PATH. |
| R-02 | MySQL root password unknown | Create a dedicated `orbit` DB user once credentials are provided. |
| R-03 | Laravel 12 security support ends Feb 2027 | Code stays on framework defaults; upgrade to 13 is planned post-MVP (low-churn release). |
| R-04 | Tenant leak via raw queries | Fail-closed scope, `TenantQuery` helper, isolation test per resource, Larastan rule banning `DB::table` outside allowed namespaces. |
| R-05 | Import performance on large XLSX | Streaming reader, 500-row chunks, preloaded lookups, bulk inserts; benchmark in Phase 3 with 200k rows. |
| R-06 | `grid-layout-plus` maturity | Wrapped in one component (`DashboardGrid.vue`); fallback is CSS grid + pointer events. |
| R-07 | SSRF / credential exposure in connectors | `HostGuard`, IP pinning, read-only sessions, encrypted secrets, redacted logs; security review in Phase 8. |
| R-08 | AI hallucinated figures | Tool-only data access, evidence rendered from tool output, explicit "insufficient data" path. |
| R-09 | No `pcntl` on Windows | Use `queue:work` without `--timeout` signals locally; `pail` excluded from dev script. |
| R-10 | Demo seed time with ~70k orders | Bulk inserts in 1,000-row batches, deterministic RNG seed; target < 60 s. |
