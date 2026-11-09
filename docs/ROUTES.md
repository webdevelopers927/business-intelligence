# ORBIT — Routes, Navigation & Page Inventory

All app routes live under `/app/{organization:slug}` and pass through `auth`, `verified`(prod), `ResolveTenant`. JSON endpoints use the same session auth (same-origin) and return:

```json
{ "data": ..., "meta": { "pagination"?: {...}, "query"?: {...} } }          // success
{ "message": "Human readable", "errors"?: { "field": ["..."] }, "code": "import_not_ready" }  // error (4xx/5xx)
```

## 1. Marketing (public, `MarketingLayout`)

| Method | Path | Page | Notes |
|---|---|---|---|
| GET | `/` | `marketing/Home` | Hero, real product screenshot, features, how it works, use cases, CTA |
| GET | `/features` | `marketing/Features` | |
| GET | `/solutions` | `marketing/Solutions` | Retail, distribution, professional services, growing businesses |
| GET | `/pricing` | `marketing/Pricing` | Plans described without fake metrics |
| GET | `/about` | `marketing/About` | |

## 2. Auth & onboarding (`AuthLayout` / `OnboardingLayout`)

| Method | Path | Page / action |
|---|---|---|
| GET/POST | `/login` | `auth/Login` (Fortify) |
| GET/POST | `/register` | `auth/Register` |
| POST | `/logout` | |
| GET/POST | `/forgot-password` | `auth/ForgotPassword` |
| GET/POST | `/reset-password/{token}` | `auth/ResetPassword` |
| GET | `/app` | Redirect → current org dashboard, or onboarding if none |
| GET/POST | `/onboarding/organization` | `onboarding/CreateOrganization` (name, industry, size in one form, two steps client-side) |
| GET | `/invitations/{token}` | `invitations/Accept` |
| POST | `/invitations/{token}/accept` | |
| GET/PATCH | `/profile` | `profile/Edit` |
| PUT | `/profile/password` | |
| POST | `/organizations/{organization}/switch` | Set current org, redirect |

## 3. Application (`AppLayout`, prefix `/app/{org}`)

| Method | Path | Page / action | Permission |
|---|---|---|---|
| GET | `/dashboard` | `app/Executive` (`app/Overview` checklist until sales data exists); `?range&from&to&branches&categories`; deferred groups `trend`, `breakdowns`, `tables`, `insights` | any member |
| GET | `/explore` | `app/Explore` (drill-down; `?month&branch&category&page`; deferred `data`) | any |
| **Dashboards** ||||
| GET | `/dashboards` | `dashboards/Index` | any |
| POST | `/dashboards` | create | dashboards.create |
| GET | `/dashboards/{dashboard}` | `dashboards/Show` (view + edit mode) | policy view |
| PATCH | `/dashboards/{dashboard}` | rename/visibility | policy update |
| DELETE | `/dashboards/{dashboard}` | | policy delete |
| POST | `/dashboards/{dashboard}/duplicate` | | dashboards.create |
| PUT | `/dashboards/{dashboard}/layout` | JSON | policy update |
| POST/PATCH/DELETE | `/dashboards/{dashboard}/widgets[/{widget}]` | JSON | policy update |
| GET | `/dashboards/{dashboard}/widgets/{widget}/data` | JSON widget data | policy view |
| PUT | `/dashboards/{dashboard}/shares` | | policy share |
| **Data** ||||
| GET | `/sources` | `sources/Index` | sources.view |
| GET/POST | `/sources/create`, `/sources` | `sources/Create` | sources.manage |
| GET/PATCH | `/sources/{source}/edit`, `/sources/{source}` | `sources/Edit` | sources.manage |
| POST | `/sources/{source}/test` | JSON test result | sources.manage |
| POST | `/sources/{source}/sync` | queue sync | sources.manage |
| POST | `/sources/{source}/toggle` | disable/enable | sources.manage |
| DELETE | `/sources/{source}` | soft delete | sources.manage |
| GET | `/sources/{source}` | `sources/Show` (sync history) | sources.view |
| GET | `/imports` | `imports/Index` (history) | sources.view |
| GET | `/imports/create` | `imports/Create` (upload step) | imports.create |
| POST | `/imports` | upload → redirect to mapping | imports.create |
| GET | `/imports/{import}/mapping` | `imports/Mapping` (preview + mapping) | imports.create |
| POST | `/imports/{import}/confirm` | validate mapping + dispatch | imports.create |
| GET | `/imports/{import}` | `imports/Show` (progress → summary) | sources.view |
| GET | `/imports/{import}/failed-rows.csv` | failed rows download | sources.view |

