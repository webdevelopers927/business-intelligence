# ORBIT — Implementation Plan

> Living document, updated at the end of every phase. Status keys: ⬜ not started · 🟡 in progress · ✅ done (tests green) · ⏸ deferred.

## Toolchain

Use Herd's PHP 8.4, **not** the XAMPP 8.2 on PATH:

```powershell
$env:Path = "C:\Users\Hp\.config\herd\bin;" + $env:Path   # php.bat / composer.bat → PHP 8.4
php -v                                                     # must print 8.4.x
```

## Dependencies & installation commands

### Backend (Composer)
```bash
composer create-project laravel/laravel:^12.0 .           # framework skeleton
composer require inertiajs/inertia-laravel:^2.0            # server adapter
composer require laravel/fortify:^1.25                     # auth backend (D-10)
composer require tightenco/ziggy:^2.4                      # named routes in TS
composer require openspout/openspout:^4.28                 # streaming CSV/XLSX (D-08)   — Phase 3
composer require barryvdh/laravel-dompdf:^3.1              # PDF export                  — Phase 6
composer require anthropic-ai/sdk:^1.6                     # AI analyst (D-13)           — Phase 7
composer require --dev pestphp/pest:^3.8 pestphp/pest-plugin-laravel:^3.2
composer require --dev larastan/larastan:^3.4              # static analysis
# laravel/pint ships with the skeleton
```
### Frontend (npm)
```bash
npm i vue@^3.5 @inertiajs/vue3@^2 @vitejs/plugin-vue
npm i -D typescript vue-tsc @types/node eslint @vue/eslint-config-typescript eslint-plugin-vue prettier prettier-plugin-tailwindcss vitest
npm i -D tailwindcss@^4 @tailwindcss/vite
npm i reka-ui class-variance-authority clsx tailwind-merge lucide-vue-next @vueuse/core   # shadcn-vue runtime deps
npm i @fontsource-variable/inter @fontsource/geist-mono
npm i echarts@^5                                            # Phase 4
npm i grid-layout-plus                                      # Phase 5
```
Every dependency above maps to a requirement; anything else needs a decision-log entry.

### Local services
- MySQL 8 (Windows service `MySQL80`) — databases `orbit` and `orbit_testing`, user `orbit`.
- Queue worker: `php artisan queue:work --tries=3`; scheduler: `php artisan schedule:work`.
- `composer dev` script runs `serve`, `queue:listen`, `schedule:work`, `vite` via `npx concurrently` (no `pail` on Windows).
- `docker-compose.yml` (Phase 8): `app` (php-fpm 8.4), `nginx`, `mysql:8.4`, `redis:7`, `worker`, `scheduler`, `vite` (dev profile).

## Phase roadmap

### Phase 0 — Repository inspection ✅
Environment assessed (see ARCHITECTURE §1). Decisions D-01…D-12 recorded.

### Phase 1 — Planning ✅
Deliverables: the six documents in `docs/`.

### Phase 2 — Foundation ✅

Delivered 2026-11-08. Verification: Pest 59 passed / 296 assertions · Vitest 7 passed · Larastan L6 0 errors · Pint clean · vue-tsc 0 · ESLint 0 · headless-Edge walkthrough (login → overview → members → invite → settings → onboarding → profile → switcher) with 0 console/page/HTTP errors, light + dark, 1440/1024/390 px.

Notes from the build:
- 2.3 shipped the primitives Phase 2 needs (Button, Input, NativeSelect, FormField, Badge, Avatar, Skeleton, Dialog, ConfirmDialog, Menu, Tooltip, Toaster, SettingsSection). Table/DataTable, tabs and filter controls ship with their first consumer in Phase 3/4. The `/_ui` page was dropped; the screenshot pass covers visual checks.
- Fortify 2FA and passkeys are **disabled** (V1): enabling them without management UI would expose routes with no screens.
- The sidebar renders only routes that exist (`hasRoute`), so modules appear as they ship and no link leads nowhere.
- `/` redirects to `/app` until the marketing site ships (Phase 8).
- Fixed during verification: Ziggy `current()` needs `absolute=false` and an object `location` (see `resources/js/lib/route.ts`).

