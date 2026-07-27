# Epic: Real Expiry & Rotation for Content-Topup

Refined from the one-liner note (`One-liner learning from challenges simulation`) via
`/f1betting-product-owner` and `/web-architecture-review`, grounded in this repo's actual
conventions (procedural PHP, no framework, PDO/MySQL, no build step) and in the incident history
already captured in memory (`project_rumor_playable_test_fixture`,
`project_paddock_challenges_phase1`). Two features, deliberately ordered so the second is the
one Djarnis (product owner) actually asked to see:

1. **Feature 1 — Content Expiry & Archival Engine.** The mechanism: real rotation for
   content-topup output so stale published rumors/trivia don't accumulate forever, plus the ~4
   e2e tests rewritten to tolerate coexisting real content instead of requiring a pristine DB.
2. **Feature 2 — Content Health Dashboard (the feature enabler).** The visibility: a single
   place for the product owner to see, at a glance, how much Paddock Challenges content is live,
   how much is stale/archived, whether Friday's content-topup batch actually landed, and how many
   weeks of KB material are left — without querying the DB by hand, which is how every incident
   in `project_rumor_playable_test_fixture` was diagnosed so far.

Feature 2 depends on Feature 1's `archived` status existing (it reports on it) but is the reason
this epic exists from the product owner's side — Feature 1 alone would fix e2e flakiness
invisibly; Feature 2 is what makes the content pipeline's health legible to a human.

---

## Origin

Every week since the content pipeline started auto-publishing (`cron-content-topup.yml`, Fridays
06:00 UTC, see `docs/paddock-challenges-reference.md`), a fresh batch of ~6 rumor items + ~6
trivia questions goes live with no admin review and — critically — nothing ever expires or reaps
the previous batches. `cleanup_challenges()` (`public/tools/test-seed.php`) only ever purges rows
tagged `source_ref`/`topic = 'e2e-seed'`; real content is untouched forever. This has caused a
recurring (documented weekly, since 2026-07-18) class of e2e failures in
`tests/e2e/challenges/{42-rumor,43-trivia,46-admin-challenges}.spec.js`, each diagnosed by hand via
direct SQL, with two near-misses where the manual fix (unpublishing stale rows to make e2e green)
accidentally zeroed out **real** visitors' live rumor deck / trivia week on test. The 2026-07-26
recurrence note is explicit: these 4 failures are "expected noise until... someone builds a
durable expiry/rotation for stale content-topup output (still not built)." This epic builds that,
and — per Djarnis's explicit ask — pairs it with a way to *see* the resulting content state
without re-running that manual SQL runbook every time.

---

## User Value

**For the product owner:** right now, "is the content pipeline healthy?" can only be answered by
running ad-hoc SQL against the test DB (as documented step-by-step in
`project_rumor_playable_test_fixture` five separate times) or by noticing e2e failures and working
backwards. This epic replaces that with (a) content that actually rotates on its own, so the
question mostly stops needing to be asked, and (b) one dashboard panel that answers it directly
when it does.

**For players (indirect):** rumor/trivia content that's been sitting unanswered for months without
ever refreshing is a worse experience than a deck that visibly rotates; archiving (never deleting)
also means CP history and Perfect Week displays stay correct forever, closing the exact
denominator bug hit twice already (2026-07-20, 2026-07-24).

## User Experience

- The Friday→Monday content cadence is unchanged from a player's point of view — new content still
  appears every Monday, nothing about `nextRumorItem()`/`nextTriviaQuestion()`'s player-facing
  behavior changes.
- For the admin: `admin-challenges.php`'s Rumors/Trivia tabs gain an `archived` state alongside
  `draft`/`published` (filterable, same UI convention as the existing All/Drafts/Published filter),
  plus a manual Archive/Restore action next to the existing bulk publish/unpublish/delete actions.
- For the product owner: `admin-dashboards.php?tab=challenges` gains a **Content Supply** panel
  (next to the existing player-usage panel) showing live/stale/archived counts, last content-topup
  run status per environment, and KB runway — the same "one glance, no SQL" experience the
  Nøgler & Rotation and PaddockKB tabs already give for their own domains.

## Success Metrics

- The 4 recurring e2e failures drop from "expected weekly noise" to genuinely rare — rewritten to
  assert against a scoped/marked item rather than global deck state, so they pass regardless of
  how much real content coexists.
