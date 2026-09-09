# Implementation Plan — Profile & Dashboards UI Cleanup (F1Betting)

> **Provenance note (2026-09-09).** This document was originally written under the folder name
> "Refactor UI for admin and profile area" — which is the name of a **Robinsonklubben** epic
> (`robinsonklubben.dk/epics/Refactor UI for admin and profile area/`, which has its own design
> handoff). It was produced against the F1Betting codebase by mistake, in a session where both repos
> shared one multi-root VS Code workspace. Its technical content was independently verified against
> F1Betting and holds up, so it has been kept and renamed as a standalone F1Betting backlog item.
> **It is not the Robinsonklubben epic and has no connection to it.** Nothing here has been
> scheduled, started, or committed.

Produced via `/web-architecture-review`, adapted to this project's actual conventions (procedural
PHP, no framework, no MVC/repository layers). There is no design handoff for this work (unlike
`epics/Admin area redesign/plan.md`'s `Admin Settings.dc.html`) — scope was derived directly from
auditing the current codebase against the conventions the prior two redesign epics already
established: `epics/Admin area redesign/plan.md` (nav framework + layout primitives, all 5 phases
shipped to TEST 2026-07-26 / LIVE 2026-07-27, see `docs/patterns.md` → "Admin Layout Primitives")
and `epics/Archive/redesign-profile-stats/` (the points-hero + status-chips stats block on
`profile.php`, shipped v2.2.0).

This epic has two independent scopes under one title, not one reskin:

1. **Admin residual cleanup** — finish migrations the prior admin epic's own phases didn't reach.
2. **Profile-area systemization** — `public/profile.php` and `public/challenges-profile.php` have
   never been through either prior epic and carry real duplication and inline-style debt.

No new data, features, or admin/profile capabilities anywhere in this epic — presentational and
structural cleanup only, same framing the prior admin epic used.

## Architecture decisions

1. **Sequence admin cleanup before profile work, not in parallel.** The admin scope is small,
   low-risk, and directly finishes work the prior epic already validated the pattern for (badge
   classes, `.card`/`.section-card` conventions already exist — this is applying them to 3 call
   sites the prior epic's phases didn't reach). The profile scope is larger, first-time work with
   more surface area (65 inline `style="..."` attributes in `profile.php`, 19 in
   `challenges-profile.php` — counted directly, `grep -c 'style="'`). Landing the small, cheap phase
   first re-validates the badge-class convention still holds before spending effort on the bigger,
   riskier profile phases.

2. **Admin: the `.badge-*` migration (Phase 3/4 of the prior epic) never reached Dashboards, and
   one call site there is a worse pattern than what Phase 4 already fixed.** Checked directly:
   `.badge-accent/-success/-danger/-warning/-neutral` were introduced in Phase 3 (Core) and extended
   to Paddock Challenges in Phase 4 (`duels.php`'s `$statusColors` → `.badge-*`, per that phase's own
   writeup), but Dashboards was never revisited. Three `label-badge` call sites still emit inline
   `style="background:<dynamic>;color:#fff"`:
   - `public/includes/admin-dashboards/keys.php:147,183` — `$meta['color']` already resolves to
     `var(--status-success-light)` / `var(--status-warning-light)` / `var(--status-danger-light)` /
     `var(--text-muted)` (token names, not hex) for its `ok/warn/bad/unknown` and `ok/due/over/unknown`
     states.
   - `public/includes/admin-dashboards/challenges.php:121-131` — `chCadenceBadgeMeta()` is a step
     *worse*: it hardcodes raw hex (`#ef4444`, `#10b981`, `#f59e0b`, `#8b8b96`) instead of reusing the
     same CSS custom properties `keys.php` already references — exactly the pattern Phase 4 fixed for
     `duels.php`'s old `$statusColors` lookup, just never applied here.
   **Verified token equivalence before assuming a free swap** (same discipline Phase 4 used for
   `.badge-accent` vs `.badge-danger`): `--status-success-light`/`-warning-light`/`-danger-light` are
   pure aliases of `--status-success`/`-warning`/`-danger` (`style.css:1982-1984`, `var(X-light) = var(X)`
   exactly) — so `keys.php`'s `ok`/`warn`/`bad`/`due`/`over` states map cleanly to
   `.badge-success`/`.badge-warning`/`.badge-danger`. Its `unknown` state (`background:var(--text-muted)`)
   does **not** cleanly map to `.badge-neutral` (`background:var(--bg-secondary)`) — different token,
   different visual weight — needs a side-by-side visual check during implementation before picking
   `.badge-neutral` vs. leaving `unknown` on a scoped inline color. `chCadenceBadgeMeta()`'s hex values
   line up 1:1 with the same three tokens (`#ef4444`=danger, `#10b981`=success, `#f59e0b`=warning), so
   it gets rewritten to return a badge-class key instead of a color string — not just template-swapped,
   since its return shape (`'color' => ...`) is consumed elsewhere in the same function and needs to
   change to `'class' => 'badge-...'`.
   No spec locates by `.label-badge` (`grep -rl "label-badge" tests/e2e/` → zero matches) — free rename
   as far as tests are concerned, but see decision 2's `unknown`-state caveat above before treating it
   as free visually.

3. **`duels.php` has a second inline-styled pill Phase 4's own writeup didn't cover.** Phase 4 fixed
   the duel-status `$statusColors` lookup (open/locked/settled/void). `duels.php:21` has a separate,
   static pill — `<span class="btn btn-sm" style="background:var(--f1-red);color:#fff;...">` — shown
   when a quick-match's race has already started (`t('admin_ch_qm_expired')` → "Løb startet"/"Race
   started"). Not data-driven (single fixed state, not a `$meta`-style lookup), so it's a one-line
   class swap once the right semantic badge is picked — likely `.badge-neutral` (it signals
   unavailability, not a warning/danger condition) but confirm against the rendered page before
   committing, same "trust but verify" pattern Phase 3/4 used throughout the prior epic rather than
   guessing from the class name alone.

4. **Keys & Rotation mobile fix — CORRECTED 2026-09-09: the CSS work is already done; only the
   regression test is missing.** This decision originally proposed picking up two Deferred items from
   `epics/Admin area redesign/plan.md` (a hardcoded `130px 1fr` wrapper squeezing `.stat-card-grid`
   beside the health ring at 375px, and long secret-name labels like `INTEGRATION_SEED_TOKEN`
   truncating against their "OK" badge). **Both were already fixed** by commit `7058fee`
   ("Nøgler & Rotation: mobile-responsive token/secret rows + KPI grid", 2026-07-27) — one day after
   the prior epic's Phase 5 closed, in an out-of-epic commit. `style.css:4184-4256` now carries named
   grid-areas (`.dash-token-row`, `.dash-secret-row`, `.dash-kpi-row`) plus a `@media (max-width:768px)`
   block, citing "epics/Admin area redesign/plan.md decision 4" in a comment at `style.css:4160`. The
   prior epic's Deferred section is stale documentation and still describes the bug as open.
   **What remains:** `tests/e2e/admin/17-dashboards-nogler-rotation.spec.js` has zero
   `setViewportSize` calls, so the shipped fix has no automated regression coverage — add that test
   (see Testing approach below). No CSS work.

5. **Profile gets its own layout primitives, not a forced reuse of admin's.** `profile.php` and
   `challenges-profile.php` sit inside the shared `.hf-container` like every other public page
   (`index.php`, `race.php`), not `.admin-shell` — importing `.admin-tabs`/`.admin-dropdown` (built for
   a container-query collapse across up to 8 tabs) would be solving a problem profile doesn't have
   (it has 3–4 fixed tabs, never enough to need a mobile dropdown collapse) at the cost of dragging in
   admin-specific chrome. Profile already has its own working, shipped conventions: `.hf-tabs`/
   `.hf-tab-nav`/`.hf-tab-btn`/`.hf-tab-panel` (fixed pill row, toggled by `app.js:351-352`'s plain
   click handler, no `<details>`/container-query involved) and the points-hero + status-chips pattern
   from the redesign-profile-stats epic. Decision: name and formalize what's *already* the de facto
   profile convention in `docs/patterns.md` (mirroring how the admin epic formalized what it built),
   rather than replacing it with admin's system.

6. **The real problem is duplication between the two profile pages, not inconsistent visuals between
   them.** Read both files in full. They hand-roll near-identical blocks independently:
   - **Profile head** — avatar circle (first-letter initial) + name + subtitle line. Byte-for-byte
     structurally identical `.hf-profile-head`/`.hf-profile-avatar`/`.hf-profile-id` markup in both
     files, only the data source differs (`$currentUser` vs `$participant`).
   - **Preferences tab** — theme/font/language segmented-toggle block. Same 3 `form-group`s, same
     `.hf-pref-toggle`/`.hf-pref-btn` markup, same hidden-input JS-sync pattern, only the POST target
     and `$participant['language']` vs. `$currentUser['language']` source differ.
   - **Password-change form** — same 3 fields (current/new/confirm), same `.hf-pw-match` JS hook, same
     `minlength="10"` — `profile.php`'s `change_password` action and `challenges-profile.php`'s
     `change_password`/`set_password` actions differ only in which fields are required and which table
     gets the `UPDATE`.
   This is decision 1's shape from the prior epic ("one shared rendering path, not a second parallel
   system") applied to profile. **Decision:** extract two small shared partials —
   `public/partials/profile_head.php` (parameterized by `$avatarInitial`/`$displayName`/`$subtitle`)
   and `public/partials/profile_preferences_form.php` (parameterized by current theme/font/language
   values + the POST `action` value) — included from both pages. **Do not** build a PHP-side rendering
   helper analogous to `renderAdminTabRow()`: that helper exists because it emits two structurally
   different outputs (flat row vs. `<details>` dropdown) from one data array — genuine branching
   complexity worth hiding behind a function. Profile's tab markup has no such branching (always one
   fixed pill row, no responsive alternate shape), so a helper would just wrap a static template with
   no complexity to justify it — a plain `include` with 2–3 `$`-variables is the right-sized fix here,
   not a new abstraction. Password-change forms are **not** extracted — `challenges-profile.php`'s
   two-mode set/change split (`$isPermanent` branch) has no equivalent in `profile.php`, so unifying
   them would mean threading a mode flag through a shared partial for a 3-field form; not worth it.