Original task list:
| # | Task | Acceptance | Tests |
|---|---|---|---|
| 2.1 | Laravel 12 skeleton, git init, `.env` (MySQL, database drivers), Pint/Larastan/ESLint/tsc configs | `php artisan about` OK; lint passes | — |
| 2.2 | Inertia + Vue 3 + TS strict + Tailwind 4 + Ziggy; tokens.css, fonts, dark mode | Blank page renders in both themes | — |
| 2.3 | UI primitives (button, input, label, select, dialog, dropdown, popover, tooltip, badge, toast, skeleton, table, tabs) restyled to tokens | Visual check page `/_ui` (local only) | Vitest for `format.ts` |
| 2.4 | Migrations: users additions, organizations, organization_user, invitations, audit_logs, notifications | `migrate:fresh` clean | — |
| 2.5 | Tenancy: TenantContext, ResolveTenant, BelongsToOrganization (fail-closed), TenantAware job middleware | | Isolation tests (scope, binding 404, fail-closed) |
| 2.6 | Roles & permissions matrix, policies scaffold, shared `auth.permissions` | | Role restriction tests |
| 2.7 | Fortify auth + Inertia pages: login, register, forgot, reset, logout; rate limiting; login audit | | Registration, login, logout, reset tests |
| 2.8 | Onboarding: create org (name, industry, size) → `/app/{org}/dashboard` with first-import checklist | | Onboarding test |
| 2.9 | Org switcher, members & invitations (invite, accept, role change, remove, last-owner guard) | | Invitation + membership tests |
| 2.10 | App shell: sidebar (groups, collapse, active state, permission-aware), TopBar, breadcrumbs, user menu, notification bell (reads DB notifications) | Shell responsive at 1440/1024/768/390 | — |
| 2.11 | Profile page (name, email, password, theme) | | Profile update test |

**Phase 2 exit:** all tests green, `pint --test`, `phpstan`, `vue-tsc`, `eslint` clean; screenshots of login, onboarding and shell in light/dark.

### Phase 3 — Data management ✅

Delivered 2026-11-09. Verification: Pest 106 passed / 521 assertions (incl. 47 new import/source tests) · Vitest 7 · Larastan L6 0 · Pint · vue-tsc 0 · ESLint 0 · seeded-data SQL audit (0 header/item mismatches, 0 duplicate keys) · headless-Edge end-to-end import through the **running queue worker**: 45-row CSV → 40 imported, 5 rejected with correct codes, notification delivered, 0 browser errors.

What shipped:
- 17 tables (sources, credentials, syncs, imports, mappings, errors, 11 dataset tables) with tenant FKs, unique natural keys and date indexes.
- Seven controlled dataset definitions; streaming CSV/XLSX reader (openspout 5) with delimiter detection, BOM/blank/duplicate header handling and spreadsheet-accurate row numbers.
- Upload → suggested mapping (exact/alias/token scoring) with live preview → date-format detection → queued, chunked (500) processing with per-chunk transactions, resume-safe retries, in-file + database duplicate detection, reference resolution, timezone-correct `business_date`.
- Import history (filters, search, live polling), result page (counters, rejected rows by code, mapping, timing), failed-rows CSV (BOM, formula-injection safe).
- Data sources CRUD for all 5 types; encrypted credentials stored apart from config, never serialized; HostGuard SSRF protection with IP pinning; real connection tests (read-only DB session / REST records path).
- Nexora Group demo seeder (deterministic, ~15 s): 44k orders, 83k lines, 698 expenses, 63 employees, 7.2k inventory snapshots, 76 targets.

Decisions & deviations (logged):
- New import status **Draft** (uploaded, awaiting mapping) so *Pending* consistently means "queued".
- **Scheduled sync from MySQL/PostgreSQL/REST is not implemented** (PRD SRC-4/5 are V1). Those sources can be created and connection-tested; their page states plainly that sync is not available yet. No sync button is shown.
- Progress uses Inertia partial-reload polling (`usePoll`) instead of a separate JSON progress endpoint.
- Demo staffing is 63 employees (3 stores + head office), not ~90; the PRD was updated.
- A file of only duplicates/invalid rows ends as **Failed** ("No rows could be imported").

