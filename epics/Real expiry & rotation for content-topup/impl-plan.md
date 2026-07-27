# Implementation Plan — Real Expiry & Rotation for Content-Topup

Sequenced, verifiable rollout for `plan.md`'s two features. Each phase lands, deploys to **test**,
and is verified against its own relevant test coverage before the next phase starts — same
incremental discipline as the Admin area redesign epic (`epics/Admin area redesign/plan.md`,
decision 8), not a big-bang change. **Live deploy is a separate, explicitly user-directed step**
after all phases are green on test (per the existing live-deploy-gate convention) — not implied by
any phase below.

Dependency shape: Phase 0 → 1 → 2 → 3 are strictly sequential (schema → engine → cron → e2e
rewrite). Phase 4 (admin Archive/Restore UI) and Phases 5–6 (Content Health dashboard) both depend
only on Phase 0–1 (the `archived` status existing) and can be built in either order or in parallel
once Phase 1 is deployed. Phase 7 (docs) closes out after everything else.

**Reviewed via `/test-strategy-manager` — one structural correction made as a result:** this
codebase has exactly two automated test tiers, no more. `tests/unit/*.php` (wired into
`test:unit`) are **DB-free PHP CLI harnesses** — every existing one (`duel-scoring-harness.php`,
`hero-window-harness.php`, etc.) `require`s `includes/challenges.php` directly with **no DB
connection** and calls a pure function with in-memory fixture data; that only works because the
functions they test never touch `$db`. `tests/e2e/*` (Playwright, wired into `test:e2e:test`) is
the **only** tier that exercises a real database, over real HTTP, via `test-seed.php`. There is no
PHPUnit and no mocked-DB integration tier here. The first draft of this plan glossed over that and
called for a "unit harness" for `archiveStaleContent()` and `chGetContentHealthSnapshot()` — both
of which need a live `$db` (COUNT/UPDATE queries) or read real files off disk, so neither can be a
harness in the existing sense as a whole function. Fixed below by splitting each into a pure,
harness-testable decision core plus a thin DB/file-orchestrating wrapper verified a different way
— see Phases 1 and 5.

---

## Phase 0 — Schema migration

**Deliverable:** `'archived'` added to both status enums. Purely additive — no existing row,
query, or code path changes behavior (nothing currently reads `status='archived'`, so this is
inert until Phase 1 writes to it).

- **Files:** `database/add_content_archival.sql` (new), registered in `migrations.json`.
- **Change:** `ALTER TABLE challenge_items MODIFY status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft'`, same for `challenge_trivia_questions`.
- **Verify:** `npm run schema:check` (test) comes back clean after manual application; no other
  test suite should be affected by this phase alone — a good checkpoint to confirm via one full
  `test:e2e:test` run that nothing regresses from the enum change itself before writing any code
  against it.
- **Risk:** none identified — additive enum value, backward compatible with every existing query
  (`WHERE status='published'`/`'draft'` continues to match exactly the same rows as before).

## Phase 1 — Archival engine (core logic)

**Deliverable:** `archiveStaleContent(PDO $db): array` exists and is directly callable, but not yet
wired to any cron or UI. **Split into a pure decision core + a thin DB wrapper**, specifically so
the decision logic — the part most worth protecting with a fast, deterministic test — fits this
codebase's existing zero-DB harness convention instead of needing a database to exercise at all:

- `rumorArchiveBudget(int $liveCount, int $floor): int` — pure, no DB: given how many rumor items
  are currently live and the configured floor, returns how many of the stale candidates may
  actually be archived this run (0 if `liveCount <= floor`). This is the exact piece of logic
  behind REQ-604/NFR-601 and the one most likely to have an off-by-one.
- `archiveStaleContent(PDO $db): array` — thin orchestrator: `SELECT` the current live rumor
  count and the stale-candidate set, call `rumorArchiveBudget()` for the cap, run the trivia
  `UPDATE` (REQ-603) and the capped rumor `UPDATE` (REQ-604), return the summary array. This part
  is inherently DB-touching and is **not** unit-harness-testable as a whole — verified per below.