7. **Test coupling — checked directly against the real spec files**, same audit precedent's decision 3
   ran:
   - `tests/e2e/challenges/47-participant-profile.spec.js:129` — `page.locator('.hf-tab-nav
     .hf-tab-btn')` — a direct class locator, not `data-testid`. The profile-head/preferences-form
     partial extraction (decision 6) doesn't touch tab-nav markup, so this shouldn't be affected, but
     must be re-run and confirmed, not assumed.
   - `tests/e2e/09-profile-preferences.spec.js:156` — `page.locator('.hf-profile-name').textContent()`
     — a direct class locator. The `profile_head.php` extraction (decision 6) must preserve this exact
     class name on the exact element, or this line needs updating in the same commit.
   - `tests/e2e/05-profile.spec.js` and the rest of `09-profile-preferences.spec.js` already assert via
     `data-testid` exclusively for every tab/stat interaction checked (`tab-*-btn`, `tab-*-panel`,
     `stats-hero`, `stats-chip-role`, `stats-chip-competing`, `stats-stars`, `display-name-input`,
     etc.) — no coupling risk from decisions 6/8/9 for these.
   - No spec locates by `.label-badge` (decision 2) or by `duels.php`'s expired-pill inline style
     content (decision 3) — confirmed via direct grep, zero matches in `tests/e2e/`.
   - `tests/e2e/challenges/46-admin-challenges.spec.js` regex-matches `/f1-red/`/`/bg-secondary/`
     against `members.php`'s bistable toggle button — untouched by this epic (see Deferred), reconfirm
     it's still out of scope since nothing here touches `members.php`.