Original task list:
3.1 dimension/fact migrations + models (all `BelongsToOrganization`) · 3.2 DatasetDefinitions for 7 types · 3.3 upload + header/preview (openspout) · 3.4 MappingSuggester + mapping UI (Workbench) · 3.5 ProcessImport job (chunks, validation, references, duplicates, transactions, resume) · 3.6 progress polling + summary · 3.7 history table + failed-rows CSV · 3.8 Data sources CRUD (file types fully; DB/REST create + test connection with HostGuard) · 3.9 Nexora demo seeder (deterministic) + demo users.
Tests: valid CSV, valid XLSX, invalid file type/size, missing required mapping, bad dates/numbers, unknown references, in-file + DB duplicates, partial failure status, resume idempotency, cross-tenant import access, credential never in responses.

### Phase 4 — Analytics ✅

Delivered 2026-11-09. Verification: Pest 149 passed / 911 assertions (incl. 43 new analytics tests against hand-computed fixtures) · Vitest 7 · Larastan L6 0 · Pint · vue-tsc 0 · ESLint 0 · headless-Edge review of dashboard (light/dark, 1440/390), filters, date menu and every explorer level with 0 browser errors.

Performance on the demo dataset (44k orders, SQLite, uncached): each dashboard/explorer request 100–250 ms server time (NFR: p95 < 800 ms). `php artisan serve` is single-threaded, so the four deferred groups queue locally; under php-fpm they run in parallel.

What shipped:
- `DateRange` with 8 presets + custom + calendar month; comparison rules: rolling windows vs the preceding equal window, to-date periods vs the same elapsed days (clamped: Mar 31 → Feb 28/29), complete months vs the whole previous month.
- `MetricRegistry` (11 metrics, polarity, fact/dimension compatibility) and `AnalyticsEngine`: one aggregate query per fact, derived metrics, ÷0 → null, gap-filled series with aligned comparison, breakdowns with stable entity colors, prorated targets, latest-snapshot inventory, headcount. Results cached per organization version (`AnalyticsCache`), invalidated on import.
- Executive dashboard: URL-state filter bar (date presets/custom, branches, categories), KPI band, secondary metrics, revenue overview (vs previous), revenue vs expenses (12 months), branch performance with target attainment, category donut with toggleable legend, top products, recent transactions, rule-based insights — four deferred groups with skeletons; every chart has a table view.
- Explorer drill-down months → branches → categories → transaction lines, URL-encoded with breadcrumbs.

Decisions (logged):
- Ratio metrics (margins) change in **percentage points**, not percent.
- Expenses can't be attributed to product categories: under a category filter, expense-based KPIs say "Product category filter not applicable". A branch filter excludes head-office costs.
- Revenue vs expenses always uses monthly buckets over the trailing 12 months (expenses post monthly; daily bars would mislead).
- Insights are deterministic rules over engine results (≥ 5% moves, target attainment, expense-ratio shift ≥ 2 pts, low stock), labeled "Rule-based".
- Head office appears in branch breakdowns of expense metrics but is excluded from the branch performance panel (it has no sales).

Original task list:
4.1 DateRange/Period presets + previous period · 4.2 MetricRegistry + base metrics · 4.3 AnalyticsEngine (per-fact aggregation, derived metrics, gap fill, comparison) · 4.4 AnalyticsCache (version bump) · 4.5 Executive dashboard (KPI strip, 6 panels, deferred props, filter bar, URL state) · 4.6 EChart wrapper + theme + table view · 4.7 Explore drill-down · 4.8 rule-based Insights (largest movers, target attainment).
Tests: revenue/COGS/gross/net/opex against hand-computed fixtures, each preset's range and previous range (incl. month-to-date alignment, leap years), ÷0 → null, branch/category filters, gap filling, cache invalidation after import, tenant isolation of aggregates.

### Phase 5 — Dashboard builder ✅

Delivered 2026-11-10. Verification: Pest 200 passed / 1,108 assertions (incl. 51 new formula/metric/dashboard tests) · Vitest 7 · Larastan L6 0 · Pint · vue-tsc 0 · ESLint 0 · headless-Edge end-to-end: real mouse drag of a widget → autosave → full reload restores the new position; add-widget dialog; share dialog; metric editor live preview and error state; light/dark, 1440/390 px; 0 browser errors.

