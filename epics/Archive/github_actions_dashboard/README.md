# Handoff: GitHub Actions Dashboard (F1 Betting)

## Overview
An internal ops dashboard for the **Frederikssund F1 Klub** (`Djarnisdrengen/F1Betting`) that surfaces every GitHub Actions workflow in the repo. It answers four questions at a glance: what automations exist, what each one is for + when it runs, how the last 10 runs of each went (with per-step logs), and — across all workflows — what ran in the last 12 hours and how the whole month's schedule is laid out (including run collisions). It is an admin/monitoring surface, not a member-facing page.

> **All displayed timestamps are in CET (UTC+1).** Run start times, per-step log stamps, the next-run box, the schedule Time column, the collision-row times, and the human schedule strings are all rendered in CET. **Cron expressions stay literal** (`0 1 * * *` etc.) because GitHub Actions evaluates cron in **UTC** — that is the actual workflow config, so do not shift it. In the prototype CET is a flat +1h; for production consider `Europe/Copenhagen` so it follows DST (CEST/+2 in summer).

## About the Design Files
The file in this bundle (`Actions Dashboard.dc.html`) is a **design reference created in HTML** — a working prototype showing the intended look and behavior, not production code to ship. It is authored as a self-contained "Design Component" with an inline React-like runtime and mock data.

Your task is to **recreate this design in the target codebase's environment**. The live app (`Djarnisdrengen/F1Betting`) is **PHP + vanilla JS + MySQL/SQLite, no front-end framework** — so the natural implementation is a PHP page (e.g. `public/admin/actions.php`) that fetches data from the GitHub REST API and renders server-side, with a small amount of vanilla JS for the expand/collapse and filter interactions. Do not port the DC runtime; reuse the existing site's CSS variables and chrome.

## Fidelity
**High-fidelity (hifi).** Final colors, typography, spacing, and interactions are all specified below and pulled from the bound design system (`public/assets/css/style.css` → `colors_and_type.css`). Recreate pixel-for-pixel using the site's existing tokens and Font Awesome 6.

## Data Source
All numbers/logs in the prototype are **mock data**. In production, back it with the GitHub REST API:
- List workflows: `GET /repos/{owner}/{repo}/actions/workflows`
- Per-workflow runs (last 10): `GET /repos/{owner}/{repo}/actions/workflows/{workflow_id}/runs?per_page=10`
- Run status/conclusion, `created_at`, `run_started_at`, `updated_at`, `event` (trigger), `actor.login`, `head_branch`, `head_sha`, `run_number`, `run_attempt`.
- Per-run steps/logs: `GET /repos/{owner}/{repo}/actions/runs/{run_id}/jobs` for step names + conclusions; `GET /repos/{owner}/{repo}/actions/runs/{run_id}/logs` (zip) for raw log text.
- Purpose / expected result / cron schedule are **not** in the API — read them from a small static config map keyed by workflow filename (see "State / Data" below), or parse the `on.schedule.cron` out of each `.yml`.
- **Caching:** the GitHub API is rate-limited — cache responses for ~60s server-side.

## Screens / Views

### Single view: Actions Dashboard
One page, regions stacked inside a centered container, top→bottom: **header → summary strip → 12h-runs table (collapsible) → two-column master/detail → run-schedule matrix**.

**Page container:** `max-width: 1360px`, centered, `padding: 22px 24px 40px`. Page background `var(--bg-primary)`. Default theme is **dark** (`.theme-dark` / `body.dark`).

#### 1. Header (sticky)
- `position: sticky; top: 0; z-index: 20`. Background `var(--bg-secondary)`, `border-bottom: 1px solid var(--border-color)`, `padding: 14px 24px`, `display:flex; align-items:center; gap:16px`.
- **Brand lockup (left):** 30×30 rounded square (`border-radius:7px`, `background: var(--f1-red)`), white "F1" in display font (weight 900, 13px). Next to it a two-line stack: "Frederikssund F1 Klub" (display, weight 800, 15px) over "Ops & automation" (12px, `--text-muted`).
- Vertical divider (1px × 26px, `--border-color`).
- **Section title:** GitHub brand icon (`fa-brands fa-github`, 18px, `--text-secondary`) + `<h1>` "Actions" (display, weight 800, 18px).
- Spacer, then the **control cluster** (right): three ghost icon-buttons — **theme toggle** (`fa-sun` in dark / `fa-moon` in light), **font toggle** ("Aa", accent font; red border when the brand-font stack is active), **language toggle** (`fa-globe` + current code `DA`/`EN`) — followed by a divider and the branch chip `main` (`fa-code-branch`, mono chip). Buttons: 34px tall, `background var(--bg-card)`, `border 1px solid var(--border-color)`, `border-radius var(--radius-md)`; hover → `background var(--bg-hover)`, `color var(--text-primary)`. See "Theming, fonts & language" below for behavior.

