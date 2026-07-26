# Implementation Plan — Admin Area Redesign (Navigation Framework + Layout Primitives)

Refined from `README.md` (design handoff, `Admin Settings.dc.html`) via `/web-architecture-review`,
adapted to this project's actual conventions (procedural PHP, no framework, no MVC/repository layers).
This epic builds directly on the already-shipped `epics/Archive/Admin settings and dashboards/plan.md`
(three top-level pages, `renderAdminAreaNav()`, the five Dashboards tabs — see `docs/admin-dashboards.md`).
It does **not** redo that work; it unifies the nav pattern across both nav levels and extends one
consistent visual language (layout primitives) across all 17 screens, all three areas.

## Architecture decisions

1. **One shared nav-rendering path for both levels, not a second parallel system.** The prior epic
   already centralized Level-1 (area switcher) into `renderAdminAreaNav()` in
   `public/includes/admin-area-nav.php`; Level-2 (per-area tab row) is still hand-copied inline in
   `admin.php`, `admin-challenges.php`, and `admin-dashboards.php`. Rather than building the new
   `.admin-tabs`/`.admin-dropdown` container-query pattern three more times by hand (the exact drift
   risk the README's "17 screens as one system" goal is trying to avoid), extend
   `admin-area-nav.php` with a second helper, `renderAdminTabRow(string $groupKey, string $activeKey,
   array $items)`, that emits the `.admin-tabs` row **and** the `<details class="admin-dropdown">`
   markup together from one `$items` array (`key`, `label`, `icon`, optional `count`). Both nav levels
   call this — Level 1 becomes `renderAdminTabRow('area', $activeArea, [...])` (folded into/reusing
   `renderAdminAreaNav`'s existing signature), Level 2 replaces each page's inline tab-row block with a
   single call. One function to test, not five hand-copies to keep in sync across 17 screens.

2. **The container-query mechanics are additive on infrastructure that already exists.**
   `public/assets/css/style.css:1713-1716` already declares `.admin-shell { container-type:
   inline-size; container-name: admin-vp; }` from the prior epic — it's just wrapping flex-wrap pills
   today, not a dropdown. No new CSS architecture is needed, only new rules under
   `@container admin-vp (max-width: 720px)` (verified to match the prototype exactly —
   `Admin Settings.dc.html:26-47`). Browser support (Chrome/Edge 105+, Safari 16+, Firefox 110+, all
   2022+) needs no fallback per the README; this is a hobby-scale admin tool with a single known
   admin user base, not public-facing.

3. **CSS-class test coupling is a verified, concrete regression risk — must be fixed in the same
   commit as the rename, not as a follow-up.** Checked directly against the real spec files rather
   than assumed, and re-verified across the *full* `tests/e2e/` tree (not just the admin subfolder)
   during the test-strategy-manager pass — the first check under-scoped this:
   - `tests/e2e/admin/15-dashboards-nav.spec.js`, `16-dashboards-oversigt.spec.js`, and
     `14-actions-dashboard.spec.js` assert directly on `.admin-area-tab` / `.admin-area-tab.active` /
     `.admin-nav-tab` / `.admin-nav-tab.active` as CSS selectors — not `data-testid`.
   - `tests/e2e/admin/10-content.spec.js`, `11-invites.spec.js`, `12-users.spec.js`, **and
     `tests/e2e/challenges/46-admin-challenges.spec.js`** (Paddock Challenges → Members, not just
     Core) use `.card` / `.card.mb-1` as their row-scoping base locator.
   **Decisions:**
   - Renaming the nav classes to `.admin-tabs`/`.admin-tab` happens **together with** updating those
     three spec files' selectors in the same PR — never left for later. While touching them, add
     `data-testid="admin-tab"` (and `data-testid="admin-area-tab"` if the two levels stay visually
     distinct) so the next redesign doesn't re-couple tests to styling class names.
   - For the dense list-row primitive (3-line stacked pattern), **`.card` / `.card-body` stay as the
     stable outer wrapper on every reskinned row, in every area** — Core (Users, Invites) and Paddock
     Challenges (Members) alike — the new primitive is nested *inside* them as an additional inner
     class, never replacing them. This means `10-`, `11-`, `12-users`, and `46-admin-challenges` specs
     need **zero locator changes** for the row-restructuring work itself, only for the nav.

4. **Layout primitive consolidation retires two parallel naming families — but the Dashboards side is
   NOT a risk-free rename, unlike the first draft of this decision claimed.** Today: Dashboards tabs
   use `.gha-stat-card` / `.dash-status-card` / `.gha-detail-card` / `.gha-runs-card` (named after the
   GitHub Actions sub-epic that introduced them first); Core/Paddock Challenges use only the generic
   `.card` / `.card-body`. **Correction from the test-strategy-manager pass:** the first draft of this
   plan checked only for `.gha-*`/`.dash-*` *container* classes and found no test coupling — it missed
   that several *inner* elements within those containers are directly test-coupled:
   - `tests/e2e/admin/17-dashboards-nogler-rotation.spec.js` locates `.dash-health-score`,
     `.gha-stat-value` (×6), and `.gha-panel`.
   - `tests/e2e/admin/18-dashboards-paddockkb.spec.js` locates `.gha-stat-value`, `.dash-run-row`,
     `.dash-cat-row`, `.dash-fresh-dot`.
   - `tests/e2e/admin/19-dashboards-challenges-usage.spec.js` locates `.gha-stat-value` (×4) and
     `.gha-panel`.
   **Decision:** the Dashboards rename is still worth doing (one canonical primitive set beats two),
   but it is **Phase 2's own responsibility to update `17-`, `18-`, and `19-` in the same commit** —
   it is not a free rename the way the container-class-only check first suggested. Introduce
   `.stat-card` + `.stat-card-grid` (with `.stat-card-value` as the direct replacement for
   `.gha-stat-value`), `.section-card` (replacing `.gha-panel`/`.gha-detail-card`/`.gha-runs-card`),
   and `.admin-page-header` as the one canonical set; apply the same classes to Core/Challenges pages
   that don't have a stat-card-grid yet.

5. **Settings page: the mock's "one save bar for everything" framing doesn't fit this page's real
   data model — scope it to what's actually true.** Checked `public/includes/admin/settings.php`:
   it is **three independent `<form>` elements today**, not one — the main multi-field settings form
   (`update_settings`), a separate one-click "Ranking maintenance" button (`backfill_snapshots`), and a
   separate one-click "Email delivery" toggle (`toggle_smtp_live`). The design mock (built from a
   single hardcoded prototype) shows one page-wide sticky save/discard bar with a single dirty flag.
   **Decision:** the sticky dirty-bar governs only the main settings form (General / Hero EN+DA /
   Betting rules / Points / Bet size / Feature toggles collapsible sections); Ranking maintenance and
   Email delivery become their own collapsible **section cards** with their existing independent
   one-click actions, unchanged — they were never "dirty-trackable" fields and forcing them under one
   shared save bar would be a functional regression (an admin could believe an unrelated already-fired
   action was still pending). This is genuinely new client-side state (a `dirty` boolean +
   input/change listeners) even though the epic is framed as "no new features" — flagged explicitly
   rather than silently building it as if it were pure CSS.

6. **"No new data" means no new SQL — presentational aggregation of already-fetched data is in
   scope, and needed in a few places.** The design's KPI-card headers (Bets: 3 stat cards; Members,
   Trivia, Duels: 3 stat cards each) don't exist as computed values anywhere today — checked
   `public/includes/admin/bets.php`: it only groups `$bets` by race, no aggregate counts. These KPIs
   (e.g., total bets, total points awarded) can be computed with `array_sum`/`count` over the `$bets`
   array **already loaded into the page** — zero new queries. Per-screen audit required during Phase 3
   /4 (below) to confirm each KPI is derivable from data the page already fetches; anything that isn't
   gets flagged and deferred rather than silently added as a new query (see Deferred).

7. **Mobile "close dropdown on click" JS is a small addition, not a new pattern.** The existing
   hamburger-menu click-outside-to-close handler (`public/assets/js/app.js:243-248`) is the direct
   precedent — the `<details class="admin-dropdown">` needs one small listener closing the nearest
   `<details>` when a `.menu a` is clicked (the `<details>`/`<summary>` open/close mechanic itself
   needs zero JS, per the README).

8. **Rollout is incremental per phase, not big-bang across 17 screens.** Given decision 3's
   test-coupling finding, each phase below lands and is verified against the full relevant E2E spec
   set before the next phase starts, rather than reskinning all three areas in one changeset.

## Files

**New:**
- None — this epic extends existing files; no new pages, partials, or schema (per the README's
  explicit "no new data, features, or admin capabilities").

**Edited:**
- `public/includes/admin-area-nav.php` — add `renderAdminTabRow()` (decision 1); Level-1 call sites
  updated to the new markup shape.
- `public/admin.php`, `public/admin-challenges.php`, `public/admin-dashboards.php` — replace each
  page's hand-copied inline Level-2 `.admin-nav` block with a `renderAdminTabRow()` call.
- `public/assets/css/style.css` — replace `.admin-area-nav`/`.admin-area-tab` and
  `.admin-nav`/`.admin-nav-tab` rules with the unified `.admin-tabs`/`.admin-tab`/`.admin-dropdown`/
  `@container admin-vp` rules (decision 2); add `.stat-card`/`.stat-card-grid`/`.section-card`/
  `.admin-page-header` (decision 4), superseding `.gha-stat-card`/`.dash-status-card`/
  `.gha-detail-card`/`.gha-runs-card`; add the 3-line list-row primitive nested inside `.card-body`
  (decision 3).
- `public/assets/js/app.js` — dropdown close-on-click handler (decision 7); settings dirty-bar
  listener scoped to the main settings form only (decision 5); section-collapse toggle (reuse the
  existing `.collapsible-form` scan at `app.js:67` if class-compatible, else a small parallel
  `.admin-collapsible-section` toggle of the same shape).
- `public/includes/admin-dashboards/{oversigt,keys,paddockkb,challenges,actions}.php` — class renames
  only (`gha-stat-card` → `stat-card`, etc.); no logic changes.
- `public/includes/admin/{races,drivers,users,invites,bets,security,logs}.php` — apply
  `.admin-page-header` + nest the list-row primitive inside existing `.card`/`.card-body`; Bets gains
  a `.stat-card-grid` computed from already-loaded `$bets` (decision 6).
- `public/includes/admin/settings.php` — split into collapsible `.section-card`s (General, Hero
  EN/DA, Betting rules + Points + Bet size, Feature toggles); add sticky save/discard bar for the main
  form only; Ranking maintenance + Email delivery become their own collapsible section cards with
  their existing independent actions unchanged (decision 5).
- `public/includes/admin-challenges/{members,rumor,trivia,duels,suppressions}.php` — same primitive
  application; Members/Trivia/Duels gain `.stat-card-grid` headers per decision 6's audit.
- `tests/e2e/admin/15-dashboards-nav.spec.js`, `16-dashboards-oversigt.spec.js`,
  `14-actions-dashboard.spec.js` — update `.admin-area-tab`/`.admin-nav-tab` selectors to the new
  class names (or to the new `data-testid` hooks — preferred), in the same commit as the CSS/markup
  change (decision 3).
- `docs/patterns.md` — document `renderAdminTabRow()` and the layout-primitive CSS convention (page
  header / stat-card grid / section card / list row) so future admin screens follow it instead of
  reinventing.
- `docs/admin-dashboards.md` — update the class-name references that describe the current Dashboards
  markup so the doc doesn't go stale.

## Phased build order

**Phase 1 — Nav framework (foundation; chrome only, zero data/logic touched)**
- Add `.admin-tabs`/`.admin-tab`/`.admin-dropdown`/`@container` CSS, replacing the old nav rules.
- Add `renderAdminTabRow()`; wire both nav levels on all three top-level pages through it.
- Wire the dropdown close-on-click JS.
- Update the three nav-selector-dependent specs **in this same phase**, not after.
- Full admin E2E run before Phase 2 starts — this phase should produce zero behavioral change besides
  the nav's responsive breakpoint.

**Phase 2 — Layout primitives, Dashboards area**
- Rename `.gha-stat-card`/`.dash-status-card`/`.gha-detail-card`/`.gha-runs-card` → the unified
  `.stat-card`/`.section-card` family (incl. `.gha-stat-value` → `.stat-card-value`, `.dash-health-
  score`, `.gha-panel`, `.dash-run-row`, `.dash-cat-row`, `.dash-fresh-dot`) across all five
  Dashboards partials.
- Add `.admin-page-header` to each tab.
- Update `17-dashboards-nogler-rotation.spec.js`, `18-dashboards-paddockkb.spec.js`,
  `19-dashboards-challenges-usage.spec.js` selectors **in this same phase** — corrected decision 4
  found these are class-coupled, unlike this phase's first "pure rename, no test impact" framing.

**Phase 3 — Core area reskin (highest regression risk: real per-row POST forms + `.card`-based test
locators)**
- Per page — Races, Drivers, Users, Invites, Bets, Security, Logs: nest the list-row primitive inside
  the existing `.card`/`.card-body` (never remove it, per decision 3); add `.admin-page-header`;
  compute Bets' stat-card-grid from already-loaded data (decision 6).
- Settings: split into collapsible sections, add the scoped sticky save bar (decision 5).
- Re-run 10-, 11-, 12- (and any settings-touching) specs after **each page**, not at the end of the
  phase — any failure here is an unintended regression, since no data/query change is in scope.

**Phase 4 — Paddock Challenges area reskin**
- Members, Rumor or Not, Trivia, Duels, Suppressions: same primitive application; confirm
  Members/Trivia/Duels KPI cards are computed from already-fetched data before adding them (decision
  6's audit — defer any that aren't, see Deferred).
- Re-run `46-admin-challenges.spec.js`, `48-invite-guardrails.spec.js`.

**Phase 5 — Docs + full-suite pass**
- Update `docs/patterns.md`, `docs/admin-dashboards.md`.
- Full `npm run test:e2e:test` pass across all admin specs plus smoke.
- Manual visual pass at ~375px / 768px / 1024px+ **inside the real page layout** (the container query
  reacts to `.admin-shell`'s own width, which is narrower than the viewport once nested inside
  `.container`'s max-width — verify the 720px breakpoint actually lands where intended in situ, not
  just in the standalone prototype).

## Testing approach

Reviewed via `/test-strategy-manager`. The review corrected decision 4 above (the Dashboards class
rename is not test-free, as first assumed) and found two more things worth calling out before any
code is written.

**Scope reminder for this review:** there is no PHPUnit and no new business logic in this epic — the
project's tests are Playwright E2E (`tests/e2e/`) plus a handful of Node `--test`/CLI harnesses for
pure-function math (`tests/unit/*-harness.php`, run via Node). Everything below is scoped to that.

### Additional coupling found beyond decision 3/4's list

Re-checked across the *full* `tests/e2e/` tree, not just the admin subfolder (the first pass under-
scoped this to files already known to be admin-related):
- No other spec outside the ones already listed in decisions 3–4 locates by `.admin-*`, `.gha-*`, or
  `.dash-*` classes. `tests/e2e/challenges/48-invite-guardrails.spec.js` uses `.hf-auth-wrap
  .card-body` but that's the public invite-acceptance page, unrelated to the admin list-row rework.
- `tests/e2e/admin/12-email-delivery.spec.js` already uses `data-testid` exclusively — no class
  coupling — but it has a **new** exposure once Settings gets collapsible sections (see below).

### Two genuinely new behaviors need new test scenarios, not just regression-proofing

**Decision 5 — Settings dirty-bar (new client-side state):**
- No spec currently exercises the main settings form at all (only `12-email-delivery.spec.js` covers
  the separate email-toggle card) — this is net-new coverage, not a migration of existing tests.
- New scenarios needed: page loads with the bar in "All changes saved" state and no dirty flag;
  editing any field in the main form (text input, textarea, number input, checkbox) sets dirty=true
  and the bar switches to "You have unsaved changes"; submitting the form clears dirty and returns to
  the saved state; editing a field then reloading without saving does **not** persist the value
  (confirms the dirty-bar is purely visual/local state, not a draft-save mechanism — the mock doesn't
  specify a `beforeunload` guard and this plan doesn't add one, so that absence should be an explicit
  assertion, not an assumption).
- **Concrete regression this decision creates, not just risk:** if the collapsible sections default
  to closed, `12-email-delivery.spec.js`'s existing `page.click('[data-testid="email-delivery-
  toggle"]')` will fail outright once that button lives inside a collapsed section it never expands.
  Same exposure applies to any future test of the "Ranking maintenance" backfill button. **This must
  be resolved as an explicit implementation decision** (default-open for Email delivery and Ranking
  maintenance's sections, or the test gets a section-expand step added) **before Phase 3 merges**,
  and `12-email-delivery.spec.js` updated in the same commit that adds the collapsible wrapper around
  it — not left to be caught by a failing CI run.

**Decision 6 — presentational KPI aggregations (Bets/Members/Trivia/Duels stat-card headers):**
- Each new KPI number must be asserted against an independently-known value from seeded fixture data
  (e.g., Bets' total-bets stat card equals the count of rows the page's own table already renders for
  that fixture) — never just "a number is displayed." This mirrors the precedent epic's own condition
  that a displayed aggregate must agree with an existing count shown elsewhere on the same page, so
  the two can never silently drift apart.
- Not every candidate KPI card (Bets, Members, Trivia, and Duels each want 3) is guaranteed to be
  derivable from data the page already fetches — if Phase 3/4's audit (per decision 6) finds one that
  isn't, it ships without that card (per Deferred) rather than quietly adding a new query with no
  corresponding test.

### Container-query breakpoint verification

The repo already has a direct precedent for this: `page.setViewportSize({ width, height })` before
`page.goto()`/interaction, used in `13-scoring.spec.js` and `15-env-banner.spec.js`. Apply the same
technique here:
- Assert `.admin-tabs` visible / `.admin-dropdown` hidden at a desktop width (e.g. 1024px) and the
  reverse at a mobile width (e.g. 375px), for **both** nav levels independently (Level-1 area switcher
  and Level-2 tab row can each be at a different point in their own `.admin-shell` container, so don't
  assume testing one proves the other).
- Add one boundary-value pair straddling the 720px container-query threshold (e.g. 719px vs 721px) on
  at least one screen, since off-by-one errors in `@container` breakpoints are otherwise invisible
  until someone happens to resize to exactly the wrong width.
- **The shell-width-vs-viewport-width divergence the design doc warns about in the abstract doesn't
  concretely apply to this codebase**: these admin pages are single-column (no nested sidebar or
  narrower embedding context), so `.admin-shell`'s inline size tracks `page.setViewportSize`'s width
  directly (minus the constant `.container` padding). No exotic iframe/narrow-embedding test setup is
  needed — noted explicitly so this isn't mistaken for an untested gap later.

### Per-phase gate conditions

The plan's proposal (run the relevant specs after each page in Phase 3/4) is **adequate for Phases
3–4** because nesting the new primitives inside the untouched `.card`/`.card-body` wrapper (decision
3) genuinely limits each page's blast radius to itself. It is **not adequate for Phases 1–2**, where
the change is a shared-class rename with repo-wide blast radius by construction (every admin page
consumes the same nav/stat-card CSS):
- **Hard gate:** a full `npm run test:e2e:test` run (not just the directly related specs) must pass
  before Phase 1 or Phase 2 is considered done and the next phase begins. A narrow "just the nav
  specs passed" check would not catch a collateral break in, say, a spec that happens to also touch
  `.admin-nav` incidentally.
- Phases 3 and 4 keep the plan's original per-page spec-run cadence, with one full-suite run at the
  end of each phase as a secondary check (cheap, given the suite already has to run for Phase 5).
- No privileged/destructive write path is introduced anywhere in this epic (unlike the precedent
  epic's "Roter nu" secret-rotation feature) — so there is no equivalent hard-blocking safety gate
  needed on that axis. The hard gates here are purely about regression containment, not about a new
  action being unsafe to expose.

### Test list summary (files touched or added, by phase)

- **Phase 1:** update `15-dashboards-nav.spec.js`, `16-dashboards-oversigt.spec.js`,
  `14-actions-dashboard.spec.js` (nav selectors); full-suite gate before Phase 2.
- **Phase 2:** update `17-dashboards-nogler-rotation.spec.js`, `18-dashboards-paddockkb.spec.js`,
  `19-dashboards-challenges-usage.spec.js` (stat-value/panel/row selectors — see corrected decision
  4); full-suite gate before Phase 3.
- **Phase 3:** no locator changes expected in `10-content.spec.js`/`11-invites.spec.js`/
  `12-users.spec.js` (per decision 3); **new** settings-form test scenarios (decision 5, above);
  update `12-email-delivery.spec.js` for the collapsible-section default-state decision; Bets KPI
  assertion (decision 6).
- **Phase 4:** no locator changes expected in `46-admin-challenges.spec.js` (per decision 3, extended
  to Members); new KPI assertions for Members/Trivia/Duels stat-card headers where decision 6's audit
  confirms they're in scope.
- **Phase 5:** full `npm run test:e2e:test` pass; manual container-query visual pass per the plan's
  existing Phase 5 item.

## Phase 3 — corrected during implementation (2026-07-26)

Before writing code, three of this plan's own Phase 3 assumptions were re-checked directly against
`Admin Settings.dc.html` (not just the README's prose) and found not to hold — same pattern as Phase
2's own corrected decision 4 above. All three *shrink* Phase 3's scope rather than expand it:

- **The 3-line stacked list-row primitive is not applied to any Core page in the mock.** It's only
  actually built once (Dashboards → Keys & Rotation → Secrets rows), and the README frames it
  explicitly as *"the template for any future dense data row"*, not something already applied to
  Users/Invites/Bets/Races/Drivers — those stay single dense flex rows in the mock. Shipped: each Core
  row stays a single flex row (reskinned colors/spacing/icon-buttons only), not restructured into
  stacked lines.
- **No Core page gets a per-tab `.admin-page-header`.** The mock shows exactly one global header
  (`<h1><i class="fas fa-gear"></i> Administration</h1>`), which `public/admin.php:720` already
  renders. Phase 2 added `.admin-page-header` to Dashboards tabs specifically because those had no
  heading before — Core tabs already have the equivalent one level up. Not added.
- **No KPI `.stat-card-grid` on any Core page in the mock** (Bets included — decision 6's "per-screen
  audit" gate above resolves to "not derivable/not wanted", not "wait for a future phase"). Not built.

Also resolved: the Settings testing-risk flagged above (*"if sections default closed,
`12-email-delivery.spec.js` will fail"*) doesn't materialize — the mock has only 3 of 6 Settings groups
collapsible (General, Homepage hero, Betting rules, default **closed**); Features, Ranking maintenance,
and Email delivery render as static always-expanded section cards. `12-email-delivery.spec.js` needed
no changes.

One in-scope bugfix found while touching this file: `update_settings` was the only settings POST
handler that didn't `header("Location: ...")` redirect after its `UPDATE` — it fell through and
re-rendered using the `$settings` array fetched *before* the query ran, so the immediately-rendered
page after Save showed stale field values. Fixed by re-calling `$settings = getSettings();` right
after the `UPDATE` (`public/admin.php`) — required for the new sticky save bar to behave correctly,
not a pre-existing behavior worth leaving in place once this exact code was being touched anyway.

See `tests/e2e/admin/21-settings-form.spec.js` for the new dirty-bar/save/discard coverage (net-new,
per the Testing approach section above) and [[project_admin_area_redesign_phase1]] (Claude memory) for
the full shipped-file list.

## Phase 4 — corrected during implementation (2026-07-26)

Before writing code, re-verified this plan's Phase 4 assumptions against the actual mock and the
current `public/includes/admin-challenges/{members,rumors,trivia,duels,suppressions}.php` markup via
three parallel Explore passes — same pattern as Phase 2's decision 4 and Phase 3's own corrections. All
three corrections *shrink* scope, same direction as Phase 3:

- **No `.stat-card-grid` KPI headers on Members/Trivia/Duels**, despite decision 6 expecting them. The
  mock's five Paddock Challenges panels (`Admin Settings.dc.html`) have **zero** stat-card-grids — not
  because the numbers aren't derivable (several are: `count($allParticipants)`, `$triviaTotalCount`,
  `count($duelsOversight)`), but because the design never put KPI cards on this section at all. Not
  built.
- **No per-tab `.admin-page-header`.** `admin-challenges.php:613` already renders one shared `<h1>`
  above all 5 tabs, exactly the situation Phase 3 found for Core's `admin.php:720`. Not added.
- **No row restructuring.** `.card`/`.card-body` and `.hf-racefull` (the two existing row wrappers
  across these 5 files) stay exactly as-is per decision 3; the mock's one real sample row (Members → All
  participants) is a single flex row, not 3-line-stacked, confirming no restructure was called for.

**What shipped instead** — presentational class swaps only, zero markup restructuring, zero new
queries:

- `duels.php`'s duel-status pill: replaced the `$statusColors` PHP lookup (the only hardcoded hex across
  all 5 files — `'locked' => ['bg' => '#f59e0b', 'fg' => '#1a1a1a']`) with Phase 3's `.badge-neutral`/
  `.badge-warning`/`.badge-success`/`.badge-accent` (open/locked/settled/void respectively) — verified
  exact token equivalence against `style.css` first (`.badge-accent` uses `--f1-red`, matching the old
  `void` color exactly; `.badge-danger` would NOT have matched, it's a different red, `--status-danger`
  `#ef4444`). Not test-coupled — no spec locates this pill by class/color.
- Delete/veto/bulk-delete buttons across `members.php`, `rumors.php`, `trivia.php`, `duels.php`: swapped
  inline `style="background:var(--f1-red);color:#fff;border:none;"` for `.btn-danger`, the exact class
  Phase 3 already standardized on for Core's delete buttons. Kept every button's existing icon/text
  markup and the `.btn-delete` class itself unchanged (it drives the shared confirm-modal JS in
  `app.js` and is directly targeted by `46-admin-challenges.spec.js` and `44-duels.spec.js`).
- `rumors.php`/`trivia.php` Edit buttons (already icon-only) gained `.admin-icon-btn` for the uniform
  34×34 sizing — zero markup change beyond the class.
- **Explicit exception, not an oversight:** `members.php`'s bistable guest `toggle_guest_competition`
  button (line 68) was left on its inline style. It doesn't map onto a single `.badge-*`/`.btn-danger`
  class (color depends on `in_competition` state), and it's the one button in all 5 files directly
  asserted on literal inline-style content (`46-admin-challenges.spec.js:72-78`, regex-matching
  `/f1-red/` and `/bg-secondary/`) — not worth the test churn for a cosmetic-only change with no
  established convention to move to.
- **Real inconsistency found and fixed, not just color polish:** `admin-challenges.php` had no
  `.admin-tab-content` wrapper around its tab include, unlike Core (`admin.php:753`) — so its `.card`
  rows (Members queue/guest rows, Duels queue/oversight rows, Suppressions rows) still got the site-wide
  `.card:hover` red-tinted lift/glow that Phase 3's CSS rule (`style.css:604-608`) explicitly kills for
  admin data rows, per the README's "admin data rows stay flat" rule. Fixed by adding the same wrapper
  div Core uses — Challenges rows are now flat too, matching Core and the README.
- **Test hardening, not required but recommended and shipped:** added `data-testid="promotion-request-
  row"` / `data-testid="converted-guest-row"` to `members.php`'s two card sections, additive alongside
  the existing `.card.mb-1` classes those rows are still located by. These were the only row family
  across all 5 files with zero `data-testid` — located purely by class + text filtering
  (`46-admin-challenges.spec.js`'s `cardFor()` helper, ~14 call sites) — the single highest-risk
  regression surface the locator audit found.

**Verification:** `46-admin-challenges.spec.js` and `44-duels.spec.js` pass; full `npm run deploy:test`
and `npm run test:e2e:test` run as the phase-end gate.

## Deferred

- Any KPI card whose number isn't already computable from data a page already fetches (decision 6) —
  resolved per-screen during Phase 3/4's audit; if one requires a genuinely new query, it ships without
  that card rather than silently expanding scope.
- Visual polish beyond what's specified (page-load animation, hover-lift on data rows) — the README
  explicitly excludes these ("no hover-lift on cards... admin data rows stay flat").
- A true cross-host Test↔Prod nav/primitive parity check — out of scope; this epic's CSS/markup
  changes deploy per-environment the same way every other admin change already does.
