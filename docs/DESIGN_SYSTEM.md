# ORBIT — Design System

> "Instrument, not decoration." ORBIT should read like a well-made measuring instrument: calibrated, quiet, precise, and fast to scan. Every rule below exists to keep the data the loudest thing on the screen.

## 1. Identity

### Principles
1. **Data first, chrome last.** Borders are hairlines, backgrounds are near-neutral, color is spent on meaning (series, status, the one primary action).
2. **Density with rhythm.** Compact by default (13 px UI text, 32 px controls, 36 px table rows) and held together by a strict 4 px grid and consistent alignment, so dense never means cramped.
3. **Structure over containers.** Sections are divided by rules and headings, not wrapped in floating cards. A panel border appears only where content is a self-contained object (a chart, a table, a widget).
4. **Numbers are typography.** Values use tabular figures; identifiers and technical values use the mono face; units and comparison context always travel with the number.
5. **Every state is designed.** Hover, focus, active, loading, empty, error, disabled and success all have a specified look.

### Signature elements (what makes ORBIT recognizable)
- **The orbit mark:** a 1.5 px ring with a 4 px filled dot at 45°, a "measurement point on a path". Used as the logo, the favicon and the loading indicator (the dot travels the ring). Not used decoratively elsewhere.
- **Instrument labels:** section labels and metadata in 11 px uppercase with `0.06em` tracking, in secondary ink. They act like the engraved legends on an instrument panel: `REVENUE · PKR · DAILY`.
- **The KPI strip:** headline KPIs sit in **one** bordered band divided by vertical hairlines, not four floating cards. It reads as a single readout.
- **Accent rule:** the active nav item and the selected tab carry a 2 px accent bar; that's the only place the accent appears as a line.

## 2. Color tokens

Defined as CSS custom properties in `resources/css/tokens.css`, mapped into Tailwind 4 via `@theme inline`. Dark mode is a `.dark` class on `<html>` (user preference: system/light/dark), with values selected for the dark surface, not inverted.

### Neutrals & surfaces

| Token | Light | Dark | Use |
|---|---|---|---|
| `--bg` | `#F7F8FA` | `#10141D` | App canvas |
| `--surface` | `#FFFFFF` | `#171D29` | Panels, tables, sidebar, inputs |
| `--surface-raised` | `#FFFFFF` | `#1D2433` | Popovers, dialogs, menus (shadow tier 2) |
| `--surface-sunken` | `#F1F3F6` | `#0C1018` | Table header, code, inactive segmented control |
| `--surface-hover` | `#F3F4F7` | `#1F2737` | Row/menu hover wash |
| `--border` | `#E5E7EB` | `#283142` | Default hairline |
| `--border-strong` | `#D2D6DE` | `#364156` | Input borders, axis baseline |
| `--text` | `#172033` | `#E8ECF4` | Primary ink |
| `--text-secondary` | `#697386` | `#9AA4B6` | Labels, metadata (4.6:1 on surface ✓) |
| `--text-muted` | `#8A93A3` | `#6F7A8E` | Placeholders, disabled, axis ticks (non-essential only) |

### Accent & semantic

| Token | Light | Dark | Use |
|---|---|---|---|
| `--accent` | `#536DFE` | `#6F86FF` | Primary button, links, focus ring, selection |
| `--accent-hover` | `#435BE8` | `#8598FF` | |
| `--accent-subtle` | `#EEF1FF` | `#1E2647` | Selected row, active nav background |
| `--accent-ink` | `#3B52D9` | `#A9B6FF` | Accent text on subtle background (≥ 4.5:1) |
| `--positive` | `#16845B` | `#3DB38A` | Favorable delta, success |
| `--positive-subtle` | `#E8F5EF` | `#12291F` | |
| `--negative` | `#D94C4C` | `#F07070` | Unfavorable delta, destructive, error |
| `--negative-subtle` | `#FDEDED` | `#331A1D` | |
| `--warning` | `#C58A27` | `#E0A645` | Warning status (always with icon + label) |
| `--warning-subtle` | `#FBF3E4` | `#2E2412` | |
| `--focus-ring` | `0 0 0 2px var(--surface), 0 0 0 4px color-mix(in srgb, var(--accent) 55%, transparent)` | same | Every focusable element |

