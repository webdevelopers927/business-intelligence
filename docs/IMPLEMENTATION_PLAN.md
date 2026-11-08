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

### Phase 5 — Dashboard builder ⬜
CRUD, duplicate, visibility + shares, widget config dialog (metric/dimension compatibility enforced), grid-layout-plus editing, debounced layout save, widget data endpoint, custom metrics (parser, evaluator, preview, editor).
Tests: create, widget validation, layout persistence round-trip, share permissions (private/org/shared × roles), formula parser (valid, invalid tokens, cycles, ÷0), metric preview.

### Phase 6 — Reports & monitoring ⬜
Report types (7), builder (columns, sort, filters, preview), CSV/XLSX/PDF export, queued large exports + report_runs, schedules, alerts (metric + event), `alerts:evaluate`, executions history, notifications UI, audit log viewer.
Tests: each report type query, export formats, queued export notification, alert conditions, transition-only notification (dedupe), resolution, audit redaction.

### Phase 7 — Advanced intelligence ⬜
AnomalyDetector + nightly job + Insights page; Forecaster (MA, trend, seasonal-naive) labeled as estimates; AI analyst (tools over AnalyticsEngine, evidence panel, config state).
Tests: anomaly z-score on synthetic series, forecast methods on known series, AI unconfigured state, AI tool argument validation & tenant scoping (HTTP faked).

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
| Custom dashboards | 5 | ⬜ |
| Layouts persist | 5 | ⬜ |
| Reports generated & exported | 6 | ⬜ |
| Alerts configured & evaluated | 6 | ⬜ |
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

Demo-only; seeder ref   uses to run when `APP_ENV=production`. Organization URL: `/app/nexora`. Rebuild with `php artisan migrate:fresh --seed`.

## Change log
- 2026-11-08 — Phases 0–1 complete. MySQL adopted (D-01); SQLite during development (D-01a).
- 2026-11-08 — Phase 2 complete: Laravel 12.71, Inertia 3 (server 3.7 / vue3 3.11), Fortify, tenancy, roles, invitations, shell, tokens. npm audit: 12 moderate advisories, all in ESLint's dev-only dependency tree; not shipped to the browser.
- 2026-11-09 — Phase 3 complete: data sources, CSV/XLSX import engine, Nexora demo dataset. Added openspout/openspout ^5.13 (D-08). Toolchain versions actually installed: Inertia 3 (not 2), Pest 4 (not 3), Larastan 3.14.
- 2026-11-09 — Fix: signed-in users hitting /login crashed (Laravel's guest redirect called `route('dashboard')` without `{organization}`); now `redirectUsersTo('/app')` + regression test.
- 2026-11-09 — Phase 4 complete: analytics engine, executive dashboard, explorer, insights. ECharts 5.6 (tree-shaken).