What shipped:
- **Formula engine** (`App\Domain\Metrics\Formula`): hand-written lexer/recursive-descent parser over `+ - * / ( )`, numbers and metric identifiers; array AST walked by a 4-operator evaluator. Limits: 300 chars, 64 tokens, depth 16. ÷0 and missing data → null. No eval/SQL/JS ever.
- **Custom metrics** join the same `MetricRegistry` as base metrics (request-scoped), evaluated in dependency order on top of cached base aggregates, so every consumer gets them and edits apply instantly. Validation: known references only, cycle detection, private/organization visibility, delete blocked while used by widgets or other metrics. Live preview over the last 30 days with the input values shown.
- **Dashboards**: CRUD, rename, duplicate (private copy with widgets), soft delete, visibility (private / organization / specific members with view or edit), 12-column drag/resize grid (`grid-layout-plus`), debounced layout autosave with visible save state, server-side clamping to the grid and per-type minimum sizes.
- **Widgets**: KPI (with sparkline), line, area, bar, pie, donut (with legend), table, ranked list. Config: metric, dimension (type × metric compatibility enforced client- and server-side), follow-dashboard or fixed date range, optional widget filters that override the dashboard filter for that dimension, comparison, item limit. Each widget loads independently with its own loading/empty/error states.
- Demo: `NexoraDashboardsSeeder` (idempotent) adds three custom metrics and an organization-wide "Branch operations" dashboard.

Decisions (logged):
- Permissions: view = owner, organization-visible, shared-with, or org owner/admin (governance). Edit = owner, org owner/admin, or an **edit share held by someone who can build dashboards** (an edit share to a Viewer grants view only). Delete/share = owner or org owner/admin.
- Widgets always resolve through their dashboard (`scopeBindings`): a widget id from another dashboard is a 404.
- Layout editing is desktop-only (≥ 1024 px); smaller screens show a read-only stack in layout order.
- Metric keys derive from names with `Str::slug(…, '_')` (`snake()` broke acronyms: "COGS share" → `c_o_g_s_share`).
- Changing the reporting currency relabels amounts; values are not converted (the settings form now says so).

Fixed during verification: the drag handle was a `<button>`, which interact.js ignores by default; the whole widget header is now the handle in edit mode. Donut/pie widgets lacked a legend. "Head office" bars used a branch color instead of neutral gray.

Original task list:
CRUD, duplicate, visibility + shares, widget config dialog (metric/dimension compatibility enforced), grid-layout-plus editing, debounced layout save, widget data endpoint, custom metrics (parser, evaluator, preview, editor).
Tests: create, widget validation, layout persistence round-trip, share permissions (private/org/shared × roles), formula parser (valid, invalid tokens, cycles, ÷0), metric preview.

### Phase 6 — Reports & monitoring ✅

Delivered 2026-11-09. Verification: Pest 222 passed / 1,263 assertions (22 new report/alert/activity tests) · Vitest 7 · Larastan L6 0 · Pint · vue-tsc 0 · ESLint 0 · `npm run build` · headless-Edge walkthrough of every new screen (builder preview + header sort, real PDF download, schedule dialog, alert "Check now", activity log; light/dark, 1440/390 px, no horizontal overflow); 0 browser errors.