> **Screenshots** of the built states are in `screenshots/`: `01-dashboard-dark-da.png` (default), `02-run-log-expanded.png` (expanded run log), `03-dashboard-light-en.png` (light theme + English).

#### 2. Summary strip
- `display:grid; grid-template-columns: repeat(4,1fr); gap:14px; margin-bottom:22px`.
- Four stat cards, each: `background var(--bg-card)`, `border 1px solid var(--border-color)`, `border-radius var(--radius-lg)`, `padding 16px 18px`. Label row = icon + 12.5px weight-600 `--text-muted`; value = display font, weight 800, 30px.
  1. **Workflows** (`fa-diagram-project`) → count (9).
  2. **Runs · last 24h** (`fa-clock-rotate-left`) → count of runs started within 24h.
  3. **Success rate** (`fa-chart-line`) → `%`, value colored `--status-success-light`. Computed `success / (success+failure+cancelled)` across all shown runs, skipped excluded.
  4. **Failing now** (`fa-triangle-exclamation`) → count of workflows whose latest run = failure. Value colored `--status-danger-light` when > 0, else `--status-success-light`.

#### 3. Runs · last 12h (collapsible, collapsed by default)
A full-width card between the summary strip and the master/detail body. Lists **every run across all workflows started in the last 12h**, newest first.
- **Header is the toggle:** `fa-clock-rotate-left` (red) + title "Runs · last 12h" / "Kørsler · seneste 12t", right side = mono pill with the run count + a chevron (`fa-chevron-down` collapsed / `fa-chevron-up` open). Clicking the header toggles. **Default collapsed** — when collapsed the header's bottom border is transparent so the card reads as one closed bar; the body and its column header are not rendered.
- **Body** (when open): a column-header row + rows in a shared grid `grid-template-columns: 1fr 140px 210px 190px 90px`. The body scrolls at `max-height: 340px`. Columns: Workflow (icon + name, ellipsized) · Status (colored icon + label) · Trigger (icon + "Scheduled · actor") · Started (mono CET timestamp over muted relative "ago") · Duration (mono, right).
- Clicking a row **selects that workflow** in the master/detail below. Hover → `--bg-hover`. Empty state: "No runs in the last 12 hours."
- State: one `open12` boolean (persist it like the theme choice if you want the panel to remember its state).

#### 4. Body — two-column master/detail
`display:grid; grid-template-columns: 326px 1fr; gap:18px; align-items:start`. Collapse to single column at ≤768px.

**Left rail (aside)** — card, `position: sticky; top: 82px`, `overflow:hidden`.
- Header row: "All workflows" (display, weight 800, 15px) + a pill count chip (mono, `--bg-secondary`, `border-radius var(--radius-pill)`, `padding 2px 9px`). `border-bottom 1px solid var(--border-color)`.
- **Filter input:** full-width, `padding 9px 12px 9px 32px`, magnifier icon absolutely positioned left. `background var(--bg-secondary)`, `border 1px solid var(--border-color)`, `border-radius var(--radius-md)`, 13.5px. Focus: `border-color var(--f1-red)` + `box-shadow 0 0 0 3px rgba(225,6,0,0.20)`. Filters the list by workflow name (case-insensitive). Empty state: "No workflows match "{query}"." centered, `--text-muted`.
- **Workflow list** (scrollable, `max-height: calc(100vh - 240px)`): each row is `display:flex; align-items:center; gap:11px; padding:10px 11px; border-radius var(--radius-md); cursor:pointer`, with a `border-left: 3px solid` accent bar. Contents:
  - Status icon (16px, colored by last-run status — see Status tokens).
  - Name (weight 600, 13.5px, ellipsized) over a mono line showing the **last-run timestamp** (`fa-clock-rotate-left` + `"21. jul 02:00 CET"`, 11px, `--text-muted`).
  - Right: relative "ago" of last run (mono, 10.5px, `--text-muted`).
  - **Sort order:** the list is sorted by **run frequency, most-frequent first** (email hourly → content 6-hourly → the daily jobs → weekly → quali → monthly → the non-scheduled E2E last) — identical to the run-schedule matrix ordering. Compute a monthly run count per workflow and sort descending; non-scheduled workflows (E2E) go last.
  - **Selected state:** `background var(--bg-hover)`, left bar `var(--f1-red)`, name color `--text-primary`. Unselected: transparent bg, transparent bar, name `--text-secondary`.
  - Hover (any row): `background var(--bg-hover)`.

