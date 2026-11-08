# ORBIT — Product Requirements

> Status: Planning baseline · v0.1 · 2026-11-08
> Source of truth for *what* ORBIT does. *How* lives in [ARCHITECTURE.md](ARCHITECTURE.md).

## 1. Product statement

ORBIT is a multi-tenant Business Intelligence platform for small and mid-sized, multi-branch organizations. It ingests business data (files, databases, APIs), normalizes it into a controlled analytics model, and turns it into KPIs, dashboards, reports, alerts and assisted insights.

ORBIT **analyzes** operational data; it does not replace the ERP/POS that produces it. There is no order entry, invoicing or stock adjustment in ORBIT.

### Target customers
SMBs, distribution companies, retail chains, agencies, growing multi-branch enterprises.

### Personas (mapped to roles)

| Persona | Role | Primary jobs |
|---|---|---|
| Founder / MD | Owner | Read the executive picture, manage members and plan/billing settings |
| Ops / Finance lead | Administrator | Connect sources, run imports, manage users and org settings |
| Business analyst | Analyst | Build dashboards, define metrics, author reports, configure alerts |
| Branch manager | Viewer | Read authorized dashboards/reports, filter, export |

## 2. Scope by module

Each requirement carries an ID used in tests and the implementation plan. **MVP** = required for the portfolio Definition of Done (§5). **V1** = planned, built after MVP modules are stable.

### AUTH — Authentication & onboarding
- AUTH-1 (MVP) Register, log in, log out, forgot/reset password, email-verified flag (verification email optional in dev).
- AUTH-2 (MVP) Profile: name, email, password change, theme preference.
- AUTH-3 (MVP) Onboarding: create organization → name + industry → business size → onboarding dashboard with a "first import" checklist. Max 2 screens before the user sees the product.
- AUTH-4 (MVP) Invitations: owner/admin invites by email with a role; token link, 7-day expiry, single use; accepting while logged out leads to register/login, then joins.
- AUTH-5 (MVP) Organization switcher for users with multiple memberships.