What shipped:
- **Seven report types** (`App\Domain\Reports\Types`): sales performance (row-level, cursor-streamed), revenue analysis, expense analysis, branch comparison, product performance, inventory summary, workforce overview. Each declares its columns, filters, default sort and range; no free-form SQL anywhere.
- **Builder** (`reports/Builder`): type, period presets/custom range, branch/category filters, column picker, sort (also by clicking a header), live paginated preview (JSON, debounced, stale responses discarded).
- **Exports**: CSV (UTF-8 BOM, raw numbers, formula-injection-safe text), XLSX (openspout), PDF (dompdf, branded, portrait/landscape by width, font subsetting, 2,000-row cap with a validation message pointing to CSV/Excel). Exports larger than `ORBIT_REPORT_QUEUE_THRESHOLD` rows (default 5,000) are queued; the requester gets an in-app + email notification with a download link. Every export is a `report_runs` row, re-downloadable and audited (`report.generated`).
- **Saved reports + schedules**: daily / weekly / monthly at a local time; recipients must be organization members; the period is recalculated per run (e.g. "Last month"). `reports:run-scheduled` (every 5 min) advances `next_run_at` before running so a failure can't loop.
- **Alerts**: metric thresholds (below / above / drops-by-% / rises-by-% over yesterday, 7 or 30 complete days, optional branch filter), failed imports, stale data sources. `alerts:evaluate` (every 5 min) evaluates due alerts per organization. State machine ok → triggered notifies **once**; staying triggered records history silently; recovery records "resolved". "Check now" on the alert page. Execution history with outcome, message, notified flag.
- **Activity** (`/activity`, owner/admin): audit log with area / person / date filters; metadata is already redacted at write time.
- Navigation: Reports (Analysis); Alerts and Activity (new Monitoring group). `composer dev` now also runs `schedule:work`.
- Demo: `NexoraReportsSeeder` (idempotent) adds four saved reports (two scheduled) and five alerts, each evaluated once against the demo data (gross margin < 30 % genuinely triggers).

Decisions (logged):
- Viewers can preview and export (`reports.export`) but not save; saved reports are managed by their author or org owners/admins.
- A background export always answers "queued — you'll be notified", even when a synchronous queue finished it instantly, so the UX doesn't depend on the queue driver.
- Alert windows use complete days only (never a partial "today"); percentage conditions compare with the preceding window of equal length. Editing an alert resets it to ok so a still-breached condition notifies again.
- Download filenames are stamped in the organization's time zone.

Fixed during verification: `structuredClone` rejected Inertia's reactive props (builder never loaded its preview) → JSON copy; PDFs embedded full DejaVu fonts (~880 KB for 4 rows) → subsetting; filenames used UTC; percent-metric alert thresholds rendered as "30" instead of "30%".

Not in this phase: export file retention/pruning (files stay on the local disk until a cleanup job is added in Phase 8).

### Phase 7 — Advanced intelligence ✅

Delivered 2026-11-09. Verification: Pest 249 passed / 1,420 assertions (11 new unit + 15 new feature tests) · Vitest 10 · Larastan L6 0 · Pint · vue-tsc 0 · ESLint 0 · `npm run build` · headless-Edge walkthrough (Insights anomalies + forecast, metric/horizon switching, Scan now, Assistant setup state for owner and viewer, 1440/390 px, dark mode); 0 browser errors. The live AI path is covered by tests with a scripted model; no paid API calls were made because no `ANTHROPIC_API_KEY` is configured.

What shipped:
- **Insights page** (`/insights`, every member): anomaly chart with critical/warning markers, list with actual vs expected range, deviation (coloured by business polarity), z-score and a plain-language explanation naming the branch that drove it; forecast panel (metric, method, 3/6/12 months) with history, dashed estimate, 95 % band, values table and back-test error, always badged "Estimate".
- **Anomaly detection**: weekday-aware daily checks for revenue and orders, monthly checks for operating expenses (see ARCHITECTURE §12). On the Nexora data it finds 24 anomalies, including the mid-September Islamabad slump (orders) and September's expense spike (+25.6 %, 87 % from Islamabad).
- **Forecasting**: four methods, auto-selected by back-test; seasonal-naive offered only with 24 months (Nexora has 18, so the UI says "needs more history").
- **AI analyst** (`/assistant`, `ai.use`): Claude Opus 5.5 via the official PHP SDK, six validated read-only tools, evidence panel from tool output, verbatim transcript replay, refusal fallback, prompt caching, per-user hourly limit, private conversations, configuration state without a key (no canned answers).
- `insights:detect-anomalies` nightly at 02:00; the demo seeder runs a scan so Insights is populated immediately.

