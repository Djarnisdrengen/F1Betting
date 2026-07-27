# Feature 2: Content Health Dashboard (Product Owner Visibility)

**This is the feature enabler Djarnis explicitly asked for** — the thing that makes the state of
Paddock Challenges content legible to the product owner without querying the database by hand.
Feature 1 makes content rotate correctly; this feature is how the product owner actually sees that
it's working (or isn't).

Depends on Feature 1's `'archived'` status existing to report on, but is independently the point
of this epic from the product owner's side.

## Requirements

### Functional Requirements

- [REQ-701] Extend the existing Dashboards → Challenges tab (`admin-dashboards.php?tab=challenges`,
  `includes/admin-dashboards/challenges.php` + `challenges-usage-lib.php`) with a second panel,
  **Content Supply**, alongside the existing player-usage panel — not a new top-level tab. Same
  page, same read-only convention as every other Dashboards tab.
- [REQ-702] **Live content counts**: currently-servable rumor item count (same predicate
  `nextRumorItem()` uses: `status='published' AND publish_date <= CURDATE()`) and currently-servable
  trivia question count for the current ISO week (same predicate `nextTriviaQuestion()` uses).
  These are the first place an admin can see "how much is actually live right now" without opening
  the flat Rumors/Trivia admin lists and counting rows by eye.
- [REQ-703] **Stale/archived breakdown**: count of `archived` rumor items and trivia questions
  (total, and split by "archived this week" vs. "archived previously" if cheaply derivable from
  existing timestamps — best-effort, not a hard requirement). If Feature 1's rumor archival guard
  blocked a run (`rumor_guard_blocked`), surface that explicitly as a flag: "rumor deck can't rotate
  safely — content may be getting old, consider a manual `generate-rumor-items.js` run."
- [REQ-704] **Batch cadence health, per environment**: last `cron-content-topup.yml` run's
  timestamp and conclusion (success/failure) for test and live separately, sourced from the
  existing GitHub Actions run cache (`ghListWorkflowRunsMulti()`,
  `public/cache/github-actions/*.json` — already populated, see `docs/github-actions.md`). Computed
  "next expected run" from the workflow's own registered cron string (`'0 6 * * 5'` in
  `ghWorkflowConfig()`) with a grace window (e.g. a few hours); if now is past that, flag
  **overdue**.
- [REQ-705] **KB exhaustion runway**: read `bin/state/{rumor,trivia}-generator-state.<env>.json`
  (used-doc-id counts, per environment, already committed to the repo) and
  `paddock-rumors/data/knowledge-base.json` (total available doc count) directly from disk;
  compute and display estimated weeks of unused KB material remaining per generator per
  environment, given the known ~6-items-per-batch draw rate. Flag "running low" below a threshold
  (proposed: 4 weeks).
- [REQ-706] This dashboard performs **no writes** — identical read-only guarantee to the existing
  Challenges usage panel (REQ-503 in the archived Feature 5 doc) and every other Dashboards tab
  except Nøgler & Rotation.
- [REQ-707] `chGetUsageSnapshot()`'s `flagCount` (today hardcoded `0`, "no natural problem state" —
  see `challenges-usage-lib.php`) is updated to include real content-health flags (overdue batch,
  blocked archival, low KB runway) so the Oversigt tab's Challenges tile finally reflects a genuine
  problem state when one exists, same as the other three Dashboards tiles already do.

### Non-Functional Requirements

- [NFR-701] No new GitHub API calls on a normal dashboard page load — reuse the existing cache
  (`ghCached`/`ghListWorkflowRunsMulti`), consistent with why that cache was built in the first
  place (`docs/github-actions.md`).
- [NFR-702] Figures here must never disagree with the equivalent number elsewhere in the admin area
  (e.g. a "live rumor count" here must match what `admin-challenges.php`'s Rumors tab would show
  filtered to Published) — same source-of-truth discipline as NFR-501 on the existing usage panel.
- [NFR-703] Reading the two generator-state JSON files and the KB JSON file must degrade cleanly
  (show "unknown"/omit the runway figure, never a fatal error or a fabricated number) if a file is
  missing or malformed — these are plain files on disk, not guaranteed to exist in every
  environment/checkout. **Testing note (from `/test-strategy-manager` review):** the parsing/runway
  math is implemented as pure functions (`parseGeneratorState()`, `computeKbRunway()`) taking
  already-read strings/arrays, specifically so this requirement is verifiable with in-memory
  fixture strings in a DB-free `tests/unit/*.php` harness — not by mutating a real, shared,
  also-used-by-`bin/generate-*.js` state file on the test environment to simulate corruption.
- [NFR-704] No new schema for this feature itself (it depends on Feature 1's enum change, but adds
  none of its own) — purely derived from existing tables, the existing GH Actions cache, and
  existing on-disk state files.

## User Story

**As a** product owner overseeing an unattended, fully-automated weekly content pipeline
**I want to** see at a glance how much Paddock Challenges content is live, how much has rotated
out, whether this week's batch actually landed, and how many weeks of source material remain
**So that** I catch a stalled pipeline, a KB running dry, or an unhealthy rumor deck before it
becomes a player-facing problem or another late-night manual-SQL diagnosis session

## Functionality

### User Flow

