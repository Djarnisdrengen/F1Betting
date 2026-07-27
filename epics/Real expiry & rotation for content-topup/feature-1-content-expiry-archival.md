# Feature 1: Content Expiry & Archival Engine

The mechanism half of the epic — real rotation for content-topup output (rumor items + trivia
questions), so stale published content stops accumulating forever, and the 4 recurring e2e
failures (`tests/e2e/challenges/{42-rumor,43-trivia,46-admin-challenges}.spec.js`) are rewritten to
tolerate coexisting real content rather than requiring a pristine test DB. Feeds Feature 2's
Content Health panel, which reports on the `archived` state this feature introduces.

## Requirements

### Functional Requirements

- [REQ-601] `challenge_items.status` and `challenge_trivia_questions.status` each gain a third
  value, `'archived'`, alongside the existing `'draft'`/`'published'`. Archiving is a status flip
  only — never a row delete — so `challenge_answers`/`challenge_trivia_answers`/`challenge_points`
  (all FK'd to the item/question, none `ON DELETE CASCADE`d by this change since the row still
  exists) are completely unaffected. Answer/CP history for an archived item remains queryable
  forever.
- [REQ-602] `nextRumorItem()`/`nextTriviaQuestion()` (`public/challenges.php`) treat `'archived'`
  exactly like `'draft'` for serving purposes — both already filter `WHERE status='published'`, so
  no query change is needed; archived rows simply stop matching, same as if they'd been
  unpublished, but distinguishably labeled in the admin UI.
- [REQ-603] Trivia archival policy: a trivia question becomes eligible for archival once its ISO
  week (`YEARWEEK(publish_date, 3)`) is **2 or more full ISO weeks in the past** relative to the
  run date. Because `nextTriviaQuestion()` already scopes serving to the *current* ISO week only
  (REQ-402, unchanged), archiving anything from an elapsed week can never reduce what's currently
  playable.
- [REQ-604] Rumor archival policy (**amends REQ-204**, which previously specified unanswered rumor
  items roll forward with no expiry): a published rumor item becomes eligible for archival once
  `publish_date` is more than `RUMOR_STALE_WEEKS` (constant, default **6**) in the past, **unless**
  archiving it would drop the count of remaining published-and-in-window rumor items below
  `RUMOR_MIN_LIVE` (constant, default **6** — one batch's worth). If the guard blocks archival for
  a given run, no rumor items are archived that run (partial archival up to the floor is fine —
  archive oldest-first until the floor is hit, then stop).
- [REQ-605] A new function, `archiveStaleContent(PDO $db): array`, applies REQ-603/REQ-604 and
  returns a summary (`['trivia_archived' => int, 'rumor_archived' => int, 'rumor_guard_blocked' =>
  bool]`) for logging and for Feature 2 to consume. **Split for testability** (found during
  `/test-strategy-manager` review — this codebase's only "unit" test tier is DB-free CLI harnesses,
  see `tests/unit/duel-scoring-harness.php`): the floor-guard arithmetic itself lives in a pure,
  DB-free `rumorArchiveBudget(int $liveCount, int $floor): int`; `archiveStaleContent()` is the
  thin wrapper that queries the live count, calls it, and runs the actual `UPDATE`s.
- [REQ-606] `archiveStaleContent()` runs from `public/cron/challenge_weekly.php`, immediately after
  the existing Perfect Week bonus + GDPR purge steps, triggered by the existing
  `.github/workflows/cron-challenges.yml` (Monday 05:00 UTC) — no new scheduled workflow.
- [REQ-607] `admin-challenges.php`'s Rumors and Trivia tabs gain a manual **Archive** action
  (per-row and bulk, same wiring as the existing publish/unpublish/delete actions) and a
  **Restore** action (archived → draft, so a mistaken archive is reversible) — for cases needing
  immediate correction outside the weekly cron (e.g., an obviously bad batch). The existing
  All/Drafts/Published status filter gains an "Archived" option.
- [REQ-608] E2E rewrite — the 4 specs identified in `project_rumor_playable_test_fixture` are
  rewritten to scoped/marked assertions instead of global-deck-state assertions:
  - `46-admin-challenges.spec.js` → *Admin rumor drafts › Publish makes the item playable*: assert
    the just-published item's own text/id appears among rumor cards (or is servable to a
    freshly-seeded participant who hasn't answered it), not that it's literally "the" card shown.
  - `46-admin-challenges.spec.js` → *Admin trivia authoring › Publish makes the question playable*:
    same pattern for trivia.
  - `42-rumor.spec.js` → *answered item drops out of the queue and the deck-cleared state
    persists*: assert the specific seeded item is no longer returned for that participant, not that
    `nextRumorItem()` returns null globally.
  - `46-admin-challenges.spec.js` (or wherever it lives) → *rumor import without a status stays a
    draft*: assert the specific imported item never renders as a `rumor-card` (e.g. by checking its
    unique text isn't present, or that it only ever appears in the admin drafts list), not that zero
    `rumor-card`s exist on the page.

### Non-Functional Requirements

- [NFR-601] Archival must never touch a row still contributing to a currently-live counter (the
  current rumor deck's floor, the current ISO week's trivia set) — enforced by REQ-603/604, not by
  a manual pre-check.
- [NFR-602] Archival is idempotent — running `archiveStaleContent()` twice in a row (e.g. a retried
  cron invocation) must not error and must not double-log; already-archived rows are simply
  excluded from the next run's candidate set.
- [NFR-603] No change to `challenge_points`, `challenge_answers`, or `challenge_trivia_answers`
  schemas or data — this feature is additive to the two `status` enums only.
- [NFR-604] The migration (`database/add_content_archival.sql`) is applied manually to test then
  live per this repo's existing migration convention (`npm run schema:check` /
  `schema:check:live`), same as every other schema change here — not auto-applied by any script.

## User Story

**As a** product owner running Paddock Challenges as an unattended weekly content pipeline
**I want** stale published content to roll off on its own after a safe, bounded time
**So that** the live rumor/trivia pool doesn't grow forever, players see reasonably fresh content,
and I don't have to manually unpublish rows by hand (and risk zeroing a real environment's live
deck, as happened twice already) just to keep the test suite honest