Decisions (logged):
- D-13: official Anthropic PHP SDK instead of raw HTTP (ARCHITECTURE decision log).
- Daily anomaly checks use same-weekday baselines only; operating expenses are checked monthly. Both choices remove systematic false positives seen on real data (every Saturday early in the history; every pay day).
- Forecast bands widen with √h; only inherently non-negative quantities are floored at zero.
- The analyst runs synchronously in the request (typically 5–30 s, progress shown); a queued/streaming variant can follow if answers grow longer.

Fixed during verification: `updateOrCreate` on a `date` cast never matched on SQLite (stored as `Y-m-d 00:00:00`) → `whereDate`; history before a metric's own data began counted as zero months (revenue got two fake months from earlier expense data) → per-fact coverage; "Last scan 5 hours ago" (UTC string read as local time) → ISO-8601 with offset; expense deviations above expected shown green → polarity-aware colours; net-profit forecast band clipped at zero → unclipped.

Not in this phase: anomaly notifications (alerts can already watch metrics); per-branch anomaly series.

### Phase 8 — Quality & deployment ⬜
Security review (tenancy, SSRF, uploads, secrets, authz), N+1 audit (`Model::preventLazyLoading`), performance benchmarks vs NFRs, responsive pass, marketing site + real screenshots, Docker setup, `docs/DEPLOYMENT.md`, demo credentials in README.

## Definition of Done tracker

| Criterion | Phase | Status |
|---|---|---|
| Register & create organization | 2 | ✅ |
| Organization data isolated | 2–3 | 🟡 framework + tests done; extended per module |
| Import business datasets | 3 | ✅ |
| Imports processed & tracked | 3 | ✅ |
| Analytics from real records | 4 | ✅ |
| KPIs consistent | 4 | ✅ |
| Charts respond to filters | 4 | ✅ |
| Custom dashboards | 5 | ✅ |
| Layouts persist | 5 | ✅ |
| Reports generated & exported | 6 | ✅ |
| Alerts configured & evaluated | 6 | ✅ |
| Responsive interface | 8 | ⬜ |
| Automated tests for key workflows | 2–8 | ⬜ |
| Realistic, consistent demo data | 3 | ✅ |
| No critical security issue | 8 | ⬜ |
| Coherent visual identity | 2–8 | ⬜ |

## Demo credentials (created in Phase 3 seeder)

| Role | Email | Password |
|---|---|---|
| Owner | `owner@nexora.test` | `password` |
| Administrator | `admin@nexora.test` | `password` |
| Analyst | `analyst@nexora.test` | `password` |
| Viewer | `viewer@nexora.test` | `password` |

Demo-only; seeder refuses to run when `APP_ENV=production`. Organization URL: `/app/nexora`. Rebuild with `php artisan migrate:fresh --seed`.

## Change log
- 2026-11-08 — Phases 0–1 complete. MySQL adopted (D-01); SQLite during development (D-01a).
- 2026-11-08 — Phase 2 complete: Laravel 12.71, Inertia 3 (server 3.7 / vue3 3.11), Fortify, tenancy, roles, invitations, shell, tokens. npm audit: 12 moderate advisories, all in ESLint's dev-only dependency tree; not shipped to the browser.
- 2026-11-09 — Phase 3 complete: data sources, CSV/XLSX import engine, Nexora demo dataset. Added openspout/openspout ^5.13 (D-08). Toolchain versions actually installed: Inertia 3 (not 2), Pest 4 (not 3), Larastan 3.14.
- 2026-11-09 — Fix: signed-in users hitting /login crashed (Laravel's guest redirect called `route('dashboard')` without `{organization}`); now `redirectUsersTo('/app')` + regression test.
- 2026-11-09 — Phase 4 complete: analytics engine, executive dashboard, explorer, insights. ECharts 5.6 (tree-shaken).
- 2026-11-10 — Phase 5 complete: dashboard builder, widgets, sharing, custom metric formulas. Added grid-layout-plus ^1.1.1.
- 2026-11-09 — Phase 6 complete: reports (7 types, CSV/XLSX/PDF, queued exports, schedules), alerts, activity log. Added barryvdh/laravel-dompdf ^3.1. `composer dev` runs the scheduler.
- 2026-11-09 — Phase 7 complete: anomaly detection, forecasting, Insights page, AI analyst (Claude Opus 5.5). Added anthropic-ai/sdk ^1.6 (D-13).