### TEN — Multi-tenancy & roles
- TEN-1 (MVP) Every tenant-owned row carries `organization_id`; all reads/writes are scoped server-side.
- TEN-2 (MVP) Cross-tenant access returns 404 (not 403) to avoid leaking existence.
- TEN-3 (MVP) Four roles: Owner, Administrator, Analyst, Viewer, with the permission matrix in [ARCHITECTURE.md §5](ARCHITECTURE.md#5-authorization-model).
- TEN-4 (MVP) An organization always has ≥ 1 owner; last owner cannot leave or be demoted.

### SRC — Data sources
- SRC-1 (MVP) Source types: CSV upload, XLSX upload, MySQL, PostgreSQL, REST API (JSON).
- SRC-2 (MVP) Table: name, type, status, last synced, created, actions. Add / edit / test connection / sync / sync history / disable / delete.
- SRC-3 (MVP) Credentials encrypted at rest, never serialized to the browser, never logged.
- SRC-4 (V1) Database sources sync from an **allow-listed table or view** with column mapping; no user-written SQL. Read-only session, connect/statement timeouts, host allow-list + private-range blocking.
- SRC-5 (V1) REST sources: GET-only, JSON records path, header/bearer auth, timeout, page-number pagination, host restrictions as SRC-4.
- SRC-6 (MVP) If a connector cannot reach its target, the UI shows the real error; no simulated "Connected" state.

### IMP — Import & processing
- IMP-1 (MVP) Upload CSV/XLSX (≤ 50 MB, configurable) → headers → 20-row preview → column mapping with auto-suggest → mapping validation → confirm.
- IMP-2 (MVP) Queued, chunked processing (500 rows/chunk), streaming reader, never whole-file in memory.
- IMP-3 (MVP) Statuses: `pending`, `processing`, `completed`, `completed_with_errors`, `failed`. Live progress (rows processed / total).
- IMP-4 (MVP) Row validation: required, date, numeric, enum, reference lookup (branch/product/etc.), in-file and in-database duplicate detection.
- IMP-5 (MVP) History table; failed rows downloadable as CSV (original values + error reason).
- IMP-6 (MVP) Dataset types: sales transactions, expenses, products, inventory snapshots, employees, branches, departments.
- IMP-7 (V1) Revert an import (delete rows carrying its `import_id`) with audit trail.

### ANA — Analytics engine
- ANA-1 (MVP) Metrics: revenue, COGS, gross profit, operating expenses, net profit, profit margin, revenue growth, expense growth, orders, average order value, units sold, inventory value, low-stock count, headcount.
- ANA-2 (MVP) Dimensions: time (day/week/month/quarter), branch, department, product category, product, expense category, data source.
- ANA-3 (MVP) Date presets: today, yesterday, last 7 days, last 30 days, this month, last month, this quarter, this year, custom. Each with a previous-equivalent comparison period.
- ANA-4 (MVP) Percent change is `null` (rendered "—") when the previous value is 0 or missing; never ±∞ or NaN.
- ANA-5 (MVP) Filters apply consistently to KPIs, charts and tables on the same screen.
- ANA-6 (MVP) Results cached per organization; caches invalidated after any import touching that organization.

### EXEC — Executive dashboard
- EXEC-1 (MVP) Header: org name, title, date range, filters (branch, category), notifications, user menu.
- EXEC-2 (MVP) KPIs: Revenue, Net Profit, Operating Expenses, Orders, each with value, % change, comparison label and direction indicator whose color reflects *business* polarity (expenses up = negative).
- EXEC-3 (MVP) Revenue overview (current vs previous period), Revenue vs Expenses, Branch performance (drillable), Sales by category (interactive legend), Top products, Recent transactions, Insights.

### DSH — Dashboard builder
- DSH-1 (MVP) Dashboard CRUD, duplicate, visibility (private / organization / specific members).
- DSH-2 (MVP) Widgets: KPI, line, bar, area, pie, donut, table, ranked list. Config: title, type, metric, dimension, date range (inherit or fixed), filters, display options.
- DSH-3 (MVP) 12-column drag/resize grid; layout persisted and restored exactly.
- DSH-4 (MVP) Dashboard-level filter bar overrides widget filters only where the widget inherits.

### MET — Custom metrics
- MET-1 (MVP) Define metric: name, key, description, formula over base metrics, format (currency/number/percent), visibility.
- MET-2 (MVP) Formula grammar: numbers, base-metric identifiers, `+ - * /`, parentheses, unary minus. No functions, no variables beyond identifiers. Division by zero → `null`.
- MET-3 (MVP) Validate on save; live preview against the last 30 days.

### RPT — Reports
- RPT-1 (MVP) Types: Sales Performance, Revenue Analysis, Expense Analysis, Branch Comparison, Product Performance, Inventory Summary, Workforce Overview.
- RPT-2 (MVP) Date range, filters, column selection, sort, preview (paginated), save & reuse.
- RPT-3 (MVP) Export CSV, XLSX, PDF (org-branded header). Exports > 5,000 rows run as a queued job and notify on completion.
- RPT-4 (V1) Schedules (daily/weekly/monthly) with recipient list.

### DRL — Drill-down
- DRL-1 (MVP) Path: month → branches → categories → transactions. Breadcrumbs reflect each step; the URL encodes state so it can be shared and survives a reload; filters carry through.

### ALR — Alerts
- ALR-1 (MVP) Metric alerts: metric, condition (`below`, `above`, `drops_by_pct`, `rises_by_pct`), threshold, window, frequency (hourly/daily/weekly), recipients, active flag.
- ALR-2 (MVP) Event alerts: import failed, source not synced for N hours.
- ALR-3 (MVP) Scheduled evaluation; execution history; notify only on transition into triggered state; resolution recorded.

### AI — AI analyst (optional module)
- AI-1 (V1) Chat interface with suggested questions.
- AI-2 (V1) The model may only call read-only analytics tools; every figure in an answer is rendered from tool results, shown as an "Evidence" block.
- AI-3 (V1) Without an API key the page shows a configuration state; no canned or fake answers.

### ANM — Anomalies & forecasting
- ANM-1 (V1) Rolling z-score anomaly detection on daily revenue/expenses/orders (configurable window and thresholds). Show metric, date, expected range, actual, severity, explanation.
- ANM-2 (V1) Forecasts: moving average, linear trend, seasonal-naive (when ≥ 24 months of history). Always labeled "Estimate" with the method name.

### NTF / AUD — Notifications & audit
- NTF-1 (MVP) In-app notifications: import completed/failed, alert triggered, report ready, invitation.
- AUD-1 (MVP) Audit log for: login, source changes, import start/finish, dashboard create/share, metric changes, report generation, org settings changes. Actor, action, subject, IP, timestamp; secrets redacted.

### WEB — Marketing site
- WEB-1 (MVP) Landing, Features, Solutions, Pricing, About; links to Login/Register.
- WEB-2 (MVP) Product preview is a real screenshot of the running app with demo data.
- WEB-3 (MVP) No invented testimonials, logos, certifications or statistics.

### DEMO — Demo environment
- DEMO-1 (MVP) Nexora Group: 3 branches (Lahore, Karachi, Islamabad), 6 departments, 8 product categories, 120 products, ~18 months of sales (≈44k orders), expenses, monthly inventory snapshots, 63 employees, monthly targets; one coherent dataset feeding every screen.
- DEMO-2 (MVP) Documented demo logins for each role; all people and companies fictional; `.test` email domains.

## 3. Non-functional requirements

| Area | Requirement |
|---|---|
| Security | Tenant isolation enforced in the model layer + policies; encrypted credentials; CSRF; rate-limited auth; no user-supplied code execution (SQL/PHP/JS). |
| Performance | Executive dashboard p95 < 800 ms uncached on demo data (≈ 70k orders); < 150 ms cached. Import throughput ≥ 2,000 rows/s on dev hardware. |
| Reliability | Imports idempotent per chunk (transaction per chunk); failed jobs visible in UI with reason. |
| Accessibility | WCAG 2.2 AA contrast for text; keyboard-operable controls; visible focus; charts have a table view. |
| Responsiveness | Fully usable ≥ 1280 px; usable at 768 px (sidebar collapses); read-only usable at 390 px. |
| Observability | Structured logs with `organization_id` and `import_id` context. |

## 4. Out of scope (explicit)
- Arbitrary SQL query editor or notebook.
- Writing back to source systems.
- Real billing/payments (Owner sees a plan placeholder page only).
- SSO/SAML, row-level security beyond branch filters, mobile apps.

## 5. Definition of Done (portfolio demo)
Mirrors the brief §26. Tracked as a checklist in [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md#definition-of-done-tracker).