- **Files:** `public/includes/challenges.php` (new constants `RUMOR_STALE_WEEKS=6`,
  `RUMOR_MIN_LIVE=6`; new `rumorArchiveBudget()`, `archiveStaleContent()`).
- **Test-only trigger:** add a `run_content_archival` action to `public/tools/test-seed.php`
  (same convention as `challenge_weekly.php`'s existing `?score_week=` override — a no-op path
  that exists purely so tests can invoke the real function on demand instead of waiting for
  Monday's cron), gated `APP_ENV==='test'` like every other test-seed action. Also add a
  `seed_stale_content` action to create rumor/trivia rows with controlled ages and counts, so
  archival-boundary tests don't depend on whatever real content-topup output happens to exist that
  week.
- **Verify (pure core):** new `tests/unit/content-archival-harness.php` (wired into `test:unit`,
  same pattern as `duel-scoring-harness.php` — `require`s `includes/challenges.php` directly, no
  DB connection) covering `rumorArchiveBudget()`'s boundary math only: at the floor, one above,
  one below, zero live, floor of zero.
- **Verify (DB wrapper):** there is no PHPUnit/mocked-DB tier in this codebase to unit-test the
  wrapper in isolation — its correctness is verified by (a) the manual `run_content_archival`
  trigger + direct row inspection during this phase's rollout, and (b) continuously thereafter by
  Phase 3's real e2e suite, which exercises it against a real test DB over real HTTP. Treat Phase 3
  as this wrapper's actual integration test, not merely "the e2e rewrite."
- **Risk to watch:** ISO-week arithmetic (`YEARWEEK(publish_date, 3)`) needs the same care as the
  Phase 6 Paddock Challenges timezone bug documented in memory — verify behavior right at a
  week boundary (Sunday night/Monday morning) rather than assuming it, even though this is a pure
  SQL date comparison with no app-side `CONVERT_TZ` involved.
- **Accepted residual risk (reviewed, not fixed):** the two-step "SELECT live count, then UPDATE
  up to the computed budget" shape has a theoretical TOCTOU race if `archiveStaleContent()` ran
  concurrently with itself (two overlapping runs could each read the same stale count and,
  together, archive below the floor). Not mitigating with a lock (e.g. `nogler-rotation-lib.php`'s
  `flock()` pattern) for v1 — the only real trigger is `cron-challenges.yml`'s single scheduled
  Monday run, and the manual trigger is `APP_ENV==='test'`-gated, never reachable on live.
  Revisit only if a manual trigger is ever added to the live admin UI.

## Phase 2 — Cron wiring

**Deliverable:** `archiveStaleContent()` actually runs every Monday, in production.

- **Files:** `public/cron/challenge_weekly.php` — call the new function immediately after the
  existing Perfect Week bonus + GDPR purge steps; log the returned summary via `logToFile()`.
- **Verify:** manually trigger `challenge_weekly.php` on test (Bearer `CRON_SECRET`, same as any
  other manual cron check) and confirm the log line + resulting row states.
- **First-activation note:** test's DB already has ~8 real stale rows sitting `published` since
  2026-07-20 (per the recurring-flakiness history) — the **first** run of this phase on test will
  immediately archive whichever of those are past the stale threshold. Because archival never
  deletes rows or touches answer/CP data, this is safe regardless of those rows' answer history
  (the exact tension that made manual unpublishing risky no longer applies) — but trigger it
  manually and check the Content Supply panel (once Phase 6 exists) or a direct row count first,
  rather than letting it fire unattended for the first time.

## Phase 3 — E2E rewrite (the 4 recurring failures)

**Deliverable:** `42-rumor.spec.js`, `43-trivia.spec.js`, `46-admin-challenges.spec.js` assert
against scoped/marked items instead of global deck state (REQ-608). Does not depend on Phase 1/2
being deployed — the rewrite is valid regardless of whether archival exists yet, since it's a
correctness fix to what the test looks for, not a workaround for archival's absence.

- **Verify:** run the Paddock Challenges suite **twice** on test (this repo's existing precedent
  for ruling out flakiness, per the Phase 1 Paddock Challenges build history) with real
  content-topup output coexisting, not just against a freshly-cleaned DB.

## Phase 4 — Admin Archive/Restore UI

**Deliverable:** REQ-607 — manual per-row and bulk Archive/Restore on both tabs, "Archived" status
filter.

- **Files:** `public/admin-challenges.php` (new `archive_rumor`/`restore_rumor`/
  `archive_trivia`/`restore_trivia` + bulk variants, CSRF + `requireAdmin()`, identical wiring to
  existing `delete_rumor`/`bulk_delete_rumors`), `includes/admin-challenges/{rumors,trivia}.php`
  (buttons + filter option).
- **Verify:** extend `46-admin-challenges.spec.js` with new cases (single archive, bulk archive,
  restore, filter shows archived rows) — additive, not touching the Phase 3 rewrite's assertions.

## Phase 5 — Content Health snapshot (backend)

**Deliverable:** `chGetContentHealthSnapshot()` — the data Feature 2 renders. Same pure/impure
split as Phase 1, for the same reason: this function reads real files and queries the DB, neither
of which the existing zero-DB harness convention can exercise directly.

- **Files:** `public/includes/admin-dashboards/challenges-usage-lib.php` (new
  `parseGeneratorState(?string $json): ?array` and `computeKbRunway(?array $state, int $totalDocs,
  float $weeklyDrawRate): ?float` — both pure, take already-read strings/arrays, never call
  `file_get_contents()` themselves; `chGetContentHealthSnapshot()` — the thin wrapper that reads
  the files, runs the live/archived COUNT queries, calls `ghListWorkflowRunsMulti()`, and calls the
  two pure helpers).
- **Verify (pure core):** new `tests/unit/content-health-harness.php` covering NFR-703 directly
  with **in-memory fixture strings** — `null`, `''`, malformed JSON, well-formed JSON — asserting
  `parseGeneratorState()`/`computeKbRunway()` return `null` (never throw) on every bad input. This
  is the right way to test "missing/malformed file" here: mutating a real committed state file on
  the shared test environment to simulate corruption isn't worth the risk to a file other tooling
  (`bin/generate-*.js`) also reads — a fixture string sidesteps that entirely.
- **Verify (DB/file wrapper + live figures):** deferred to Phase 6's e2e spec, which checks the
  wrapper's actual output (live counts, cadence, runway) against a real environment, not a mock.

## Phase 6 — Content Health panel (frontend)

**Deliverable:** the actual panel on `admin-dashboards.php?tab=challenges`.

- **Files:** `public/includes/admin-dashboards/challenges.php` — new Content Supply block using
  existing `.stat-card`/`.stat-card-grid`/`.section-card` primitives, no new CSS pattern.
- **Verify:** extend `tests/e2e/admin/19-dashboards-challenges-usage.spec.js` — live/archived
  counts match `admin-challenges.php`'s own filtered counts (NFR-702), overdue/low-KB/blocked-guard
  flags render distinctly, read-only guarantee holds, test/live cadence shown independently.

## Phase 7 — Docs

- `docs/paddock-challenges-reference.md` — document the archival policy under "Content pipeline."
- `docs/admin-dashboards.md` — document the Content Supply panel under "Challenges usage."
- No CLAUDE.md change needed — nothing here changes a quick-reference-level fact.

---

## Rollout checklist

```text
□ Phase 0 deployed to test, schema:check clean, full e2e run unaffected
□ Phase 1 deployed to test, pure-logic harness green, run_content_archival manually verified once
□ Phase 2 deployed to test, challenge_weekly.php manually triggered once, log line confirmed,
  first-activation row states checked before relying on the unattended Monday run
□ Phase 3 deployed to test, Paddock Challenges suite green twice with real content coexisting
□ Phase 4 deployed to test, new admin-challenges e2e cases green
□ Phase 5+6 deployed to test, dashboard e2e green, figures cross-checked against admin-challenges.php
□ Phase 7 docs updated in the same PRs as their corresponding code, not after
□ Full test:all green on test
□ Hand deploy:live off to Djarnis explicitly — not run unprompted (feedback_live_deploy_gate)
```