**Right column (main)** — two stacked cards.

**Detail card** (`padding 22px 24px`, `margin-bottom 16px`):
- Header: 44×44 icon tile (`border-radius var(--radius-md)`, `background var(--bg-secondary)`, `border 1px solid var(--border-color)`) holding the workflow's icon in `var(--f1-red)` (20px). Next to it: `<h2>` workflow name (display, weight 800, 22px) + a status line (colored status icon + "8 passed · 2 failed — last 10 runs"). Far right: mono chip of the workflow file path (`.github/workflows/<id>.yml`).
- **Two info panels** (`grid-template-columns: 1fr 1fr; gap:14px`), each `background var(--bg-secondary)`, `border 1px solid var(--border-color)`, `border-radius var(--radius-md)`, `padding 14px 16px`:
  - **Purpose** — red uppercase micro-label (`fa-bullseye`) + paragraph (13.5px, line-height 1.55, `--text-secondary`).
  - **Expected result** — red uppercase micro-label (`fa-flag-checkered`) + paragraph.
- **Meta chip row** (`flex; flex-wrap; gap:10px; margin-top:14px`). Each chip: `background var(--bg-secondary)`, `border 1px solid var(--border-color)`, `border-radius var(--radius-md)`, `padding 9px 13px`, icon + two-line (uppercase 10.5px micro-label / 13px value):
  - **Schedule** (`fa-calendar-days`) — human text, e.g. "Daily 01:00 UTC".
  - **Cron** (`fa-terminal`) — cron string in mono, e.g. `0 1 * * *` (or `—` for non-scheduled).
  - **Trigger** (`fa-clock`/`fa-code-pull-request`/…) — "Scheduled" / "Pull request" / "Manual".
  - **Next scheduled run** — visually emphasized: `border 1px solid var(--f1-red)`, icon + micro-label in `var(--f1-red)`. Value is mono timestamp + muted relative, e.g. `22. jul 02:00 CET · om 11t`. For non-scheduled workflows: "On next pull request · on demand". Compute from last run start + interval, advanced forward until it's in the future.

**Runs card** ("Last 10 runs"):
- Header: `<h3>` "Last 10 runs" (display, weight 800, 15px) + helper text "Click a run to see its output" (12px, `--text-muted`). `border-bottom 1px solid var(--border-color)`, `padding 15px 20px`.
- **Column header row** + each **run row** share `display:grid; grid-template-columns: 150px 78px 1fr 130px 92px 30px; padding: …20px`. Column labels (uppercase 10.5px, `--text-muted`): Status · Run · Trigger · Started · Duration(right) · (chevron).
- **Run row** (`padding 12px 20px`, `border-bottom 1px solid var(--border-color)`, `cursor:pointer`; hover + open → `background var(--bg-hover)`):
  - **Status:** colored icon + label (13px weight 600) in the status color.
  - **Run:** `#306` mono, `--text-secondary`.
  - **Trigger:** trigger icon + "Scheduled · github-actions[bot]" (or human actor), ellipsized, 12.5px `--text-secondary`.
  - **Started:** relative "ago" mono 12px `--text-muted`.
  - **Duration:** mono 12.5px, right-aligned, e.g. `5m 56s`, `48s`.
  - **Chevron:** `fa-chevron-down`, flips to `fa-chevron-up` when open.
