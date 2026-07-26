# Epic: E2E Test Suite Restructuring for Progress Visibility

*Note: this epic's "user" is the developer/QA engineer (Djarnis) running the Paddock Picks E2E suite, not an end betting user. It's an internal tooling epic.*

Plan: `plan.md` · Test plan: `test-plan.md`
Reviewed by the `web-architecture-review` and `test-manager` skills — see `plan.md` for the full MUST FIX / SHOULD FIX list folded in from both passes.

## User Value

Right now the E2E suite runs as one undifferentiated block: no visibility into what's running, how far along it is, or which functional area a failure belongs to. That makes it slow to diagnose failures and discourages running tests during day-to-day development, since a run is an opaque black box until it finishes (or hangs).

Restructuring the suite into UX-oriented groups and surfacing live progress means:
- Failures are immediately traceable to a feature area (predictions, scoring, leaderboard, admin, auth) instead of a wall of test names.
- A single suite can be re-run in isolation after a fix, instead of re-running everything.
- Progress feedback (percent complete, test X of Y) makes long runs bearable and makes it obvious whether a run has stalled.

## User Experience

- The full E2E run is composed of discrete, named suites grouped by user-facing area of the app (e.g. Podium Predictions, Auto-Scoring, Leaderboard, Race/Admin Management, Authentication, Mobile Responsiveness).
- Each suite can be invoked on its own via `npm run test:e2e:<suite>(:<environment>)` (e.g. `npm run test:e2e:predictions` or `npm run test:e2e:predictions:live`) and produces its own pass/fail result, independent of the other suites.
- Running the full suite executes each group sequentially, one after another, rather than all tests interleaved with no structure.
- While a run is in progress (whether a single suite or the full run), the developer sees live progress: which test is currently running, "test X of Y", and a percentage complete for the current suite and/or the overall run.
- Suite boundaries reflect what a user would recognize as a feature of the app, not internal code structure (e.g. not grouped by file or class name, but by "what part of Paddock Picks does this test").

## Suite Taxonomy (verified against source — 175 tests, 21 spec files)

11 primary suites partition all 175 tests with **zero overlap and zero file moves** (tagged via
Playwright native `{ tag: '@slug' }`), plus one cross-cutting suite that reuses 3 already-counted
tests and is excluded from the full run (see Resolved Contradictions below).

| # | Suite (npm slug) | Source file(s) | Tests | `:live`? |
|---|---|---|---|---|
| 1 | Smoke & Platform Health (`smoke`) | `01-smoke`, `15-env-banner` | 21 | **Yes** |
| 2 | Authentication (`auth`) | `02-auth`, `auth/30,31,32,35,36` | 49 | No |
| 3 | Invites & Registration (`registration`) | `03-registration`, `admin/11-invites` | 6 | No |
| 4 | Podium Predictions (`predictions`) | `04-betting` | 5 | No |
| 5 | Auto-Scoring & Leaderboard (`scoring`) | `admin/13-scoring` | 12 | No |
| 6 | Race Page & Results Display (`race-page`) | `14-race-page` | 16 | No |
| 7 | Race & Content Admin Management (`admin`) | `admin/10-content`, `admin/12-users`, `admin/12-email-delivery`, `06-emails` | 15 | No |
| 8 | Profile & Stats (`profile`) | `05-profile` | 17 | No |
| 9 | Theme & Appearance Persistence (`appearance`) | `08-preferences` | 10 | No |
| 10 | Preferences Editor (`preferences-editor`) | `09-profile-preferences` | 15 | No |
| 11 | Notifications & Cron Jobs (`cron`) | `07-cron` | 9 | No |
| — | Mobile Responsiveness (`mobile`, standalone only, secondary tag) | 3 inline viewport tests, already inside their home suites | 3 (reused) | No |

Sum of primary suites: 21+49+6+5+12+16+15+17+10+15+9 = **175.** ✓