1. Product owner opens `admin-dashboards.php?tab=challenges` (or lands via Oversigt's Challenges
   tile, which now shows a real flag count instead of always 0).
2. Existing player-usage panel is unchanged, above or beside the new **Content Supply** panel.
3. Content Supply panel shows, per game: live count, archived count, and (rumor only) whether the
   archival guard is currently blocked.
4. A **Batch cadence** row shows last content-topup run time + status for test and live, with an
   "overdue" badge if the next expected Friday run hasn't happened within its grace window.
5. A **KB runway** row shows estimated weeks remaining per generator per environment, flagged if
   running low.
6. Product owner can act immediately: if overdue, check GitHub Actions; if KB is low, run
   `bin/generate-*.js` manually or grow the KB; if rumor archival is blocked, decide whether 6 weeks
   is still the right threshold or top up content sooner.

### Detailed Specifications

- Visual treatment matches the existing Dashboards stat-card/section-card primitives
  (`patterns.md` → Admin Layout Primitives) — no new CSS pattern, reuse `.stat-card`,
  `.stat-card-grid`, `.section-card` exactly as the Nøgler & Rotation and PaddockKB tabs already do.
- "Overdue" and "blocked"/"low" states use the same badge/flag visual language already established
  by Nøgler & Rotation's health score and PaddockKB's freshness dots — not a new visual vocabulary.

### Technical Implementation

- `chGetContentHealthSnapshot(PDO $db): array` in `challenges-usage-lib.php`, composed into
  `chGetUsageSnapshot()`'s return array and rendered by a new block in
  `includes/admin-dashboards/challenges.php`.
- Live/archived counts: plain `COUNT(*)` queries mirroring `nextRumorItem()`/`nextTriviaQuestion()`'s
  own `WHERE` predicates — no new indexes needed, `idx_status_date (status, publish_date)` already
  exists on both tables.
- Batch cadence: reuse `ghListWorkflowRunsMulti(['cron-content-topup.yml'], N)` (already imported
  wherever `ghGetHealthSnapshot()` lives, `public/includes/actions-dashboard.php`) — filter/derive
  rather than re-implement the GitHub API call.
- KB runway: `json_decode(file_get_contents(...), true)` against the two per-environment generator
  state files + the KB file, wrapped so a missing/malformed file yields `null`/"unknown", never a
  fatal.

## Test Scenarios

```gherkin
Feature: Content health dashboard

  Scenario: Product owner sees live/archived counts without running SQL
    Given 8 published in-window rumor items and 5 archived rumor items exist
    When the product owner opens Dashboards → Challenges
    Then the Content Supply panel shows "8 live" and "5 archived" for rumors

  Scenario: Overdue batch is flagged
    Given cron-content-topup.yml's last successful run was more than one week + grace period ago
    When the product owner views the Batch cadence row
    Then it shows an "overdue" badge for that environment

  Scenario: KB running low is flagged
    Given the rumor generator's per-environment state shows fewer than 4 weeks of unused docs left
    When the product owner views the KB runway row
    Then it shows a "running low" flag for that generator/environment

  Scenario: Blocked rumor archival is surfaced, not silent
    Given the last archival run set rumor_guard_blocked = true
    When the product owner views the Content Supply panel
    Then it explicitly states the rumor deck could not rotate safely this run
```

## Test Cases

```gherkin
Feature: Content health dashboard — detailed cases

  Scenario: Live rumor count matches admin-challenges.php's own Published filter
    Given the Rumors tab's "Published" filter shows 8 rows
    When the product owner views the Content Supply panel's live rumor count
    Then it also shows 8, never a divergent number (NFR-702)

  Scenario: Dashboard makes zero live GitHub API calls on a normal load
    Given public/cache/github-actions/*.json already has a warm cache entry for cron-content-topup.yml
    When the Content Supply panel renders
    Then no outbound GitHub API request is made (verified via the existing ghCached() TTL path)

  Scenario: Missing generator-state file degrades cleanly (unit-tested with a fixture, not a real deleted file)
    Given parseGeneratorState() is called with null (simulating file_get_contents() returning false)
    Then it returns null rather than throwing

  Scenario: Malformed KB JSON degrades cleanly (unit-tested with a fixture string)
    Given parseGeneratorState() is called with the literal string "{not valid json"
    Then it returns null rather than throwing
    And computeKbRunway() given that null input also returns null, not a fabricated number

  Scenario: On a real environment, a genuinely missing/malformed file still renders a clean dashboard
    Given bin/state/rumor-generator-state.test.json does not exist on this checkout
    When the KB runway row renders on Dashboards → Challenges
    Then it shows "unknown" for that generator/environment
    And the page does not error or 500

  Scenario: Oversigt's Challenges tile reflects a real flag for the first time
    Given the Content Supply panel currently shows an overdue batch
    When the product owner views Dashboards → Oversigt
    Then the Challenges tile's flag count is greater than 0 (previously always hardcoded 0)

  Scenario: Read-only guarantee holds for the new panel
    Given the product owner is viewing the Content Supply panel
    When they interact with any element on it
    Then no write request is issued (REQ-706)

  Scenario: Test and live batch cadence are shown independently, never merged
    Given test's last content-topup run succeeded and live's failed
    When the product owner views the Batch cadence row
    Then test shows healthy and live shows failed/overdue, distinctly labeled per environment
```