## Functionality

### User Flow (automatic path)

1. Friday 06:00 UTC — `cron-content-topup.yml` publishes a fresh batch (unchanged).
2. Monday 00:00 — the batch goes live for players (unchanged).
3. Monday 05:00 UTC — `cron-challenges.yml` runs `challenge_weekly.php`: Perfect Week bonuses,
   GDPR purge (unchanged), then the new `archiveStaleContent($db)` step: archives elapsed-week
   trivia and stale-but-safe rumor items, logs a summary.
4. Content that's been archived simply stops appearing to players — no visible change from their
   side beyond a fresher-feeling deck over time.

### User Flow (manual path)

1. Admin notices a bad or unwanted batch on `admin-challenges.php` (Rumors or Trivia tab).
2. Selects the row(s), clicks **Archive** (or bulk-selects + **Archive selected**).
3. Item(s) move to `archived` status immediately, same visual treatment as the existing
   publish/unpublish/delete row actions.
4. If archived by mistake, **Restore** flips it back to `draft` for review before re-publishing.

### Technical Implementation

- `archiveStaleContent(PDO $db): array` in `public/includes/challenges.php`:
  - Trivia: `UPDATE challenge_trivia_questions SET status='archived' WHERE status='published' AND
    YEARWEEK(publish_date,3) <= YEARWEEK(CURDATE(),3) - 2` (ISO-week arithmetic via the same
    `YEARWEEK(..., 3)` mode already used by `nextTriviaQuestion()` — no new date-handling pattern).
  - Rumor: compute current published-and-in-window count; if `count - RUMOR_MIN_LIVE <= 0`, skip
    entirely and set `rumor_guard_blocked = true`; otherwise archive oldest-first
    (`publish_date ASC, id ASC` — same ordering `nextRumorItem()` already uses) rows older than
    `RUMOR_STALE_WEEKS`, capped at `count - RUMOR_MIN_LIVE` rows.
  - Both constants (`RUMOR_STALE_WEEKS = 6`, `RUMOR_MIN_LIVE = 6`) defined once near the function,
    not admin-configurable in v1 (see plan.md → Out of scope).
- `admin-challenges.php` Archive/Restore actions follow the exact existing bulk-action wiring
  (`delete_rumor`/`bulk_delete_rumors` etc.) — new `archive_rumor`/`restore_rumor` and
  `archive_trivia`/`restore_trivia` handlers, same CSRF/requireAdmin() guarding as every other
  action on this page.