8. **Security-tab inline-style reduction is presentational-only but genuinely large — extract a
   class family, don't restructure markup.** `profile.php`'s Security tab repeats the same
   "icon+title+status row, right-aligned conditional action form" shape four times (Passkeys, TOTP,
   Email OTP, Recovery codes), each hand-styled with the same handful of inline
   `display:flex;align-items:center;justify-content:space-between;gap:12px` rules plus per-row
   `border-bottom` and spacing tweaks. **Decision:** introduce a small shared class family
   (`.hf-mfa-row`, `.hf-mfa-row-head`, `.hf-mfa-row-title`, `.hf-mfa-row-status`) replacing the
   repeated inline styles across all four blocks — pure CSS-class extraction, zero markup
   restructuring, zero new state — same "layout primitive, not new feature" framing the admin epic
   used throughout. Must be checked against any MFA-specific e2e specs (`data-testid` hooks already
   exist on every actionable element per decision 7's audit — `passkey-section`, `totp-status`,
   `emailotp-status`, etc. — so this should be a safe rename, but confirm no spec asserts inline
   `style` attribute content directly, the way `46-admin-challenges.spec.js` does for `members.php`'s
   exception case).

9. **Two stale CSS blocks found while reading the file for decision 8 — delete/rename, don't leave
   them as silent drift.** Not required by anything else in this epic, but cheap to fix while already
   touching neighboring rules in the same stylesheet:
   - `style.css:2846-2867` — a `.hf-tabs` block commented `/* Tabs (used on profile) */` whose selector
     (`.hf-tabs button:not(.btn)`) targets a `<button>` as a **direct child** of `.hf-tabs`. Current
     profile markup never does this — every tab button is nested inside `.hf-tab-nav` inside `.hf-tabs`
     — so this block is unreachable, superseded by the real, currently-applied block at
     `style.css:3582` ("Profile tabs"). Confirmed dead, not just suspected: no markup anywhere in
     `profile.php`/`challenges-profile.php` matches its selector shape.
   - `style.css:3305` — `.hf-profile-grid { display: block; padding-bottom: 48px; }` — the class name
     promises a 2-column grid that isn't there; it's single-column now. **Decision:** rename to
     `.hf-profile-body` (honest name for what it does) rather than resurrecting an actual grid layout
     nobody asked for — this is a naming/dead-code fix, not a visual change, so the one call site in
     `profile.php` gets updated in the same commit.