Rules:
- The accent is reserved for: one primary action per view, links, focus, selection, and chart series 1. Never use it as a decorative fill.
- **No gradients** in the product UI. The marketing site may use one flat tint band per page; still no gradients.
- Deltas are colored by **business polarity**, not sign: expenses +8% → `--negative`; revenue −3% → `--negative`; neutral metrics stay `--text-secondary`.

## 3. Data-visualization palette (validated)

Validated with the dataviz palette validator against ORBIT's own surfaces (`#FFFFFF` light, `#171D29` dark): lightness band, chroma floor, adjacent-pair CVD separation (target ΔE ≥ 8), normal-vision floor (ΔE ≥ 15) all **PASS** in both modes.

| Slot | Hue | Light | Dark |
|---|---|---|---|
| 1 | indigo (brand) | `#536DFE` | `#6F86FF` |
| 2 | orange | `#E8743B` | `#D46A35` |
| 3 | teal | `#19A08A` | `#1C9A83` |
| 4 | ochre | `#D6A21E` | `#B08826` |
| 5 | magenta | `#C9529A` | `#CC5C9C` |
| 6 | green | `#2F8F2F` | `#3F933F` |
| 7 | violet | `#8E6BD8` | `#957DE0` |
| — | "Other" / comparison | `#AEB5C2` | `#4E596C` |

Validation results: light worst adjacent CVD ΔE 10.1, normal-vision 21.8; dark worst adjacent CVD ΔE 10.8, normal-vision 16.7. Slots 1–3 also pass **all-pairs** in both modes (for scatter/donut-like forms where any pair can touch).