- No incident like 2026-07-20/2026-07-24 (an unpublish-to-fix-e2e action accidentally zeroing a
  real environment's live deck) recurs, because there's no longer a manual unpublish step at all —
  archival only ever touches content outside the current live window, enforced in code.
- Time to answer "is this week's content batch live and healthy?" drops from a multi-query manual
  SQL session to one dashboard page load.
- `challenge_items`/`challenge_trivia_questions` row growth over time is unbounded no longer that a
  clean linear function of "batches run × 12" — the `archived` count grows instead of the
  `published` count growing forever.

## Acceptance Criteria

```gherkin
Feature: Real expiry & rotation for content-topup

  Scenario: Old trivia questions from a fully-elapsed ISO week get archived automatically
    Given a trivia question published 3 ISO weeks ago, still status='published'
    When the Monday content-health cron step runs
    Then that question's status becomes 'archived'
    And its existing challenge_trivia_answers rows and challenge_points CP awards are untouched

  Scenario: Archival never empties the currently-live deck
    Given every currently-published rumor item is older than the normal stale threshold
    When the archival step runs
    Then no rows are archived this run
    And the Content Health dashboard flags "rumor deck cannot rotate safely" instead

  Scenario: Product owner can see content state without writing SQL
    Given at least one stale batch exists and this Friday's content-topup run has completed
    When the product owner opens Dashboards → Challenges
    Then they see live/stale/archived counts, last content-topup run status per environment,
      and estimated KB weeks remaining, all without leaving the page

  Scenario: E2E tests tolerate coexisting real content
    Given real published rumor/trivia content coexists with e2e-seeded fixtures in the test DB
    When the Paddock Challenges e2e suite runs
    Then the 4 previously-flaky specs pass regardless of how much real content is present
```

---

## Architecture Decisions

1. **Archive via a new `status='archived'` value, never a hard delete.** Both `challenge_items`
   and `challenge_trivia_questions` already carry `status ENUM('draft','published')`
   (`database/schema.sql`); this epic adds `'archived'` to both enums via a normal migration
   (`database/add_content_archival.sql`, applied manually per this repo's existing migration
   convention — see `docs/gotchas.md`/`schema:check`). Archiving is a plain `UPDATE ... SET
   status='archived'`, identical in shape to today's manual unpublish workaround, so it never
   touches `challenge_answers`/`challenge_trivia_answers`/`challenge_points` — no `ON DELETE CASCADE`
   is ever triggered, no FK considerations at all. This is why archival is safe against the known
   denominator tension: it doesn't delete rows, it doesn't orphan CP history, and (decision 2)
   it's scoped to never touch anything still contributing to a live counter.

2. **The "never empty the live deck/week" guard from the manual runbook becomes actual code, not
   a checklist step.** Trivia is the easy case: `nextTriviaQuestion()` already scopes serving to
   `YEARWEEK(publish_date,3) = YEARWEEK(CURDATE(),3)` (REQ-402), so any question whose ISO week has
   fully elapsed is *by construction* no longer reachable by any player — archiving it can never
   reduce what's currently playable. Policy: archive trivia questions once their ISO week is **2
   full weeks in the past** (one week of buffer past "just ended", matching the existing
   Perfect-Week-bonus cron's own timing, in case of any late scoring/backfill). Rumor is the harder
   case — REQ-204 deliberately has no expiry concept (unanswered items roll forward indefinitely).
   This epic **amends REQ-204**: archive published rumor items older than a fixed
   `RUMOR_STALE_WEEKS` threshold (proposed: 6 weeks, roughly one KB quarter) *unless* doing so would
   drop the remaining published+in-window count below `RUMOR_MIN_LIVE` (proposed: 6, one batch's
   worth) — mirroring exactly the manual check that caught the 2026-07-24 near-miss
   (`project_rumor_playable_test_fixture`). If the guard blocks archival, nothing is archived that
   run and it surfaces as a flag on the Feature 2 dashboard instead of failing silently.

3. **Run archival from the existing Monday cron, not a new workflow.** `public/cron/challenge_weekly.php`
   already runs Monday 05:00 UTC via `.github/workflows/cron-challenges.yml`, right after the new
   Friday batch has gone live and right when the previous week's Perfect Week bonus is computed —
   the natural moment to also archive rows that are now provably stale. Adding an `archiveStaleContent($db)`
   step here (new function in `includes/challenges.php`) reuses existing infra (auth via
   `CRON_SECRET`, existing test coverage pattern) rather than standing up a second scheduled
   workflow. No change to `cron-content-topup.yml` itself.

4. **Content Health reuses the GitHub Actions cache, not a new polling mechanism.**
   `cron-content-topup.yml` is already registered in `ghWorkflowConfig()`
   (`public/includes/actions-dashboard.php:24-27`, cron string `'0 6 * * 5'`) and its runs are
   already fetched and cached via `ghListWorkflowRunsMulti()` (`public/cache/github-actions/*.json`,
   see `docs/github-actions.md`) — the same mechanism `ghGetHealthSnapshot()` uses for Oversigt.
   "Did Friday's batch run, and did it succeed, per environment" is answered by reading that same
   cache for `cron-content-topup.yml`'s recent runs and comparing the latest `run_started_at`
   against its declared cron cadence — no new GitHub API polling, no new infrastructure.

5. **Content Health lives inside the existing Challenges dashboard tab, as a second panel — not a
   new top-level tab.** `admin-dashboards.php?tab=challenges` (`includes/admin-dashboards/challenges.php`
   + `challenges-usage-lib.php`) already exists for Paddock Challenges and today shows only
   player-usage analytics (`chGetUsageSnapshot()`). Rather than adding a 6th dashboard tab for a
   closely-related concern, this epic adds a sibling function, `chGetContentHealthSnapshot()`, in
   the same file, rendered as a second panel on the same tab. This also finally gives the
   Challenges tab a real `flagCount` for Oversigt (today hardcoded to `0` — "no natural problem
   state" — because usage analytics genuinely has none; content supply does: overdue batch, blocked
   archival, low KB runway).

6. **KB runway reads the same files the generators already read — no new state.**
   `bin/state/{rumor,trivia}-generator-state.<env>.json` (used-doc-id tracking, per environment,
   already committed to the repo) and `paddock-rumors/data/knowledge-base.json` (total doc count,
   already read read-only by the generators themselves per `docs/paddock-challenges-reference.md`)
   are both plain JSON files on disk the PHP admin process can read directly — same read-only access
   pattern the Node generators already use, just from PHP instead of from `bin/generate-*.js`.
   Runway is `(total KB docs − used docs) ÷ docs-consumed-per-week`, surfaced as "~N weeks
   remaining," matching the "watch for a failed matrix job" signal already documented but turning it
   into a proactive number instead of a reactive GitHub Actions failure.

7. **E2E rewrite strategy: scope assertions to a marked item, don't require a pristine deck.**
   The 4 recurring failures fall into two classes (per `project_rumor_playable_test_fixture`):
   (a) the two admin "Publish makes X playable" tests, which lose the `ORDER BY publish_date ASC,
   id ASC` tie-break to older real content because the admin Publish action stamps `publish_date` as
   *today* via production code, not via a seed-controlled sentinel; (b) the two tests that require
   the *entire* deck/week to be empty (`answered item drops out of the queue`, `rumor import without
   a status stays a draft`), which are structurally incompatible with any coexisting published
   content, however it got there. Fix for (a): assert against the specific just-published item by
   its known id/text rather than "whatever's on top of the deck" — the production Publish flow
   doesn't need to change, only what the test looks for. Fix for (b): assert "the seeded item is no
   longer served to this participant" / "the seeded draft never renders as a card," not "the deck is
   empty" — a scoped, participant- or item-specific assertion instead of a global-state one. Once
   Feature 1's archival keeps the test DB's real-content footprint small and bounded (rather than
   accumulating since mid-July), residual flakiness should also drop even before the rewrite, but the
   rewrite is what makes the tests correct rather than merely lucky.

---

## Features

### [Feature 1: Content Expiry & Archival Engine](feature-1-content-expiry-archival.md)
The mechanism — schema change, archival cron step, admin archive/restore controls, and the e2e
rewrite. See linked doc for REQ-6xx/NFR-6xx and full Gherkin scenarios.

### [Feature 2: Content Health Dashboard](feature-2-content-health-dashboard.md)
The feature enabler — the product-owner-facing panel this epic exists to deliver. See linked doc
for REQ-7xx/NFR-7xx and full Gherkin scenarios.

---

## Files

**New:**
- `database/add_content_archival.sql` — adds `'archived'` to both status enums (Feature 1).
- `epics/Real expiry & rotation for content-topup/feature-1-content-expiry-archival.md`
- `epics/Real expiry & rotation for content-topup/feature-2-content-health-dashboard.md`

**Edited:**
- `public/includes/challenges.php` — new `archiveStaleContent($db)`, `wouldEmptyLiveRumorDeck()`
  helper (Feature 1).
- `public/cron/challenge_weekly.php` — call the new archival step after the existing Perfect Week
  bonus / GDPR purge (Feature 1).
- `public/admin-challenges.php` + `includes/admin-challenges/{rumors,trivia}.php` — Archive/Restore
  actions, status filter gains "Archived" (Feature 1).
- `public/includes/admin-dashboards/challenges-usage-lib.php` — new `chGetContentHealthSnapshot()`
  (Feature 2); `chGetUsageSnapshot()`'s `flagCount` starts reflecting real content-health flags.
- `public/includes/admin-dashboards/challenges.php` — render the new Content Supply panel
  (Feature 2).
- `tests/e2e/challenges/42-rumor.spec.js`, `43-trivia.spec.js`, `46-admin-challenges.spec.js` —
  the 4 rewritten assertions (Feature 1).
- `docs/paddock-challenges-reference.md` — document archival policy + Content Health panel;
  `docs/admin-dashboards.md` — document the new panel under "Challenges usage".

## Out of scope / Deferred

- Making `RUMOR_STALE_WEEKS`/`RUMOR_MIN_LIVE` admin-configurable via the `settings` table — start
  as fixed constants; revisit only if the fixed values prove wrong in practice.
- Doc-reuse-after-cooldown for KB exhaustion (`docs/paddock-challenges-reference.md` already flags
  this as "not implemented yet") — Feature 2 reports the runway number, it doesn't solve exhaustion.
- A cross-environment (test vs. live) content-health comparison beyond showing both side by side —
  no shared channel between the two hosts exists (see `docs/admin-dashboards.md` → "No live
  environment toggle"), and building one is out of proportion to this epic.