## Files

**New:**
- `public/partials/profile_head.php` — avatar + name + subtitle, shared by both profile pages
  (decision 6).
- `public/partials/profile_preferences_form.php` — theme/font/language segmented-toggle form, shared
  by both profile pages (decision 6).

**Edited:**
- `public/includes/admin-dashboards/keys.php` — `label-badge` inline styles → `.badge-*` classes
  (decision 2). No mobile/truncation CSS work — already shipped in `7058fee` (decision 4).
- `public/includes/admin-dashboards/challenges.php` — `chCadenceBadgeMeta()` rewritten to return a
  badge-class key instead of a hex color string; call site updated to emit `.badge-*` (decision 2).
- `public/includes/admin-challenges/duels.php` — expired-pill inline style → badge class (decision 3).
- `public/profile.php` — use the two new partials; Security-tab MFA rows get the new
  `.hf-mfa-row` class family (decision 8); `.hf-profile-grid` reference → `.hf-profile-body`
  (decision 9).
- `public/challenges-profile.php` — use the two new partials.
- `public/assets/css/style.css` — delete the dead `.hf-tabs` block at `2846-2867`; rename
  `.hf-profile-grid` → `.hf-profile-body` (decision 9); add `.hf-mfa-row`/`.hf-mfa-row-head`/
  `.hf-mfa-row-title`/`.hf-mfa-row-status` (decision 8). No Keys & Rotation grid/truncation work —
  already shipped in `7058fee` (decision 4). No new `.badge-*` variants needed — all five already
  exist (`style.css:794-798`).
- `docs/patterns.md` — new "Profile Layout Primitives" section (mirrors "Admin Layout Primitives")
  documenting `.hf-tabs`/`.hf-tab-nav`/`.hf-tab-btn`/`.hf-tab-panel`, `.hf-profile-head`, the
  points-hero + status-chips stats pattern, the two new shared partials, and `.hf-mfa-row`.
- `docs/admin-dashboards.md` — note the badge-class convention now spans all three admin areas
  (previously only documented for Core/Challenges).
