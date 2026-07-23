# Implementation Plan — GitHub Actions Dashboard

Refined from `README.md` (design handoff) via `/web-architecture-review`, adapted to this
project's actual conventions (procedural PHP, no framework). Deviations from the handoff doc
are called out explicitly with the reason.

## Architecture decisions (deviations from the handoff doc)

1. **Page location & name:** `public/admin-actions.php`, not `public/admin/actions.php`. The
   site has no `public/admin/` directory — admin surfaces are flat `admin*.php` files
   (`admin.php`, `admin-challenges.php`), added to the shared `.admin-area-nav` switcher.
   Follows the same "standalone page, `?tab=`-less" shape as `admin-challenges.php`.
2. **Theme/font/lang toggles are NOT reimplemented.** The site already has a global,
   cookie-persisted theme/font/lang system (`header.php`'s `?toggle_theme=1` /
   `?toggle_lang=1` / `?toggle_font=1` links, `getTheme()`/`setTheme()`/`getFont()`/
   `setFont()`/`getLang()`/`setLang()` in `functions.php`). The header's control cluster in
   the design reuses these exact links instead of introducing page-local JS state — this also
   means CSS should key off `body.dark`/`body.light`/`body.clubhouse`, not `.theme-dark` /
   `.theme-light` / `font-system` as the handoff doc names them (those class names don't
   exist in this codebase; `body.dark`/`body.light` + optional `body.clubhouse` do).
3. **Design tokens partially don't exist yet** — `--radius-sm/md/lg/pill` and
   `--status-success-light` / `--status-warning-light` / `--status-danger-light` /
   `--status-neutral` are assumed by the handoff doc (from the separate, unmerged
   `colors_and_type.css`) but are **not** currently defined in `public/assets/css/style.css`.
   The closest existing tokens are `--r-sm/md/lg/xl` (defined in an unrelated `:root` block
   for a broadcast-card feature) and `--status-success/warning/danger` (no `-light` variants;
   two call sites already fall back to hardcoded hex via `var(--status-success-light,
   #10b981)`). Plan adds the missing tokens properly under `body.dark`/`body.light`/
   `body.clubhouse.*` (real values, not more fallback hex), rather than hardcoding colors in
   the new page's CSS. Small, additive, reusable by future features — not scope creep.
4. **No DB tables.** Everything is server-fetched from the GitHub REST API plus a static PHP
   config array (purpose/expected/schedule/icon per workflow) and computed CET/cron
   derivations. Matches the handoff doc's own "state is client-side for interaction only, rest
   is server-fetched" note.
