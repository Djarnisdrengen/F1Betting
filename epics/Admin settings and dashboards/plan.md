# Implementation Plan — Admin Settings & Operations Dashboards

Refined from `admin-settings-dashboards-epic.md` + the five `feature-*.md` docs via `/web-architecture-review`,
adapted to this project's actual conventions (procedural PHP, no framework, no MVC/repository layers). Follows
the same shape as the precedent plan for the already-shipped GitHub Actions sub-feature
(`epics/github_actions_dashboard/plan.md`). Deviations from the feature docs are called out explicitly with
the reason, same convention as that precedent.

## Architecture decisions (deviations from the feature docs / open questions resolved)

1. **Dashboards routing mirrors `admin-challenges.php`'s own pattern.** New `public/admin-dashboards.php` with
   `?tab=oversigt|keys|paddockkb|challenges|actions` (default `oversigt`), each tab's markup in
   `public/includes/admin-dashboards/{tab}.php` — same shape as `public/includes/admin-challenges/{tab}.php`.
   This resolves epic decision D4 in favor of one-file-per-area consistency across all three areas, rather
   than inventing a different shape for the newest one.

2. **`admin-actions.php` becomes a thin compatibility redirect**, not a deleted file. Its current rendering
   logic (GET page render + the `?ajax=run_jobs&run_id=` branch) moves verbatim into
   `public/includes/admin-dashboards/actions.php`, included by the new router. `admin-actions.php` itself
   is gutted to `header('Location: admin-dashboards.php?tab=actions' . ($qs ? "&$qs" : ''), true, 302); exit;`
   forwarding its query string — satisfies feature-1's REQ-108 (old bookmarks keep working) without
   maintaining two copies of the same page. `tests/e2e/admin/14-actions-dashboard*.spec.js` get updated to
   assert against the new canonical URL, **plus one new assertion that the old URL 302-redirects there** —
   the redirect itself becomes a tested contract, not an unverified side note.

3. **The triplicated `<nav class="admin-area-nav">` block is extracted** into
   `public/includes/admin-area-nav.php`, a single `renderAdminAreaNav(string $activeArea, int
   $challengesPromoCount)` helper. `admin.php`, `admin-challenges.php`, and the new `admin-dashboards.php` all
   call it. Resolves D4's other half: a 4th/5th copy-paste was never seriously on the table once a second new
   file needed the same block.

4. **Nøgler & Rotation ships with NO live environment toggle in v1 — this revises feature-3's REQ-301.**
   Verified against `config.example.php` (separate `config.test.php`/`config.live.php`, plain `define()`
   constants, no shared config) and `build-deploy/sync.js`/`deploy.js` (FTP from the developer's own machine,
   one host at a time, no server-to-server channel of any kind between hpovlsen.dk and formula-1.dk). There is
   no existing mechanism by which a PHP process running on one host could read, let alone safely write, the
   other host's config file — building one (e.g. embedding cross-host FTP/SSH credentials inside the live app
   itself) would be a disproportionate new attack surface for a hobby-scale internal tool, and exactly the
   kind of shortcut this review process exists to catch before it's built.
   **Decision:** each deployed instance of `admin-dashboards.php` manages only the environment matching the
   host it's running on — implicit, no toggle, no fake cross-host mirage. Page copy states plainly which
   environment this instance is (e.g. "Nøgler & Rotation — Test" derived from `APP_ENV`). A true side-by-side
   Test↔Prod view is exactly the "Test ↔ Prod drift" idea panel the epic already deferred (D6) — its natural
   future home is a small addition to `sync.js` that also carries secret-age metadata alongside the DB sync,
   giving Test a read-only mirror of Live's ages (never the reverse, and never live values). Flagged as the
   extension point, not built now.