- `tests/e2e/challenges/47-participant-profile.spec.js` — update the `.hf-tab-nav .hf-tab-btn` locator
  only if Phase 2 changes that markup shape (decision 7 — verify first, don't assume).
- `tests/e2e/09-profile-preferences.spec.js` — update the `.hf-profile-name` locator only if the
  `profile_head.php` extraction doesn't preserve that exact class (decision 7 — verify first).

## Phased build order

**Phase 1 — Admin residual cleanup (low risk, finishes the prior epic's own migration)**
- `keys.php` + `challenges.php`: badge-class migration per decision 2, including the visual check on
  the `unknown` state before picking `.badge-neutral` vs. a scoped inline color.
- `duels.php`: expired-pill class swap per decision 3.
- Add the missing 375px viewport regression test for the already-shipped Keys & Rotation mobile fix
  per decision 4 (test only — no CSS change).
- Run `17-dashboards-nogler-rotation.spec.js`, `18-dashboards-paddockkb.spec.js`,
  `19-dashboards-challenges-usage.spec.js`, `44-duels.spec.js`, `46-admin-challenges.spec.js` — none
  are expected to need locator changes (decision 7), but this is the gate that proves it, not an
  assumption to skip.
- Full `npm run test:e2e:test` gate before Phase 2 — shared-stylesheet blast radius, same reasoning
  the prior epic's Phase 1/2 hard-gate used.

**Phase 2 — Profile shared partials (structural, should be zero visual change)**
- Extract `profile_head.php`; wire into both pages.
- Extract `profile_preferences_form.php`; wire into both pages.
- Run `05-profile.spec.js`, `09-profile-preferences.spec.js`,
  `tests/e2e/challenges/47-participant-profile.spec.js` — update the two class-coupled locators
  (decision 7) only if the extraction actually changed that markup; otherwise confirm they pass
  unmodified.

**Phase 3 — Profile inline-style reduction (presentational only)**
- Introduce `.hf-mfa-row` family; apply across all four Security-tab MFA method blocks
  (Passkeys/TOTP/Email OTP/Recovery codes) per decision 8.
- Run `05-profile.spec.js`'s Security-tab scenarios plus any dedicated MFA e2e specs — confirm no
  test asserts inline `style` content directly before treating this as a free rename.

**Phase 4 — CSS dead-code cleanup**
- Delete the orphaned `.hf-tabs` block (`style.css:2846-2867`) per decision 9, after confirming (again,
  not just once) that no current markup anywhere in the repo matches its selector shape.
- Rename `.hf-profile-grid` → `.hf-profile-body`; update the one call site in `profile.php`.
- Full `npm run test:e2e:test` gate — a shared-stylesheet deletion has repo-wide blast radius by
  construction, same as Phase 1's gate.

**Phase 5 — Docs + full-suite pass**
- `docs/patterns.md`: new "Profile Layout Primitives" section.
- `docs/admin-dashboards.md`: note the badge-class convention now spans all three admin areas.
- Full `npm run test:e2e:test` pass across the whole suite.
- Manual visual pass on `profile.php` and `challenges-profile.php` at 320/768/1280px, dark + light
  theme, DA + EN — mirrors the manual QA checklist `epics/Archive/redesign-profile-stats/
  implementation-redesign-profile-stats.md` already used for this same page.

## Testing approach

Reviewed via `/test-strategy-manager`. The review re-ran decision 7's grep audit across the *full*
`tests/e2e/` tree rather than trusting the first draft's file list, and found one real coverage gap
plus a set of specs the first draft's decision 8 didn't account for at all — same pattern as the
precedent epic's own test-strategy-manager pass (`epics/Admin area redesign/plan.md`'s Testing
approach section), which corrected an under-scoped first draft rather than rubber-stamping it.

**Scope reminder:** no PHPUnit, no new business logic anywhere in this epic — tests are Playwright
E2E (`tests/e2e/`) only, per project convention.

### Decision 7's audit re-verified, and widened

Re-ran the class-locator greps across the entire `tests/e2e/` tree (not just the files the first
draft assumed were relevant) for every class this epic touches or removes: `.hf-tab-nav`/
`.hf-tab-btn`/`.hf-tab-panel`/`.hf-tabs`, `.hf-profile-*`, `.label-badge`, `.badge-*`.

- **Confirmed complete, not just plausible:** decision 7's two class-coupled locators —
  `tests/e2e/challenges/47-participant-profile.spec.js:129` (`.hf-tab-nav .hf-tab-btn`) and
  `tests/e2e/09-profile-preferences.spec.js:156` (`.hf-profile-name`) — are the *only* two matches
  anywhere in the repo's E2E suite for any of those selectors. Nothing else needs auditing for
  decision 6's partial extraction.
- **Confirmed, not assumed:** zero specs anywhere locate by `.label-badge` or any `.badge-*` class —
  decision 2's Dashboards badge migration and decision 3's `duels.php` pill fix are both fully free of
  locator risk.

### Gap the first draft missed: decision 8's blast radius is bigger than the file it named

The first draft listed only `05-profile.spec.js` as coverage for the Security tab's MFA rows (decision
8's `.hf-mfa-row` extraction). Grepping for every spec that actually exercises `profile.php`'s Security
tab turns up five more, all under `tests/e2e/auth/`, none previously listed: `30-totp-mfa.spec.js`,
`31-email-otp.spec.js`, `32-mfa-default-method.spec.js`, `35-passkey.spec.js`,
`36-passkey-negative.spec.js`. Checked each for class-based (non-`data-testid`) locators inside the
region decision 8 restyles: none exist — `30-`/`31-` assert `.alert-error` (a shared site-wide alert
component, untouched by this epic), `32-` asserts `.hf-login-card` (the *login* page, a different file
entirely), `35-` asserts `.card`/`.alert-success` on the *admin* Users page (a different file). So
decision 8's inline-style-to-class swap is not expected to break any of them — but all five must be
added to the Phase 3 regression gate; the first draft's single-file list would have missed a real
regression if the extraction had gone wrong in a way that changed element structure rather than just
attributes (e.g. an accidentally-collapsed `data-testid` during the markup pass).

### Near-miss investigated and cleared: decision 2's Dashboards Challenges edit sits next to a real locator

`tests/e2e/admin/19-dashboards-challenges-usage.spec.js:32` locates
`div[style*="grid-template-columns:200px"]` — an inline-style-content locator on the exact same page
(`admin-dashboards.php?tab=challenges`) decision 2's `chCadenceBadgeMeta()` rewrite touches. Read both
the spec and `public/includes/admin-dashboards/challenges.php` directly to confirm these don't
collide: the `200px` grid belongs to the visitor→member **funnel panel** rows (`challenges.php:187`),
a structurally separate section from the **cadence badge** row decision 2 changes
(`challenges.php:~228-230`, a `.label-badge` span with its own unrelated inline `background:` style).
Decision 2's planned edit touches only the badge span's `style` attribute and the PHP function's return
shape — it does not touch the funnel section's markup at all. Confirmed safe, not assumed safe — this
was the one place in the whole epic where two different decisions' edits land within a few lines of
each other in the same file.

### Genuinely new risk found: challenges-profile.php's Preferences tab has zero E2E coverage today

`tests/e2e/09-profile-preferences.spec.js` covers `profile.php`'s preferences flow thoroughly
(DB-level assertions that `theme`/`font`/`language` persist to `users`, survive logout/re-login, reject
tampered values, and update `html[lang]`). `tests/e2e/challenges/47-participant-profile.spec.js` has
**no test at all** for `challenges-profile.php`'s Preferences tab (its 12 tests cover display name,
password, account/session actions, and promotion — confirmed by listing every `test(...)` in the file).
This is a **pre-existing gap**, not something this epic creates — but decision 6 turns it from a
latent gap into a live regression risk: extracting `profile_preferences_form.php` means one shared
template now drives *two* different POST targets and *two* different DB tables (`users.language` vs.
`challenge_participants.language`), and only one side has a test that would catch a parameterization
mistake (e.g. a copy-paste error wiring both forms to `users`). **New scenario needed, not deferred**:
add a `challenges-profile.php` Preferences-tab test to `47-participant-profile.spec.js` — same shape as
`09-profile-preferences.spec.js`'s language-persistence case (`PP-NEW-5`), asserting the language value
lands in `challenge_participants`, not `users`. This should land in **Phase 2**, alongside the
extraction itself, not as follow-up — it's the one direct test for decision 6's core risk (the shared
partial silently writing to the wrong place).