> Import progress is delivered by Inertia partial reloads (`usePoll`, 1.5 s) of the `imports/Show` page while the import is queued or processing, so no separate progress endpoint exists.
| GET | `/datasets/{type}` | `datasets/Index` (browse normalized records) | sources.view |
| **Analysis** ||||
| GET | `/metrics` | `metrics/Index` | any |
| GET/POST/PATCH/DELETE | `/metrics…` | `metrics/Form` | metrics.manage |
| POST | `/metrics/preview` | JSON formula validation + value | metrics.manage |
| GET | `/reports` | `reports/Index` (catalog, saved, recent exports) | reports.export (all roles) |
| GET | `/reports/new?type=` | `reports/Builder` | reports.export |
| GET | `/reports/{report}` | `reports/Builder` (saved) | reports.export |
| POST | `/reports` | save | reports.create |
| PATCH/DELETE | `/reports/{report}` | update / delete | author or owner/admin |
| PUT/DELETE | `/reports/{report}/schedule` | schedule / unschedule | author or owner/admin |
| POST | `/reports/preview` | JSON paginated preview | reports.export |
| POST | `/reports/export` | JSON: download URL (inline) or queued | reports.export |
| GET | `/reports/runs/{run}/download` | file (tenant-scoped; 410 if expired) | reports.export |
| GET | `/insights?metric&severity&forecast&method&horizon` | `insights/Index` (anomalies + forecasts; deferred group `charts`) | any |
| POST | `/insights/scan` | re-run anomaly detection | metrics.manage |
| GET | `/assistant`, `/assistant/{conversation}` | `assistant/Index` (AI or config state; own conversations only) | ai.use |
| POST | `/assistant/messages` | JSON `{message, conversation_id?}` → messages + evidence; `throttle:ai` | ai.use |
| DELETE | `/assistant/{conversation}` | delete own conversation | ai.use |
| **Monitoring** ||||
| GET | `/alerts` | `alerts/Index` | alerts.manage |
| GET/POST/PATCH/DELETE | `/alerts…` | `alerts/Form` | alerts.manage |
| GET | `/alerts/{alert}` | `alerts/Show` (execution history) | alerts.manage |
| POST | `/alerts/{alert}/evaluate`, `/alerts/{alert}/toggle` | check now / pause–enable | alerts.manage |
| GET | `/notifications` | JSON list; POST `/notifications/read` | any |
| GET | `/activity` | `activity/Index` (audit log) | audit.view |
| **Settings** ||||
| GET/PATCH | `/settings/organization` | `settings/Organization` | org.settings.manage |
| GET | `/settings/members` | `settings/Members` | members.manage |
| POST/PATCH/DELETE | `/settings/members…`, `/settings/invitations…` | | members.manage |
| GET | `/settings/billing` | `settings/Billing` (plan placeholder, clearly labeled) | org.billing.view |
| GET | `/settings/ai` | `settings/Ai` (configuration status) | org.settings.manage |

## 4. Sidebar navigation

```
[◎ Nexora Group ▾]           ← org switcher
OVERVIEW
  Executive                  /dashboard
  Dashboards                 /dashboards
  Explore                    /explore
DATA
  Sources                    /sources          (hidden for Viewer)
  Imports                    /imports          (hidden for Viewer)
ANALYSIS
  Metrics                    /metrics
  Reports                    /reports
  Insights                   /insights
  Assistant                  /assistant
MONITORING
  Alerts                     /alerts
  Activity                   /activity         (Owner/Admin)
────────
  Settings                   /settings/organization
[avatar  Name · role ▾]      ← profile, theme, sign out
```