- **Expanded log panel** (shown when a row is open): `background var(--bg-secondary)`, `padding 14px 20px 18px`.
  - Meta line (12px `--text-muted`, `flex; gap:16px`): actor (`fa-user`), branch (`fa-code-branch`), short SHA (`fa-hashtag`, mono), full start time (`fa-clock`, mono).
  - **Console block:** `background var(--bg-primary)`, `border 1px solid var(--border-color)`, `border-radius var(--radius-md)`, `padding 12px 14px`, mono 12px, line-height 1.75, `overflow-x:auto`. Each line = a muted `HH:MM:SS` timestamp prefix + the message, colored by level:
    - step OK → `✓ Step name  (Ns)` in `--text-secondary`
    - success summary → `✓ Complete — …` in `--status-success-light`
    - failure → `✗ Step`, indented error message, `##[error]Process completed with exit code 1`, all in `--status-danger-light`
    - cancelled → `■ Step — cancelled by <actor>` + `##[warning]The run was cancelled` in `--status-warning-light`
    - skipped → single `Skipped — <reason>` in `--text-muted`
    - "Set up job" prelude in `--text-muted`.

#### 6. Run-schedule matrix (full-width panel, below the master/detail)
A month-at-a-glance heat matrix answering "how often / on which days does each action run?" — this is the schedule/timetable overview.
- **Header:** `fa-calendar-week` (red) + "Run schedule · Juli 2026" / "Kørselsplan · Juli 2026". Right side = a **heat legend** (three swatches: `1×` `rgba(225,6,0,0.38)`, `few` `0.65`, `many` `0.95`) then a divider and a **collision key** (`fa-triangle-exclamation` red + "3+ jobs at same time").
- **Layout:** each row is a grid `grid-template-columns: 196px 84px 1fr`: action label (icon + name over mono cadence) · **Time (CET)** column (exact fire time(s), e.g. `02:00`, `1/7/13/19`, `:00 · hourly`) · a day grid `repeat(31, 1fr)` of cells. The panel body scrolls horizontally (`min-width: 720px`) — horizontal scroll on mobile is acceptable/expected.
- **Day-axis header:** per day a small stacked cell — weekday letter (10px, weight 800) over day number (10px). **Weekends are highlighted**: weekday letter in `var(--f1-red)`, cell background `rgba(225,6,0,0.08)`; today's number in red. (This is the readability treatment for day-of-week.)
- **Heat cells:** filled red with opacity by that day's run count (`>=24 → 0.95`, `>=4 → 0.65`, `>=1 → 0.38`, else the empty/neutral bg with a faint red tint on weekend columns). Today's column cells get a `--text-primary` border. Every cell has a `title` tooltip: `‹name› · ‹d›. jul · ‹time› · ‹N›×`.
- **Rows are sorted most-frequent-first** (same ordering that drives the left rail). Only scheduled workflows appear here (E2E is excluded — it's PR/manual, not time-scheduled).
- **Collision row:** a dedicated strip (label "Collisions" / "Kollisioner" with a red `fa-triangle-exclamation`) with one cell per day. A cell shows a solid-red ⚠ marker on days where **3+ workflows start at the same clock time** (in the sample data: every Monday, where email + content top-up + weekly challenges all fire at 07:00 CET). Tooltip lists the colliding time and jobs, e.g. `07:00 — Cron — Email Notifications, Cron — Content Top-up, Cron — Weekly Challenges (3)`. Non-collision days show only the faint weekend tint. Note the hourly mailer overlaps every job by nature — the collision detector only flags **3+ concurrent starts** to keep the signal meaningful.
- Clicking a matrix row also selects that workflow in the master/detail.
- Footer note (muted, `fa-circle-info`): explains sorting + hover + the collision definition.

## Theming, fonts & language
All three are runtime toggles in the header and are also exposed as props on the component (`theme`, `fontSystem`, `lang`, plus `defaultOpenLogs`). Implement them by toggling classes on the page root and swapping a string table — everything else reads CSS variables and the table.

- **Theme (dark / light):** toggles the root class between `.theme-dark` and `.theme-light` (the design system also ships `.theme-clubhouse` — combine as `theme-clubhouse theme-dark/-light`). Every color in the UI is a `var(--*)` token that both themes redefine, so no per-element color changes are needed. **Default: dark.** The live site persists the user's choice (localStorage / cookie) and mirrors it on `<body>` — do the same.
- **Fonts (AC-FONT-01):** the brand stack (Stack A = Kalam accent + Courier Prime mono) vs a system stack (Stack B). Toggled by adding/removing the `font-system` class on the root — `colors_and_type.css` redefines `--font-accent`/`--font-mono` (and their `--accent`/`--mono` aliases) under `body.font-system`. Do **not** swap individual elements; flip the one class. It is an all-or-nothing swap.
- **Language (Danish / English):** Danish is the **default** (the site is DA-first with an EN toggle). All chrome, status labels, trigger labels, the runs summary, relative timestamps (`for 3t siden` / `3h ago`, `om 21t` / `in 21h`), and the date format (`23. jul 11:42 UTC` / `Jul 22, 11:42 UTC`) switch. Per-workflow **purpose / expected result / schedule** copy is translated too (both DA and EN strings are in the prototype's `strings()`, `daWf()`, and `data()` methods — lift them verbatim). Wire this to the existing bilingual system: `public/lang/user.php` already holds the DA+EN copy strings; add these keys there. **Console log text stays English** in both languages (real CI logs are English) — only the surrounding UI is localized.

## Interactions & Behavior
- **Select workflow:** click a left-rail row — or a row in the 12h table or the schedule matrix — to re-render the detail + runs cards for that workflow. Default selection on load: `nightly-tests` (in production, default to the first workflow or the most recently run one).
- **Collapse 12h table:** click its header to toggle; **collapsed by default** (`open12=false`).
- **Expand run:** click a run row → toggles its log panel open/closed. Multiple can be open at once. Chevron reflects state. (Optional tweak `defaultOpenLogs` auto-opens the newest run's log.)
- **Filter:** typing in the search box filters the workflow list live by name.
- **Sort:** left rail and schedule matrix are both sorted by run frequency (most-frequent first); the 12h table is sorted by start time (newest first).
- **Hover:** rows (workflow, run, 12h, matrix) go to `--bg-hover`; matrix/collision cells get a red outline. Cards in the source system also lift on hover (`translateY(-2px)` + red-tinted shadow) — optional here since these are dense list items.
- Transitions: `background 0.15s`. No parallax/springs. Keep it calm per the design system.
- **Responsive:** ≤768px → grids collapse to a single column (`grid-template-columns: 1fr`). Summary strip stacks to 2×2 or 1 column; run-row grid should reflow; the schedule matrix keeps its 31-column grid and scrolls horizontally.

## State / Data
State needed (client-side for the interactive bits; the rest is server-fetched):
- `selectedWorkflowId` — which workflow's detail is shown.
- `openRuns` — set/map of expanded run keys.
- `open12` — whether the 12h-runs table is expanded (default false).
- `query` — filter text.
Derived per workflow: a **monthly run count** (for the frequency sort of the left rail and matrix) and **per-day run counts** (for the matrix heat + collisions), computed from the cron schedule.
Per-workflow **static config** (not from the GitHub API), keyed by workflow filename:
- `purpose` (string), `expected` (string), `schedule` (human string, CET), `cron` (string, UTC — literal YAML), `icon` (Font Awesome name), and the exact CET fire time(s) for the Time column.
The 9 workflows and their config (as used in the prototype). **Schedule/Time are CET; Cron is the literal UTC expression:**

| id / file | Icon | Schedule (CET) | Time (CET) | Cron (UTC) |
|---|---|---|---|---|
| content-topup — Cron — Content Top-up | fa-newspaper | Every 6 hours | 1/7/13/19 | `0 */6 * * *` |
| email-notify — Cron — Email Notifications | fa-envelope | Hourly | :00 · hourly | `0 * * * *` |
| quali-import — Cron — Qualifying Results Import | fa-flag-checkered | Sat 15:00 CET · race weekends | Sat 15:00 | `0 14 * * 6` |
| weekly-challenges — Cron — Weekly Challenges | fa-bolt | Mondays 07:00 CET | Mon 07:00 | `0 6 * * 1` |
| e2e — E2E Orchestrator (test env) | fa-vial | On pull request · manual | — | `—` |
| sec-review — Monthly Security Review | fa-shield-halved | 1st of month 04:00 CET | 1st 04:00 | `0 3 1 * *` |
| db-backup — Nightly DB Backup | fa-database | Daily 03:00 CET | 03:00 | `0 2 * * *` |
| nightly-tests — Nightly Tests & Security Scan | fa-flask | Daily 02:00 CET | 02:00 | `0 1 * * *` |
| kb-update — Paddock Rumors — KB Update | fa-comments | Daily 09:00 CET | 09:00 | `0 8 * * *` |

(Full purpose/expected-result copy for each workflow lives in the `data()` (EN) and `daWf()` (DA) methods of the prototype — copy them verbatim into your config.)

## Design Tokens
From `colors_and_type.css` (dark theme). Reference the CSS variables directly — do not hardcode where the site already defines them.

**Colors**
- Brand: `--f1-red #e10600`, `--f1-red-light #ff1a14`, `--f1-red-dark #b30500`
- Status: success `--status-success-light #10b981` / `--status-success #059669`; warning `--status-warning-light #f59e0b`; danger `--status-danger-light #ef4444` / `--status-danger #dc2626`; neutral `--status-neutral #6b7280`
- Dark bg ramp: `--bg-primary #131316`, `--bg-secondary #1c1c20`, `--bg-card #232328`, `--bg-hover #2d2d33`
- Text: `--text-primary #f5f5f7`, `--text-secondary #b8b8be`, `--text-muted #8e8e95`
- Border: `--border-color #34343a`
- Light theme + "clubhouse" variants also exist (see `colors_and_type.css`); the prototype exposes a `theme` switch (dark / light / clubhouse-dark / clubhouse-light) applied as a class on the root.

**Typography**
- Display (headings, badges): `--font-display` (system-ui stack; the brand's Chivo where available), weights 700–900.
- Body: `--font-body` (system-ui stack).
- Mono (timestamps, cron, run #, SHA, durations, logs): `--font-mono` = `'Courier Prime', ui-monospace, monospace`, `font-variant-numeric: tabular-nums`, weight 700 for chips.
- Scale used here: h1 18px, h2 22px, h3/section 15px, stat value 30px, body 13–13.5px, meta 12–12.5px, micro-label 10.5–11.5px.

**Radii:** `--radius-sm 6px`, `--radius-md 8px` (buttons/inputs/chips), `--radius-lg 12px` (cards), `--radius-pill 9999px`.

**Spacing:** 4pt base. Card padding 14–24px; grid gaps 14–18px.

**Shadows:** none at rest. Card hover (if used): `0 8px 24px rgba(225,6,0,0.15)`. Input focus ring: `0 0 0 3px rgba(225,6,0,0.20)`.

## Assets
- **Icons:** Font Awesome 6 Free (solid + brands). The live repo self-hosts it in `public/assets/fontawesome/`; the prototype loads the 6.5.2 CDN. Use the self-hosted copy in production. Icon names are listed above.
- **Logo:** the prototype fakes the mark with a red "F1" tile; swap for the real `assets/logo_header_dark.png` / `logo_header_light.png`.
- No images/photography.

## Files
- `Actions Dashboard.dc.html` — the design reference (this bundle). Open in a browser to see it live. All layout values, the 9-workflow config, purpose/expected copy (DA + EN), CET conversion, the schedule/collision logic, and the log-line formatting live in its template + `strings()`/`daWf()`/`data()`/`schedDefs()`/`buildSchedule()`/`renderVals()` logic.
- `screenshots/01-dashboard-dark-da.png` — default state (dark theme, Danish).
- `screenshots/02-run-log-expanded.png` — a run row expanded to show its per-step log.
- `screenshots/03-dashboard-light-en.png` — light theme + English.
- `screenshots/04-schedule-matrix.png` — the run-schedule matrix with the collision row and weekend highlighting.
- `screenshots/05-runs-12h-expanded.png` — the 12h-runs table expanded.
- Design system reference (in the main project, not bundled): `_ds/frederikssund-f1-klub-design-system-…/colors_and_type.css` and `styles.css`; live equivalents in the repo at `public/assets/css/style.css`.