### Gap found: decision 4's Keys & Rotation mobile fix has no automated viewport coverage at all

`tests/e2e/admin/17-dashboards-nogler-rotation.spec.js` has zero `setViewportSize` calls — the entire
spec runs at the default (desktop) viewport. This matches the prior epic's own account of how this
issue was originally found: only a manual screenshot pass at 375/719/721/768/1024/1280px caught it
(`epics/Admin area redesign/plan.md`, Phase 5), not automation — the precedent epic itself never added
a regression test for it, per its own Deferred section. The CSS fix itself shipped in `7058fee`
(decision 4) but still carries no automated coverage, so the regression it guards against could
silently return. **New scenario needed**: one `page.setViewportSize({width:375,
height:...})` assertion on `admin-dashboards.php?tab=keys` confirming the stat-card-grid/health-ring
area doesn't overflow and a long secret name (`INTEGRATION_SEED_TOKEN`) doesn't collide with its status
badge — mirroring the existing precedent for this exact technique in `13-scoring.spec.js`'s "podium is
visible on mobile viewport (375px)" test and `15-dashboards-nav.spec.js`'s container-query breakpoint
tests. Add in **Phase 1**. It is a pure test addition — the code it covers is already live.

### Test list summary (files touched or added, by phase)

- **Phase 1:** run `17-dashboards-nogler-rotation.spec.js`, `18-dashboards-paddockkb.spec.js`,
  `19-dashboards-challenges-usage.spec.js`, `44-duels.spec.js`, `46-admin-challenges.spec.js` — no
  locator changes expected in any of them (confirmed above); **add** one new 375px viewport assertion
  to `17-dashboards-nogler-rotation.spec.js` for decision 4's fix. Full-suite gate before Phase 2.