5. **The handoff doc's 9-workflow schedule table is fictional/stale — verified against the
   real `.github/workflows/*.yml` files and rejected.** Cross-checking each workflow's actual
   `on.schedule.cron` against the epic's table (§"The 9 workflows and their config") found it
   wrong for most of them, not just imprecise:
   - `content-topup`: real cron is `0 6 * * 5` (**weekly, Friday** 06:00 UTC — matches
     `docs/github-actions.md`'s Content Top-up section), not the table's "every 6 hours."
   - `quali-import`: real cron is `*/5 6-23 * * 6` (**every 5 minutes, all day Saturday**,
     06:00–23:55 UTC), not a single 15:00 CET fire.
   - `sec-review`: real cron is `17 8 1 * *` (08:17 UTC), not `0 3 1 * *` (03:00 UTC) — 5+
     hours off.
   - `weekly-challenges`: real cron is `0 5 * * 1`, not `0 6 * * 1`.
   - `db-backup`: real cron is `0 1 * * *`, not `0 2 * * *`.
   - `kb-update` (`paddock-rumors.yml`): **seven** distinct `schedule:` cron lines tied to the
     race-weekend results/analysis cycle (Sat/Sun/Mon/Tue/Wed/Thu, several times a day on some
     of those), not one daily 09:00 line.
   - `e2e`: real trigger is `workflow_dispatch` only (confirmed by `docs/github-actions.md`:
     "manual only... no schedule"), not "on pull request."
   - `nightly-tests` was the one entry that checked out exactly (`0 1 * * *`).

   Consequence: a hardcoded per-workflow "interval shape" (hourly / every-6h / daily / etc.)
   can't represent `kb-update`'s seven-line, race-weekend-driven schedule or `quali-import`'s
   5-minute polling window without becoming per-workflow bespoke logic anyway — at which point
   it's no simpler than the real thing. Plan instead: store the **actual, verified cron
   string(s)** per workflow (copied from the `.yml` files above, one or many per workflow) as
   the static config, plus one small **generic 5-field cron evaluator**
   (`ghCronFireTimes(string $cron, DateTime $utcDate): array` — minute/hour/day/month/weekday
   fields, supporting `*`, lists, ranges, and `*/n` steps; no macros, no seconds) that expands
   a cron string to its exact UTC fire times (`HH:MM`) on a given date. Per-workflow day counts,
   the matrix heat, the "Time" column, and collision detection (union of fire-time sets across
   workflows per day, flagged at 3+) all fall out of that one function applied per workflow per
   day — `kb-update` just supplies 7 cron strings instead of 1, no special-casing needed.
   **Real consequence for the design's "Monday 07:00 collision" scenario:** it doesn't
   reproduce with real crons (`weekly-challenges` Monday 05:00 UTC and `content-topup` Friday
   06:00 UTC are different weekdays entirely, and `email-notify`'s hourly `1 * * * *` fires at
   minute :01, one minute off `weekly-challenges`' :00). The matrix may legitimately show zero
   collision days most months — that's the honest output of computing from real data, not a
   bug to work around by fudging a fixture to match the mock's story.
   **Purpose/expected copy is likewise not lifted verbatim from the handoff doc** (its own
   instruction) where verification shows it describes different behavior than the real
   workflow — most notably `content-topup`, whose mock copy says "refreshes homepage blurbs";
   the real workflow (`docs/github-actions.md`'s Content Top-up section) generates and
   auto-publishes Rumor-or-Not/Trivia items via Claude. New copy is written per workflow from
   `docs/github-actions.md`, in the same tone/length as the handoff's copy, DA translated
   alongside.
6. **CET via `Europe/Copenhagen`, not a flat +1h.** `config.shared.php` already calls
   `date_default_timezone_set('Europe/Copenhagen')` app-wide, so `DateTime`/`date()` already
   follow DST correctly (CET in winter, CEST in summer) with no extra code. The handoff doc's
   own note flags the flat +1h as a prototype shortcut to fix in production — this plan does
   that from the start.
7. **Per-step raw log text (the zip download endpoint) is out of scope for v1.** Rendering the
   console panel from `GET .../actions/runs/{run_id}/jobs` (step name, status, conclusion,
   timestamps — already enough to reproduce the mock console lines: "✓ Step name (Ns)",
   failure/cancelled/skipped states) avoids downloading+unzipping raw logs for every expanded
   run. Full raw log text is a documented stretch goal (`## Deferred` below), not silently
   dropped.
8. **GitHub calls are server-side only, via a small curl-based client + file cache** — same
   shape as the existing `F1Intelligence` PHP client (`public/f1-intelligence/F1Intelligence.php`).
   The browser never talks to `api.github.com` directly (the site's CSP is `connect-src
   'self'`, so it couldn't anyway); all fetches go through `admin-actions.php` itself.
9. **Eager vs. lazy fetching, to respect GitHub's rate limit:**
   - Eager (on every page load, 60s file cache): last-10-runs per workflow, 9 calls — no
     separate "list workflows" call needed at all, since the 9 filenames are already fully
     known from the static config (decision #5), so `GET .../workflows/{file}/runs` is called
     directly per workflow. Comfortably inside both the unauthenticated (60/hr/IP) and
     authenticated (5000/hr) budgets even without a token, but a token is strongly recommended
     (see Prerequisites) since the *shared-hosting IP* means other Simply.com tenants share
     the unauthenticated quota.
   - Lazy (only when a run row is expanded, via same-origin AJAX): `GET
     .../actions/runs/{run_id}/jobs`, cached indefinitely for completed runs (immutable) and
     15s for in-progress runs.
10. **No bespoke sticky sub-header with duplicate theme/font/lang buttons.** `admin.php` and
    `admin-challenges.php` neither have one — they render an `<h1>` + `.admin-area-nav`
    switcher inside the standard `header.php`/`footer.php` chrome, and the site's only
    theme/lang toggle controls live in `header.php`'s drawer (decision #2). Reproducing the
    design's separate visible theme/font/lang buttons would duplicate that control and be the
    one piece of chrome on this page inconsistent with every other admin surface. The page
    keeps the design's GitHub icon + "Actions" title (as the `<h1>`) and the `main` branch
    chip (cheap, on-theme, and free of duplicate controls) but drops the rest of the bespoke
    header bar.

## Prerequisite (manual, Djarnis)

A GitHub PAT with `actions:read` (fine-grained) or `repo` (classic) scope on
`Djarnisdrengen/F1Betting`, added as `GITHUB_TOKEN` to `config.test.php` and
`config.live.php` (not committed — same as every other secret in that file). The page
degrades to unauthenticated calls if unset (logs a warning once per cache miss), but the
recommendation is a token from day one. I cannot generate this — flagging per
`config.example.php`'s own "Ask Djarnis" convention.

## Files

**New:**
- `public/admin-actions.php` — page controller: GET renders HTML; `?ajax=run_jobs&run_id=`
  branch (admin-gated, same file, mirrors `public/f1-intelligence/query.php`'s
  GET/POST-branch-in-one-file shape) returns JSON step list for one run, no CSRF needed (GET,
  read-only, same pattern as other pure-read admin fetches).
- `public/includes/actions-dashboard.php` — helpers: `ghApiGet()` (curl + file-cache wrapper),
  `ghListWorkflowRuns()`, `ghListRunJobs()`, the 9-workflow static config array (icon, cron,
  interval descriptor, CET fire time(s)), `ghComputeSchedule()` (monthly run count + per-day
  counts + collisions from the interval descriptors — pure function, no API calls), `ghNextRun()`.
- `public/cache/github-actions/` — new gitignored dir for the file cache (`.gitkeep` +
  `.htaccess` deny, copied verbatim from `public/logs/.htaccess`).
- `tests/e2e/admin/14-actions-dashboard.spec.js`.
- `tests/unit/actions-schedule-harness.php` — CLI harness (mirrors
  `tests/unit/hero-window-harness.php`'s style: loads `actions-dashboard.php` standalone, no
  DB/config, `check()`-based assertions) for `ghComputeSchedule()`. Wired into
  `npm run test:unit`.
- Fixture: `public/includes/actions-dashboard-mock.json` (see Testing below — deliberately
  **not** under `tests/fixtures/`, which is the Playwright auth-fixture module's home, not a
  data-fixture directory).

**Edited:**
- `public/includes/header.php` and/or `public/admin.php` / `public/admin-challenges.php` —
  add a third `.admin-area-tab` entry for `admin-actions.php` to the switcher.
- `public/lang/admin.php` — new `admin_actions_*` keys (da + en), appended, checked against
  existing keys for accidental duplicates before merging (a prior duplicate-key bug in
  `user.php`/`email.php` in this array-literal style was found before — last key silently
  wins with no error).
- `public/assets/css/style.css` — new tokens (see decision 3) + a scoped `.gha-*` block for
  the dashboard's layout (summary cards, master/detail, run rows, schedule matrix), reusing
  `--bg-card`/`--bg-secondary`/`--border-color`/`--text-*`/`--f1-red` etc. throughout instead
  of introducing a parallel palette.
- `config.example.php` — document `GITHUB_TOKEN` (commented, with the scope note).
- `docs/github-actions.md` — new section documenting the dashboard page, its cache, and the
  token prerequisite.
- `CLAUDE.md` — its doc table already lists `docs/github-actions.md` ("CI workflows"); update
  that one-line description to also mention the new admin dashboard.

## Phased build order

**Phase 1 — backend foundation (no UI yet)**
- `GITHUB_TOKEN` wiring + config example doc.
- `actions-dashboard.php`: curl client with file cache, 9-workflow static config, schedule/
  collision computation, CET formatting via `Europe/Copenhagen`.
- Smoke-verify against the real API with a throwaway CLI (`php -r`) call, not committed.

**Phase 2 — page shell + summary + master/detail**
- `admin-actions.php` GET path: header, summary strip, left rail (workflow list, no filter JS
  yet), detail card, runs card (rows render, no expand yet).
- CSS tokens + `.gha-*` styles, lang keys, admin-area-nav entry.

**Phase 3 — interactions**
- Filter input (vanilla JS, client-side over server-rendered data attributes — no reload).
- Workflow selection (left rail / 12h table / matrix rows all select into the same detail
  view — reload via `?workflow=` query param is simplest and matches the rest of the site's
  no-SPA convention, rather than a client-side re-render of the detail card).
- 12h table collapse/expand + collision-cell tooltips (native `title`, no JS).
- Run-row expand → lazy `?ajax=run_jobs` fetch + render.

**Phase 4 — tests + docs**
- PHP unit harness for the schedule/collision math (no UI needed).
- Playwright spec against the mock fixture (see below).
- `docs/github-actions.md` section, `CLAUDE.md` doc-table row.

## Testing approach

Reviewed via `/test-manager` — verdict **APPROVE WITH CONDITIONS**; all conditions folded in
below. Original proposal (E2E-only, fixture path under `tests/fixtures/`, and one merged
"selection sync" assertion) was revised on three points; see `git log` on this file if the
prior draft is wanted for reference.

**Unit level (`tests/unit/actions-schedule-harness.php`, `php tests/unit/*-harness.php`
style, DB-free):** two layers of pure computation, both DB/API-free and fast:

`ghCronFireTimes()` (the generic evaluator, decision #5):
- `1 * * * *` (email-notify) → 24 fire times/day, all at `:01`.
- `0 6 * * 5` (content-topup) → 1 fire time, Fridays only, zero on other weekdays.
- `*/5 6-23 * * 6` (quali-import) → 216 fire times on a Saturday (18h × 12/h), zero on other
  days.
- `17 8 1 * *` (sec-review) → 1 fire time on the 1st of the month only.
- one of `kb-update`'s 7 lines, e.g. `0 18 * * 3` → 1 fire time, Wednesdays only.

`ghComputeSchedule()` (monthly/per-day counts + collisions, built on the evaluator):
- monthly total for `nightly-tests` (`0 1 * * *`) = the month's day count (one/day).
- `kb-update`'s per-day count on a Saturday sums fire times from just the two Saturday-tagged
  lines (`0 12,13 * * 6`), not all seven.
- collision detection against the **real** cron set returns **no** collisions for a month with
  no 3-way same-minute overlap (see decision #5) — i.e. explicitly assert the collision list
  can legitimately come back empty, so a future regression that fakes a collision to "look
  more interesting" gets caught.
- a synthetic 3-workflow same-UTC-minute overlap (constructed in the test, not from the real
  9) does flag; a synthetic 2-workflow overlap does not (threshold is 3+, not 2+).
- `e2e` (no `cron:` line, `workflow_dispatch` only) excluded from the matrix entirely.

**E2E level (`tests/e2e/admin/14-actions-dashboard.spec.js`):** GitHub's live API is
unsuitable for E2E — non-deterministic run history, rate limits shared with other Simply.com
tenants, network flakiness in CI. Gate a fixture mode the same way `admin.php`'s existing
E2E test-mode is gated (`INTEGRATION_SEED_TOKEN`-matched `e2e_token`) — when present alongside
a new `e2e_gh_fixture` flag, `ghApiGet()` reads `public/includes/actions-dashboard-mock.json`
instead of calling `curl`. **Live safety:** `ghApiGet()` must check
`defined('INTEGRATION_SEED_TOKEN') && $_GET['e2e_token'] === INTEGRATION_SEED_TOKEN` — the
exact same two-part gate `admin.php`'s `$testMode` uses — *before* it even inspects
`e2e_gh_fixture`, so the fixture path is structurally unreachable without a matching token
(and live's token differs from test's, per existing convention). The mock JSON should mirror
real GitHub API field names/shape (not a simplified ad hoc shape), including at least one
`in_progress` run, so a future schema diff against real output stays easy.

Test cases:

- summary stat math (workflow count, 24h run count, success-rate — confirm `skipped` **and**
  `in_progress` are excluded from the success/failure ratio), failing-now count + coloring.
- `e2e_gh_fixture=error` variant: simulated curl/HTTP failure still renders page chrome with a
  visible error/stale-data state, not a blank page or fatal error.
- filter input narrows the rail list live; empty-state message when nothing matches.
- selection sync — **three separate assertions**, one per entry point (rail row, 12h-table
  row, matrix row), each confirming the detail/runs cards update to the clicked workflow.
- 12h table default-collapsed, toggles open/closed, empty state when nothing ran in 12h.
- run row expand → lazy AJAX fetch renders step list; second expand of the same completed run
  does not re-fetch (cache hit); collapse/re-expand toggles chevron.
- `?ajax=run_jobs` endpoint is independently admin-gated — a logged-out/non-admin request
  redirects/403s the same as the main page (separate code path from the GET render, not
  covered "by implication").
- collision cell tooltip present on the flagged Monday, absent elsewhere.
- DA/EN toggle — spot-check 2–3 representative strings (a status label, a relative-time
  string, one schedule human string), not full enumeration.
- non-admin/logged-out access to `admin-actions.php` itself is rejected.

## Deferred (not in this plan, flagged for a future pass if wanted)

- Full raw per-step console log text via the `.../logs` zip endpoint (decision 7).
- Parsing `on.schedule.cron` directly out of the `.yml` files instead of a hardcoded
  descriptor (would auto-track schedule changes, at the cost of a YAML parser dependency this
  project doesn't currently have).