**Suites 8/9/10 look similar but are distinct** — `profile` = identity + account (stats hero card,
role/competing chips, password change); `appearance` = the site *remembering* your theme/font/language
choice across anonymous↔logged-in sessions; `preferences-editor` = the on-page controls that change
those choices. A failure in one should never send you looking in the other two.

## Resolved Contradictions

1. **Mobile Responsiveness is a standalone-only cross-cutting suite, not a 12th full-run leg.** Its 3
   tests are inline `320px` viewport checks already counted inside `race-page`, `scoring`, and `auth`.
   Running it as a peer suite in the full run would double-execute them, violating the no-regression-
   in-count metric below. Run via `npm run test:e2e:mobile` on its own; excluded from the sequential
   full run.
2. **Leaderboard is not an independent suite.** No independent leaderboard seed path exists —
   leaderboard correctness (points, star badge, rank) is a view over scoring state and is asserted
   inside `admin/13-scoring`. It is merged into **Auto-Scoring & Leaderboard**, not a separate suite.
3. **Every `:live` example besides `smoke` is unsafe and must not be built.** Every other suite mutates
   data (bets, users, races, passwords); test-strategy principle #4 forbids mutating Live. The only
   safe standalone-live command is `npm run test:e2e:smoke:live`.

## Success Metrics

- Time to isolate a failing area drops: a failure points to one suite instead of requiring a scan of full output.
- Individual suites are re-run standalone during development (adoption signal — Djarnis actually uses `npm run test:e2e:<suite>(:<environment>)` commands instead of always running the full set).
- Full E2E run reports live progress (X of Y, percent) for every run, with zero silent/opaque stretches.
- **No regression in total test count** (175, re-verified from source at implementation time) as a
  result of the restructuring — grouping is organizational, not a reduction in coverage.
- **No regression in total run time beyond the low-double-digit-second per-suite process-bootstrap
  cost inherent to suite isolation** (~15–25s on today's 175-test, 5–10 min baseline). Splitting one
  process into 11 sequential legs has an irreducible Node/Playwright bootstrap floor (~1–2s × 11 legs);
  everything above that floor (session re-login, inbox/intercept toggling per leg) is eliminated by
  the mitigations in `plan.md` (MUST-5, MUST-6), not accepted as regression. See `plan.md` §A5 for the
  full quantification.

## Acceptance Criteria

```gherkin
Feature: E2E Test Suite Restructuring for Progress Visibility

  Scenario: Full E2E run executes grouped suites sequentially
    Given the E2E test suite has been restructured into UX-based groups
    When the developer runs the full E2E command
    Then each suite executes one after another, not interleaved
    And the run reports which suite is currently executing

  Scenario: Individual suite can be run in isolation
    Given the E2E suite is grouped into named suites (e.g. Predictions, Scoring, Leaderboard, Admin, Auth)
    When the developer runs a single suite via "npm run test:e2e:<suite>"
    Then only that suite's tests execute
    And a pass/fail result is reported for that suite alone

  Scenario: Individual suite can target a specific environment
    Given a named suite (e.g. Predictions)
    When the developer runs "npm run test:e2e:<suite>:<environment>" (e.g. test:e2e:predictions:live)
    Then only that suite's tests execute against the specified environment
    And the environment used is reported alongside the result

  Scenario: Live progress is visible during a run
    Given an E2E run (full or single-suite) is in progress
    When a test starts or completes
    Then the developer sees "test X of Y" for the current suite
    And the developer sees a percent-complete indicator

  Scenario: Suite grouping reflects app features, not code structure
    Given the restructured suite groupings
    When the developer reviews the suite names
    Then each suite name corresponds to a recognizable Paddock Picks feature area
    And no suite is defined purely by file/folder structure unrelated to app functionality

  Scenario: Full run reports overall progress across suites
    Given the full E2E run is executing multiple suites
    When a suite completes and the next begins
    Then the developer sees overall progress (e.g. suite 2 of 6, and cumulative percent across all tests)
```