## Test Scenarios

```gherkin
Feature: Content expiry & archival engine

  Scenario: Elapsed-week trivia archives automatically
    Given a trivia question published 3 ISO weeks ago, status='published'
    When the Monday cron's archival step runs
    Then the question's status becomes 'archived'
    And it no longer appears in any admin "Published" filter view
    And its existing challenge_trivia_answers and challenge_points rows are unchanged

  Scenario: Stale rumor items archive down to the safety floor, not below it
    Given 12 published, in-window rumor items older than RUMOR_STALE_WEEKS and RUMOR_MIN_LIVE=6
    When the archival step runs
    Then exactly 6 of the oldest items become 'archived'
    And 6 remain 'published'

  Scenario: Archival guard blocks a run that would empty the live deck
    Given only 5 published rumor items exist, all older than RUMOR_STALE_WEEKS
    When the archival step runs
    Then no items are archived
    And the run's summary reports rumor_guard_blocked = true

  Scenario: Admin manually archives a bad batch item
    Given an admin viewing the Rumors tab with a factually wrong published item
    When they select it and click Archive
    Then its status becomes 'archived' immediately
    And it stops being served by nextRumorItem() on the next page load

  Scenario: Restore reverses a mistaken archive
    Given a rumor item was just archived
    When the admin clicks Restore on that row
    Then its status becomes 'draft'
```

## Test Cases

```gherkin
Feature: Content expiry & archival engine — detailed cases

  Scenario: Trivia archival respects the 2-week grace buffer, not just "week ended"
    Given a trivia question in the ISO week that ended exactly 8 days ago (1 full week elapsed)
    When the archival step runs
    Then that question is NOT yet archived (only 1 elapsed week, threshold is 2)

  Scenario: Trivia archival is idempotent across repeated runs
    Given a trivia question already archived from a prior run
    When the archival step runs again
    Then no error occurs and the row's status stays 'archived' (not touched twice)

  Scenario: Rumor archival is idempotent across repeated runs
    Given a prior run already archived down to exactly RUMOR_MIN_LIVE published items
    When the archival step runs again with no newly-stale items since
    Then rumorArchiveBudget() returns 0 and no further items are archived

  Scenario: An archived item's CP ledger history is provably unchanged
    Given a participant has an existing challenge_points row (source_ref="rumor_or_not:<item_id>")
      for an item that later becomes archival-eligible
    When the archival step archives that item
    Then the challenge_points row still exists with the same points and source_ref
    And that participant's total CP (getChallengeCpTotal()) is unchanged before and after

  Scenario: Rumor archival never considers draft or already-archived rows as "live" for the floor count
    Given 3 published in-window rumor items, 2 draft items, and 4 already-archived items
    When computing whether archiving more would breach RUMOR_MIN_LIVE
    Then only the 3 published in-window items count toward the floor

  Scenario: Answered-but-not-yet-archived items still score correctly
    Given a participant answers a rumor item the day before it becomes archival-eligible
    Then their CP award and challenge_answers row are recorded normally, unaffected by the
      item's later archival

  Scenario: Perfect Week display stays correct across an archival run (regression guard for the
  2026-07-20 denominator bug)
    Given 5 participants answered all of last week's trivia questions correctly
    And this week's archival step (2-week-elapsed rule) does NOT touch last week's questions yet
    When challenges.php renders the weekly trivia denominator
    Then it still shows the real published count for that week, never a mismatched "5 of fewer than 5"

  Scenario: Admin bulk-archive on the Rumors tab
    Given an admin selects 4 published rumor items via the multiselect
    When they click "Archive selected"
    Then all 4 become 'archived' in one request, same UX as existing bulk delete

  Scenario: Rewritten e2e — Publish makes the item playable, tolerant of coexisting real content
    Given real published rumor items exist in the test DB dated earlier than today
    And an admin publishes a new test-seeded rumor item via the drafts screen
    When the test looks for that specific item's text among rendered rumor cards for a fresh
      participant who hasn't answered it
    Then it is found, regardless of how many other real items also exist

  Scenario: Rewritten e2e — rumor import without a status stays a draft, tolerant of coexisting
  real content
    Given real published rumor items already render as cards on the public page
    When a rumor item is imported with no explicit status
    Then that specific imported item's text never appears among rendered rumor-cards
    And the test does not require the total rumor-card count to be zero
```