Usage rules:
- **Fixed order, never cycled.** An 8th+ series folds into "Other" (gray). Donuts/pies show at most 6 slices + Other.
- **Color follows the entity:** branch Lahore is always slot 1 wherever branches are charted (the color index is derived from the entity's stable sort order in the org, not its rank in the current result), so a filter never repaints survivors.
- **Previous period** is drawn as the same hue's series in `--text-muted` gray, dashed 1.5 px, never a second categorical color.
- Light slot 4 (ochre) is below 3:1 on white → **relief rule**: charts using it show direct labels or the table view toggle is present (it always is, see §8).
- Status colors (`--positive/--negative/--warning`) are **never** series colors.
- **Sequential** (heatmaps, intensity): single indigo ramp `#EEF1FF → #C9D2FF → #93A5FF → #536DFE → #3346C4 → #1F2C85`.
- **Diverging** (variance to target): `--negative` ↔ neutral `#E5E7EB`/`#283142` ↔ indigo.
- **Never** a dual y-axis. Revenue vs Expenses share one currency axis; ratios go in a separate chart.

## 4. Typography

| Family | Token | Use |
|---|---|---|
| **Inter** (variable, self-hosted via `@fontsource-variable/inter`) | `--font-sans` | All UI text. `font-feature-settings: "cv11", "ss01"` (single-storey a off, open digits) |
| **Geist Mono** (self-hosted via `@fontsource/geist-mono`) | `--font-mono` | IDs, references, SKUs, timestamps in tables, formula editor, API values |

Numbers in sans use `font-variant-numeric: tabular-nums` globally in tables, KPI values, axes and deltas (`.num` utility).

### Scale (px / line-height / weight)

| Token | Size | LH | Weight | Use |
|---|---|---|---|---|
| `text-label` | 11 | 16 | 500, uppercase, +0.06em | Instrument labels, table group headers |
| `text-xs` | 12 | 16 | 400/500 | Metadata, badges, axis labels, helper text |
| `text-sm` | 13 | 20 | 400/500 | **Default UI**: tables, nav, buttons, inputs |
| `text-base` | 14 | 22 | 400 | Forms body, prose, dialogs |
| `text-md` | 16 | 24 | 600 | Panel/section titles |
| `text-lg` | 20 | 28 | 600, −0.01em | Page titles (max in-app heading) |
| `text-kpi` | 26 | 32 | 600, −0.02em, tabular | KPI values |
| `text-display` | 44–56 | 1.05 | 600, −0.03em | **Marketing only** |

No in-app heading exceeds 20 px. Weights used: 400, 500, 600 only.

## 5. Space, size, radius, elevation

- **Spacing:** 4 px base: `1=4, 1.5=6, 2=8, 3=12, 4=16, 5=20, 6=24, 8=32, 10=40`. Page gutters 24 px (16 px < 768 px). Section gap 24 px; intra-panel padding 16 px.
- **Control heights:** `sm 28`, `md 32` (default), `lg 36` (auth forms, marketing 40).
- **Content width:** app pages are fluid up to `1600px`; forms max `640px`; settings `880px`; marketing text column `680px`, grid `1200px`.
- **Radius:** `--radius-sm 4px` (badges, checkboxes, chart bar-ends), `--radius-md 6px` (buttons, inputs, menus), `--radius-lg 8px` (panels, dialogs). **No pills** except avatar circles and 6 px status dots. Nothing above 8 px in the app.
- **Elevation:** tier 0 = border only (panels, tables). Tier 1 = `0 1px 2px rgb(16 24 40 / .05)` (sticky table header when scrolled, dragged widget). Tier 2 = `0 8px 24px -4px rgb(16 24 40 / .12), 0 2px 6px rgb(16 24 40 / .06)` (popovers, menus, dialogs, toasts). Dark mode uses the same offsets with higher opacity plus `--border`. Panels never get shadows.
- **Motion:** `120ms` for hover/color, `180ms ease-out` for popovers/dialogs (opacity + 4 px translate), `prefers-reduced-motion` disables all transforms. No entrance animations on page load; charts animate once (300 ms) on first data only, not on every filter change.

## 6. Layout

### App shell
```
┌──────────┬──────────────────────────────────────────────────────────┐
│ Sidebar  │ TopBar  56px: breadcrumbs / page title · actions · 🔔 · ⓤ │
│ 232px    ├──────────────────────────────────────────────────────────┤
│ (56 col- │ FilterBar 48px (only on analytical pages) — sticky       │
│  lapsed) ├──────────────────────────────────────────────────────────┤
│          │ Content (padding 24, max 1600)                           │
└──────────┴──────────────────────────────────────────────────────────┘
```
- **Sidebar:** `--surface`, right hairline. Org switcher (28 px org monogram square, radius 6) at top. Group labels in `text-label`. Items 32 px, 16 px Lucide icons at 1.5 stroke, `text-sm` 500. Active = `--accent-subtle` background + 2 px accent bar on the left edge + `--accent-ink` text. Collapses to icons (tooltips) at < 1280 px or by toggle (persisted in localStorage); off-canvas sheet < 768 px.
- **TopBar:** page title (`text-lg`) with breadcrumbs above in `text-xs` when depth > 1. Right side: contextual actions (max one primary button), notifications bell, user avatar.
- **FilterBar:** date range picker (presets + custom + comparison label "vs 1–8 Oct"), branch multi-select, category multi-select, "Reset" ghost button. Active filters render as removable 24 px chips (radius 4). Sticky under the TopBar.

### Page archetypes (pages pick one; each has a different job)
1. **Readout** (Executive, dashboards): FilterBar + KPI strip + chart grid (12 col, 16 px gaps).
2. **Ledger** (Sources, Imports, Reports, Activity): toolbar (search · filters · primary action) + full-width DataTable, no wrapping card, table touches page gutters.
3. **Workbench** (Import mapping, Report builder, Metric editor, Dashboard edit): left config rail 320 px + main preview canvas.
4. **Form** (Settings, Create source): single column 640 px, sections divided by rules with label/description at left on ≥ 1024 px (two-column settings pattern).
5. **Conversation** (Assistant): centered 760 px thread + evidence panels.

## 7. Components

| Component | Spec |
|---|---|
| **Button** | Variants: `primary` (accent fill, white text), `secondary` (surface + `--border-strong` border), `ghost` (no border, hover wash), `destructive` (negative fill only inside confirm dialogs; elsewhere destructive actions are ghost with negative text). Sizes sm/md/lg. Loading = spinner replaces leading icon, label kept, width locked. Max one primary per view region. |
| **Input / Select / Combobox** | 32 px, radius 6, `--border-strong`, `text-sm`. Focus: border accent + focus ring. Error: border negative + message below in `text-xs` negative with icon. Disabled: `--surface-sunken`, muted text. |
| **FormField** | Label (`text-sm` 500) above; required mark = `*` in negative after label (also `aria-required`); helper text `text-xs` secondary; error replaces helper. 16 px between fields, 24 px between groups. |
| **Badge / StatusBadge** | 20 px tall, radius 4, `text-xs` 500. Status = 6 px dot + label, subtle background: `completed` positive, `processing` accent with pulsing dot, `pending` neutral, `completed_with_errors` warning, `failed` negative, `disabled` muted. Never color alone. |
| **KpiStrip / KpiStat** | Band with 1 px border, radius 8, cells separated by vertical hairlines; cell padding 16/20. Label `text-label`; value `text-kpi` tabular; below: DeltaBadge + "vs Oct 1–8" in `text-xs` secondary; optional 24 px sparkline on the right in slot-1 color at 60% opacity. No icons in KPI cells. |
| **DeltaBadge** | Arrow glyph (▲▼ via Lucide `trending-up/down` 12 px) + signed percent; colored by polarity; "—" with tooltip "No data in comparison period" when null. |
| **Panel** | `--surface`, 1 px border, radius 8, header 44 px: title `text-md`… (14 px 600 inside panels), instrument label metadata right-aligned (`PKR · MONTHLY`), overflow menu (⋯) for: view as table, download CSV, open in Explore. |
| **DataTable** | See §9. |
| **Dialog / ConfirmDialog** | Max 480 px (forms 560), radius 8, tier-2 shadow. Destructive confirms name the object ("Delete source **POS Lahore**?") and state consequences; confirm button label repeats the verb ("Delete source"). |
| **Toast** | Bottom-right, 360 px, tier 2, auto-dismiss 5 s (errors persist until dismissed), status icon + one sentence + optional action. |
| **EmptyState** | Inline, left-aligned within its region: 14 px title, one-line explanation, one action. No illustrations; a single 20 px Lucide icon in muted ink is allowed. Distinguish "no data yet" (call to import) from "no results for these filters" (call to reset). |
| **Skeleton** | `--surface-sunken` blocks matching final geometry (table rows at 36 px, KPI value bars, chart plot rect with axis stubs), shimmer disabled under reduced motion. |
| **Tabs / SegmentedControl** | Tabs: text + 2 px accent underline on active. Segmented (chart granularity Day/Week/Month): 28 px, sunken track, raised active segment. |
| **Tooltip** | `text-xs`, inverted (`--text` bg, `--surface` text), radius 4, 400 ms delay. |
| **Avatar** | Circle, initials on a hashed neutral tint (not categorical colors). |

## 8. Charts

Implemented in `components/charts` on ECharts with a theme built from CSS variables at runtime (`lib/chart-theme.ts`).

- **Anatomy:** inside a Panel; plot area padding: left auto (axis labels), right 16, top 8, bottom 24. Title lives in the Panel header, not in the canvas.
- **Axes:** y-axis labels `text-xs` muted, compact formatting (`₨ 1.2M`, `45K`); x-axis day/month labels with smart thinning; no axis lines except a `--border-strong` baseline; gridlines horizontal only, `--border` 1 px, dashed none.
- **Marks:** lines 2 px, no symbols except on hover (8 px with 2 px surface ring); areas 12% opacity fill of the series color; bars max 28 px wide, 4 px radius on the data end only, 2 px gap between adjacent bars/stacked segments; donut ring width 28%, 2 px surface gap between slices, total in the center (`text-kpi` + `text-label`).
- **Tooltips:** crosshair on line/area (vertical hairline), per-mark on bar/donut. Content: date header (`text-xs` secondary), one row per series = 8 px swatch + name + value right-aligned tabular; comparison rows show delta. Values formatted with the metric's formatter (currency PKR, percent with 1 dp, counts with thousands separators).
- **Legends:** shown for ≥ 2 series, top-left in the panel header area, clickable to toggle series (ECharts `legendselectchanged`), keyboard-operable via the HTML legend we render (not the canvas legend). Up to 4 series also direct-labeled at line ends.
- **States:** loading = skeleton plot; empty = EmptyState inside the plot area ("No sales recorded for Nov 1–8. Try a wider range."); error = inline message + Retry.
- **Accessibility:** every chart has an "View as table" toggle (Panel menu) rendering the same data in a DataTable; canvas gets `role="img"` and an `aria-label` summary ("Revenue, Nov 1–8: ₨ 18.4M, up 6.2% vs Oct 1–8").
- **Drill-down affordance:** clickable marks get `cursor: pointer` and a tooltip footer "Click to break down by branch".

## 9. Tables

- Header row 32 px on `--surface-sunken`, `text-xs` 500 secondary, sentence case; sortable headers show a 12 px sort glyph on hover and when active; `aria-sort` set.
- Body rows 36 px (compact 32 px option), `text-sm`, hairline separators, hover `--surface-hover`, selected `--accent-subtle`.
- **Alignment:** text left; numbers, currency, percentages **right** with tabular figures; dates left in mono `text-xs` when they're timestamps; status centered-left as StatusBadge. Column headers align with their data.
- First column is the identifier (bold 500 or mono reference) and is the row link target.
- Row actions: trailing 32 px ⋯ menu, visible on hover/focus and always visible on touch.
- Toolbar above: search input (240 px, `/` shortcut focuses), filter dropdowns, column visibility, primary action right.
- Pagination below: "1–25 of 1,284" + page size (25/50/100) + prev/next; server-side.
- Loading: 8 skeleton rows; empty: EmptyState spanning the table body.
- Sticky header with tier-1 shadow once scrolled; horizontal scroll for wide tables with the first column sticky.

## 10. Interaction & accessibility

- Focus ring on every interactive element (`:focus-visible`), never removed.
- Keyboard: `⌘/Ctrl K` command palette (navigation + org switch) — Phase 8 nice-to-have; `/` focuses table search; `Esc` closes overlays; arrow keys in menus/listboxes (reka-ui).
- Hit targets ≥ 32 px on desktop, ≥ 40 px on touch layouts.
- Confirm only destructive or irreversible actions; everything else gets a toast with Undo where feasible (e.g., widget removal).
- Disabled controls explain why via tooltip when the reason isn't obvious (e.g., "Viewers can't create dashboards").
- Text contrast ≥ 4.5:1 for body, ≥ 3:1 for large text and UI boundaries.

## 11. Marketing site

Same tokens, different register: larger type (`text-display`), more air (section spacing 96–128 px), a white canvas with one `--surface-sunken` band per page, and real product screenshots framed with a 1 px border and tier-2 shadow, never device mockups. The "How it works" section is a three-step horizontal diagram using the orbit mark's ring-and-dot language as connectors (Connect → Analyze → Decide). No stock illustrations, no gradient blobs, no fake logos or testimonials.

## 12. Anti-pattern checklist (review every PR against it)

- [ ] Is any section wrapped in a card that doesn't represent an object? Remove it.
- [ ] More than one primary button in a region?
- [ ] Any heading > 20 px inside the app?
- [ ] Any icon added purely for decoration (inside KPI cells, section titles)?
- [ ] Any gradient, glow, blur or glassmorphism?
- [ ] Any number not tabular, not right-aligned in a column, or missing its unit/period?
- [ ] Any chart value not from the analytics engine?
- [ ] Any color carrying meaning alone (status without label, series without legend)?
- [ ] Empty, loading and error states present?
- [ ] Dark mode checked?