5. **Secret rotation writes are a targeted single-line replace + atomic swap, not a full-file rewrite.**
   `config.test.php`/`config.live.php` are plain `define('NAME', 'value');` lines (confirmed in
   `config.example.php`). "Roter nu" for `NAME`:
   - Copies the current config file to a timestamped backup (`config.php.bak.<unix-ts>`, same directory,
     `chmod 0600`) — satisfies NFR-304's backup-before-write requirement.
   - Regex-replaces only the one matching `define('NAME', '...')` line in a copy of the file content, writes
     that copy to a temp file in the same directory, then `rename()`s it over the live file — atomic on the
     same filesystem, and NFR-303's "rest of the file untouched" falls out of doing a targeted line replace
     instead of regenerating the whole file from an array.
   - Calls `opcache_invalidate($configPath, true)` if the function exists, after the rename. **Without this,
     a rotated secret can silently keep serving the old value from OPcache** until the file's mtime is next
     checked (or indefinitely, if `opcache.validate_timestamps=0` — common hardening on shared hosting) —
     a real correctness bug the naive "just write the file" version of this feature would ship with.
   - On any failure (unwritable file, regex not matching exactly one line, rename failure), the attempt is
     logged to the audit table as a failed rotation (NFR-305) and the admin sees an error state; the backup
     is never deleted automatically, so a bad rotation is always recoverable by hand.
   - **The backup step is a hard precondition** (test-manager condition): if the backup copy itself can't be
     created, the replace/rename never runs — logged distinctly from a replace/rename failure so the two
     failure modes aren't conflated in the audit trail.
   - **Test-manager condition:** this same function (not a duplicate/parallel path) is what E2E exercises,
     via the `e2e_token`-gated redirect described in decision 4/feature-3 — the target file path is swapped
     for a fixture path only when E2E mode is active, and that redirect is hard-blocked whenever
     `APP_ENV === 'live'` regardless of token validity. This closes the seam a "test the writer in total
     isolation" approach would leave between the DB/audit write and the actual file write.
   - **Bootstrapping (test-manager condition):** the schema migration (decision 6) seeds one
     `admin_secret_state` row per real configured item with a conservative initial `rotated_at` (e.g. the
     config file's own filesystem mtime) rather than leaving rows absent — so health score and badges are
     defined from the very first page load after this feature deploys, not computed against missing data.
   - **Per-secret `mode` gates whether this write path runs at all (implementation-discovered, safety-
     critical revision to feature-3's REQ-308).** Auditing every secret actually in `config.example.php`
     against "generate a fresh value and write it" found it would be an active lockout/outage bug for most of
     them: `MFA_KEY` rotation makes every stored TOTP secret undecryptable; `PASSWORD_PEPPER` rotation makes
     every stored password hash fail to verify; `DB_PASS`/`SMTP_PASS` are external-system credentials (MySQL/
     Proton) that break immediately if only the local copy changes; `INTEGRATION_SEED_TOKEN`/`CRON_SECRET`
     are each paired with a matching GitHub Actions repo secret and break CI/cron until that's updated too.
     Only `CHALLENGE_INVITE_SECRET` (stateless HMAC, no persisted state, no external pairing) is genuinely
     side-effect-free to regenerate. **Consequence:** the config-file-writer above still exists and is still
     real, but the static secret config (decision 6's per-item array) carries a `mode` field —
     `'auto'` (writer actually runs; v1 = `CHALLENGE_INVITE_SECRET` only) vs `'record'` (same "indtast ny
     dato"-style recording as access tokens; the human rotates it via the actual correct channel — MySQL,
     Proton, the paired GitHub secret, or a dedicated pepper/key migration entirely out of scope here — then
     just records that it happened). This is not a smaller feature: age tracking, the health score, and the
     audit log — the epic's actual success metric — apply identically regardless of mode.

6. **Schema: two new small tables, environment-implicit (no `env` column needed)** — because of decision 4,
   each environment's own DB only ever holds that environment's own rows, so there's nothing to disambiguate:
   ```sql
   CREATE TABLE admin_secret_state (
       id            INT AUTO_INCREMENT PRIMARY KEY,
       item_key      VARCHAR(64) NOT NULL UNIQUE,   -- e.g. 'secret:db_password', 'token:github_pat'
       item_type     ENUM('secret','token') NOT NULL,
       rotated_at    DATETIME NULL,                  -- secrets: age = NOW() - rotated_at
       rotated_by    VARCHAR(100) NULL,
       expires_at    DATETIME NULL                   -- tokens only; secrets leave this NULL
   );
   CREATE TABLE admin_audit_log (
       id          INT AUTO_INCREMENT PRIMARY KEY,
       actor       VARCHAR(100) NOT NULL,
       action      VARCHAR(40) NOT NULL,   -- 'rotate_secret' | 'record_token_expiry' | 'rotate_secret_failed'
       item_key    VARCHAR(64) NOT NULL,
       detail      TEXT NULL,
       created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
   );
   ```
   Per-item **static** config (display name, icon, policy-days, provider) stays in a PHP array in
   `public/includes/admin-dashboards/nogler-rotation-lib.php` — same "static config, not a table" pattern
   `actions-dashboard.php` already uses for its 9-workflow config, kept consistent rather than inventing a
   second convention for near-identical data.

7. **PaddockKB dashboard reuses the GitHub Actions dashboard's existing API client**, rather than building a
   second run-history mechanism. "Sidste opdatering"/"Seneste ingest-kørsler" read the existing
   `ghListWorkflowRuns()` (from `actions-dashboard.php`) filtered to the KB-ingest/content-topup workflow(s) —
   the same run history already shown on the GitHub Actions tab, just re-surfaced with PaddockKB framing.
   Entry/category/index-size KPIs read `public/paddock-rumors/knowledge-base.json` (the deployed
   copy `public/paddock-rumors/query.php` also reads — **not** `paddock-rumors/data/knowledge-base.json`,
   which is the git-repo/CI-only master and is never uploaded; only `public/` is deployed — this was
   wrong in an earlier draft of this plan and fixed after an E2E run against the real test
   environment caught it) directly and live (per
   project memory the KB is currently under 100 docs — cheap to read on every page load, no cache needed yet).
   **"Kør opdatering nu" needs a GITHUB_TOKEN scope bump**: the existing token
   (`epics/github_actions_dashboard/plan.md`'s prerequisite) is read-only (`actions:read`); triggering
   `workflow_dispatch` needs write access to Actions. Flagged in `config.example.php` and
   `docs/github-actions.md` as a required scope change, not silently assumed.

8. **Challenges usage dashboard is pure aggregate SQL over existing tables** (`challenge_participants`,
   `challenge_points`, `duels`, `duel_predictions`, `challenge_trivia_answers`, `challenge_items`,
   `challenge_answers`) — no new schema. Per feature-5's NFR-501, its participant-count query reuses whatever
   function/query the existing Paddock Challenges Members tab (`public/includes/admin-challenges/members.php`)
   already uses for its own count, rather than a second hand-rolled `COUNT(*)`, so the two views can never
   silently disagree.

9. **Oversigt is pure composition, built last.** Each of the other three new dashboards plus the existing
   Actions dashboard exposes one small "snapshot" function (`nrGetHealthSnapshot()`, `ghGetFailingSummary()`,
   `kbGetHealthSnapshot()`, `chGetUsageSnapshot()`) that Oversigt's partial calls directly via `require_once`
   — no re-querying, no second computation of the health score or success rate, satisfying feature-2's
   NFR-201 by construction rather than by convention alone.

## Files

**New:**
- `public/admin-dashboards.php` — router (`?tab=`), includes the shared nav + the active tab's partial.
- `public/includes/admin-area-nav.php` — `renderAdminAreaNav()`, extracted from the three existing pages.
- `public/includes/admin-dashboards/oversigt.php`, `keys.php`, `paddockkb.php`, `challenges.php`, `actions.php`
  (the last one is `admin-actions.php`'s current body, moved).
- `public/includes/admin-dashboards/nogler-rotation-lib.php` — static item config, health-score/badge pure
  functions, the config-file safe-writer (backup → targeted replace → atomic rename → opcache invalidate),
  audit-log helpers.
- `database/schema.sql` additions — `admin_secret_state`, `admin_audit_log` (see decision 6).
- `tests/e2e/admin/15-dashboards-nav.spec.js` — two-tier nav + old-URL redirect coverage.
- `tests/e2e/admin/16-dashboards-oversigt.spec.js`, `17-dashboards-nogler-rotation.spec.js`,
  `18-dashboards-paddockkb.spec.js`, `19-dashboards-challenges-usage.spec.js`.
- `tests/unit/nogler-rotation-harness.php` — health-score formula + badge thresholds, CLI harness style
  matching `tests/unit/actions-schedule-harness.php`.
- `docs/admin-dashboards.md` — documents the two-tier nav, the five Dashboards tabs, the per-host
  environment-implicit limitation on Nøgler & Rotation (decision 4) and why, the two new tables, the config
  safe-write mechanism, and the new GITHUB_TOKEN scope requirement.

**Edited:**
- `public/admin.php`, `public/admin-challenges.php` — replace inline nav block with
  `renderAdminAreaNav('core'|'challenges', $challengesPromoCount)`; area link that pointed at
  `admin-actions.php` now points at `admin-dashboards.php`.
- `public/admin-actions.php` — gutted to the redirect shim (decision 2).
- `public/includes/actions-dashboard.php` — no logic change, but its `ghListWorkflowRuns()` (and friends) are
  now also called from `nogler-rotation-lib.php`'s sibling `paddockkb.php`; add `ghTriggerWorkflowDispatch()`
  for "Kør opdatering nu" if it doesn't already exist in a usable form.
- `public/assets/css/style.css` — Level-1/Level-2 nav rules generalized for a 4-tab-wide row under
  Dashboards (today's rules only had to handle 3 areas' tab rows); new dashboard-layout block (tiles, health
  ring, KPI grid, progress bars) — reuses existing `--bg-card`/`--border-color`/`--text-*`/`--f1-red`/
  `--status-*-light` tokens throughout (verify current presence of `--radius-*`/`--status-*-light` added by
  the GitHub Actions feature before assuming any are still missing).
- `public/lang/admin.php` — new da+en keys for the nav area label and all 4 new dashboards, checked against
  existing keys for accidental duplicates before merging (this repo's known array-literal footgun).
- `config.example.php` — document the GITHUB_TOKEN scope bump (read-only → also `actions:write` for
  workflow_dispatch); no new secret needed for rotation itself (it writes to the config file it's already
  running from).
- `docs/github-actions.md` — note the reparenting under Dashboards and the new token scope requirement.
- `CLAUDE.md` — add a doc-table row for `docs/admin-dashboards.md`.

## Phased build order

**Phase 1 — nav shell foundation**
- Extract `admin-area-nav.php`; wire into `admin.php` / `admin-challenges.php`.
- Build `admin-dashboards.php` router with all 5 Level-2 tabs present; only `actions` fully wired (content
  moved from `admin-actions.php`), the other 4 render a "coming soon" placeholder.
- `admin-actions.php` → redirect shim. Update `14-actions-dashboard*.spec.js` for the new canonical URL +
  add the redirect assertion.
- CSS: generalize the nav rules for 5 tabs; verify/patch design tokens.

**Phase 2 — read-only dashboards**
- PaddockKB partial (reuses GitHub API client + reads KB json).
- Challenges usage partial (SQL aggregates, reusing the Members tab's own participant-count query).
- Oversigt partial (composes the four snapshot functions) — built last in this phase since it depends on the
  other three (and on Nøgler & Rotation's snapshot from Phase 3, so Oversigt's own final wiring actually
  lands at the start of Phase 4).

**Phase 3 — Nøgler & Rotation**
- Schema migration (`admin_secret_state`, `admin_audit_log`), seeded with a conservative bootstrap row per
  real item (see testing-review condition below) so health score/badges are never computed against undefined
  state on first deploy.
- Static per-item config (policy days, display name, icon).
- Health score + badge pure functions + unit harness.
- Token-record action (no external calls — pure DB write + audit row).
- Secret-rotation action: confirm step → value generation → safe config write (backup, targeted replace,
  atomic rename, opcache invalidate) → audit row. Double-submit guard (NFR-301).
- Apply decision 4 (no cross-host toggle; explicit per-environment page copy).
- **Hard gate (test-manager condition, non-negotiable):** the "Roter nu" route/button must not be merged or
  deployed to *any* environment — including test — until confirmation, audit logging, and the double-submit
  guard are ALL implemented and landing together in the same change. A half-safeguarded write endpoint being
  briefly reachable (e.g. audit logging not wired yet) is exactly the kind of shortcut this review process
  exists to block. If Phase 3 needs to land incrementally for review purposes, keep the button/route entirely
  unlinked (not just unlabeled) from the UI until the full slice is done.

**Phase 4 — Oversigt finalization, tests, docs**
- Wire Oversigt's Nøgler & Rotation tile now that Phase 3 exists.
- E2E specs 16–19; unit harness for Nøgler math.
- `docs/admin-dashboards.md`, `CLAUDE.md` doc-table row, `config.example.php` GITHUB_TOKEN scope note,
  `docs/github-actions.md` reparenting note.

## Testing approach

Reviewed via `/test-manager` — verdict **APPROVE WITH CONDITIONS**; all conditions folded in below (and into
the affected feature docs — see epic decisions D7/D8). Original first-pass proposal understated Feature 3's
risk: it tested the config-writer's mechanics in isolation from the DB/audit path that a real "Roter nu"
click actually exercises, left the health-score/badge math undefined for several boundary and bootstrap
inputs, and didn't test true request-level concurrency (only single-session double-click). All three are
fixed below; see `git log` on this file if the prior draft is wanted for reference.

**Unit level (`tests/unit/nogler-rotation-harness.php`, DB-free, mirrors the existing harness style):**
- Health-score formula: 0 issues → 100; mixed issues → exact formula result; enough issues → clamps at 0, not
  negative.
- Badge thresholds: secret age at exactly 80%/100% of policy; token at exactly 14 days / past expiry.
- **Boundary/malformed-input cases added by review:** a secret with `policy = 0` does not divide-by-zero
  (treated as immediately overdue); a negative age (rotated_at in the future, e.g. clock skew) clamps to
  0/OK rather than displaying negative; a secret with no `admin_secret_state` row yet (fresh migration, see
  Phase 3) renders a defined "unknown age" state, not undefined behavior or a silently-assumed age of zero.
- Config-file line-replace: exact single-line match/replace on a fixture string resembling
  `config.example.php`'s format, asserting every other line is byte-identical before/after; **a fixture with
  the target constant defined twice must fail closed** (ambiguous target — abort, modify neither line) rather
  than silently replacing the first or last match.

**E2E level:**
- Nav reparenting (`15-dashboards-nav.spec.js`): two-tier nav renders correctly per area; old
  `admin-actions.php` URL (with and without query string) 302-redirects to
  `admin-dashboards.php?tab=actions`; non-admin access rejected on the new router the same as the old page.
- Oversigt (`16-*`): healthy state shows no flags; a seeded failing condition (e.g. a fixture-mode GitHub
  Actions failure, reusing the existing `e2e_gh_fixture` mechanism) surfaces in the needs-attention strip
  with a working deep link; read-only (no POST issued anywhere on the page); **a tile whose backing dashboard
  doesn't exist yet at that point in the rollout (e.g. Oversigt reachable before Phase 3 lands) renders a
  "coming soon" state, not a fatal error** — added by review, since the phased build order genuinely creates
  this window.
- Nøgler & Rotation (`17-*`) — **the one feature needing a real write path, and the one review found the
  most gaps in:**
  - The original "test the writer in isolation against a throwaway path" approach left the *integration
    seam* between "user clicks Roter nu on a real item" and "the file actually gets written" completely
    uncovered — a unit test of the writer and an E2E test of the DB/audit path never met in the middle.
    **Fixed:** the config-writer's target path is resolved through the same `e2e_token`-gated convention
    `admin-actions.php` already uses, redirecting writes to a fixture file when E2E mode is active — so a
    real, fixture-named item in `admin_secret_state` exercises the *actual* production code path end to end
    (DB update → config-writer → audit row) in one pass, just pointed at a throwaway file instead of
    `config.test.php`. **This redirect must be hard-blocked whenever `APP_ENV === 'live'`, independent of
    token validity** — the same "structurally unreachable on live" guarantee `docs/github-actions.md`
    already documents for the GitHub Actions dashboard's own fixture mode, tested explicitly here too.
  - Token-record flow (date input → save → recompute) tested end-to-end normally since it has no external
    side effect.
  - Double-submit protection tested at the **request level with two near-simultaneous confirmed requests**,
    not just a single session's double-click — a sequential double-click and a genuine race are different
    bugs, and only the former was in the original proposal.
  - Backup-write failure is its own test case, distinct from replace/rename failure: if the pre-write backup
    can't be created, no config mutation happens at all, and the audit log records which of the three steps
    failed.
  - Generated-value format/entropy is checked against the target secret's documented generation scheme (e.g.
    `bin2hex(random_bytes(32))` → exactly 64 hex chars), not just "a rotation happened."
  - Plaintext value not echoed; non-admin access rejected.
- PaddockKB (`18-*`): run log shows both success and failure outcomes (fixture-mode, same `e2e_gh_fixture`
  pattern as the GitHub Actions dashboard); "Kør opdatering nu" is gated the same fixture way in E2E (never
  fires a real `workflow_dispatch` from a test run) with a documented manual smoke-test step for the real
  trigger; **a missing/insufficiently-scoped GITHUB_TOKEN (read-only, lacking `actions:write`) produces a
  clear "insufficient permissions" message, not a silent no-op or generic failure** — added by review,
  mirroring the prerequisite-missing handling the GitHub Actions epic's own plan already had for its token.
- Challenges usage (`19-*`): KPI figures match a seeded fixture exactly; per-game cards stay independent
  (don't cross-contaminate totals); numbers agree with the existing Members tab's own count for the same
  fixture data; **the funnel's top-of-funnel step degrades cleanly (omitted or clearly marked unavailable,
  never a fabricated zero) if visitor data isn't sourced** — added by review.
- All five new/changed pages: non-admin/logged-out access rejected, consistent with every existing admin
  page.

**Rollout-safety condition (test-manager, non-blocking for other phases but hard-blocking for Phase 3):** the
"Roter nu" endpoint must never be reachable — in test or live — before confirmation, audit logging, and the
double-submit guard are all implemented together; see the hard gate now written into Phase 3 above and epic
decision D8.

## Deferred (not in this plan, flagged for a future pass if wanted)

- **Live Test↔Prod cross-host comparison** for Nøgler & Rotation (decision 4) — needs a new metadata channel
  (e.g. piggybacking on `sync.js`), not built here.
- **PaddockKB "Query-brug & svar-kvalitet" panel** (top queries + source-hit coverage %) — the handoff itself
  marks it "Idé"; ships only if query logging already exists for this pipeline, which hasn't been verified.
- **Challenges usage funnel's top-of-funnel "visitors" step** — likely not sourced from existing data; the
  participated→registered→requested-membership portion can ship without it (see feature-5).
- **Full raw GitHub Actions log text** — already deferred by the GitHub Actions sub-epic itself; unaffected by
  this plan.