- **Phase 2:** run `05-profile.spec.js`, `09-profile-preferences.spec.js`,
  `tests/e2e/challenges/47-participant-profile.spec.js` — update `.hf-tab-nav .hf-tab-btn` and
  `.hf-profile-name` only if the partial extraction actually changed that markup (verify, don't
  assume); **add** a new Preferences-tab persistence test to `47-participant-profile.spec.js`
  (decision 6's core risk, above) in the same commit as the extraction.
- **Phase 3:** run `05-profile.spec.js` (Security-tab scenarios) plus all five newly-identified
  `tests/e2e/auth/{30-totp-mfa,31-email-otp,32-mfa-default-method,35-passkey,36-passkey-negative}
  .spec.js` — none expected to need locator changes (confirmed above), but this phase is the gate that
  proves it for a set of specs the first draft didn't list at all.
- **Phase 4:** full `npm run test:e2e:test` gate (shared-stylesheet deletion, repo-wide blast radius by
  construction, same reasoning as Phase 1's gate).
- **Phase 5:** full `npm run test:e2e:test` pass; manual visual check per the phased build order above.

### Per-phase gate conditions

Phases 2 and 3 keep the plan's per-page/per-spec cadence adequate on their own — decision 6's partial
extraction and decision 8's class extraction are each confined to files this epic already lists, with
no shared-stylesheet-wide blast radius. Phases 1 and 4 are **not** adequate with a narrow "just the
directly-related specs passed" check — both involve edits to `public/assets/css/style.css`, a
repo-wide shared file, so a full `npm run test:e2e:test` run is the hard gate before/after those
phases, matching the precedent epic's own reasoning for its Phase 1/2 gates.

## Deferred

- Visually converging `profile.php`'s points-hero + status-chips stats block with
  `challenges-profile.php`'s 3-cell `.hf-stats-metrics` grid — decision 5 keeps them as two distinct,
  intentional shapes (different underlying metrics: season points/role/competing vs. CP total/rank/
  streak), not a forced shared template. Revisit only if a future epic decides these should look
  identical.
- Any deeper visual/typographic redesign of profile — this epic is systemization and dead-code
  cleanup (shared partials, badge convention, inline-style extraction), not a new visual design. Unlike
  the admin epic, there is no design handoff for profile (no `.dc.html` mock) — an actual reskin would
  need one first.
- `members.php`'s bistable inline-styled `toggle_guest_competition` button (documented exception in
  the prior epic's Phase 4, directly asserted on by `46-admin-challenges.spec.js`) — still out of
  scope; nothing in this epic touches `members.php`.
- Password-change form unification between `profile.php` and `challenges-profile.php` — decision 6
  explicitly excludes this; `challenges-profile.php`'s set/change two-mode split has no equivalent in
  `profile.php`.
