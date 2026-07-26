# Feature 2: Dashboards — Oversigt (Overview)

## Requirements

### Functional Requirements
- [REQ-201] Oversigt renders four clickable summary tiles, one per other Dashboards tab: Nøgler & Rotation,
  GitHub Actions, PaddockKB, Paddock Challenges (usage).
- [REQ-202] Each tile shows: icon, title, a headline stat, a unit/context label, a tone-colored note, and an
  optional red count "flag" badge when that dashboard has outstanding issues.
- [REQ-203] Clicking a tile navigates to that dashboard's tab.
- [REQ-204] A full-width "Kræver handling" (needs attention) strip below the tiles lists cross-cutting
  exceptions aggregated from the other three dashboards' own data (not a separate data source) — each row
  has a colored severity dot, description text, and a link to the relevant tab.
- [REQ-205] Oversigt performs **no writes** — it only reads and aggregates data already computed by the other
  dashboards' own logic (health score, failing-workflow count, ingest failure, etc.).
- [REQ-206] If a dashboard's underlying data source is unavailable (e.g. GitHub API down), its tile
  degrades to a visible error/stale state rather than silently showing a stale or blank stat.

### Non-Functional Requirements
- [NFR-201] Oversigt must not introduce a second computation of any figure already computed elsewhere (e.g.
  the health score) — it calls the same functions/queries the owning dashboard uses, so the two views can
  never disagree.
- [NFR-202] Page load stays within the same admin-page performance envelope as Core/Paddock Challenges pages
  today (no new heavy synchronous external calls beyond what the other three dashboards already make, and
  those are already cached per their own features).

## User Story

### Primary User Goal
As the admin, I want one screen that tells me at a glance whether anything across GitHub Actions, PaddockKB,
secrets/tokens, or Paddock Challenges needs my attention, so I don't have to open all four tabs just to check.

### User Story Format
**As an** admin
**I want to** see a single overview of the four operational areas with a combined "needs attention" list
**So that** I can triage problems without visiting every dashboard individually

## Functionality

### User Flow
1. Admin opens Dashboards → lands on Oversigt (the default tab).
2. Sees four tiles at a glance; any tile with a red flag badge draws the eye first.
3. Sees the "Kræver handling" strip below, listing specific issues with direct links.
4. Clicks a tile or a strip link → jumps straight to the relevant dashboard/tab, already scoped to the
   flagged item where applicable (e.g. GitHub Actions opens with the failing workflow selected).

### Detailed Specifications
- Tile stats and flags are **computed by**, not duplicated from, each source dashboard:
  - Nøgler & Rotation tile: stat = health score (0–100, same formula as that dashboard); flag = expired-token
    count + overdue-secret count.
  - GitHub Actions tile: stat = success rate (same window/computation as that dashboard); flag = count of
    workflows whose latest run failed.
  - PaddockKB tile: stat = "Healthy"/"Degraded" status + time to next scheduled run; flag = 1 if last ingest
    run failed, else 0.
  - Paddock Challenges tile: stat = active participant count; flag = 0 in steady state (this dashboard is
    read-only usage analytics, not itself a source of "problems" in the same sense as the other three).
- "Kræver handling" strip rows are generated from the same flag-producing conditions above — no separate
  rules engine, just one row per flagged condition with a link built from that dashboard's own routing.

### Mobile Considerations
Tile grid (2×2) collapses to a single column on narrow viewports; the needs-attention strip's rows stack
their link below the description text rather than side-by-side.

### Technical Implementation
- No new tables. Reads: health-score computation (Feature 3), GitHub Actions' existing run-summary functions
  (`actions-dashboard.php`), PaddockKB's ingest-status read (Feature 4), Challenges' usage aggregates
  (Feature 5).
- Because Oversigt depends on all four other dashboards' read functions existing, it is built **last** in
  the phased implementation order, after Features 3–5 (see architecture-review plan).

## Test Scenarios

```gherkin
Feature: Dashboards Oversigt

  Scenario: Healthy state shows no flags
    Given no token/secret is expired or overdue, no workflow is failing, and PaddockKB's last run succeeded
    When the admin opens Oversigt
    Then all four tiles show a zero/blank flag and a positive-tone note
    And the "Kræver handling" strip is empty or shows a single "alt er sundt" state

  Scenario: A failing workflow surfaces on Oversigt and deep-links correctly
    Given a GitHub Actions workflow's latest run failed
    When the admin opens Oversigt
    Then the GitHub Actions tile shows a flag count ≥ 1
    And the needs-attention strip includes that failure with a link
    And clicking the link opens Dashboards → GitHub Actions with that workflow pre-selected

  Scenario: A source dashboard's data is unavailable
    Given the GitHub API is unreachable
    When the admin opens Oversigt
    Then the GitHub Actions tile shows a visible degraded/error state, not a blank or stale-looking stat

  Scenario: A tile whose dashboard isn't built yet degrades gracefully, not fatally
    Given Oversigt is reachable (all 5 Dashboards tabs exist per the phased build order) but the Nøgler &
      Rotation snapshot function doesn't exist yet at this point in the rollout
    When the admin opens Oversigt during that window
    Then that tile renders a "coming soon" state
    And the page does not fatal-error or show a broken/undefined value
```

## Test Cases

```gherkin
Feature: Dashboards Oversigt

  Scenario: Health-score tile matches Nøgler & Rotation exactly
    Given Nøgler & Rotation computes a health score of 72 for the current environment
    When Oversigt renders its tile
    Then the tile's stat reads 72 — the same value, not independently recomputed

  Scenario: Tile click navigation
    Given the admin is on Oversigt
    When they click the PaddockKB tile
    Then they land on Dashboards → PaddockKB

  Scenario: Multiple simultaneous flags aggregate correctly
    Given 2 tokens are expired, 1 secret is overdue, and 1 workflow is failing
    When Oversigt renders
    Then the needs-attention strip shows all 4 issues as separate rows, each with a correct link target

  Scenario: Read-only guarantee
    Given the admin is on Oversigt
    When they interact with any element on the page
    Then no POST/write request is issued anywhere in the page's interactions

  Scenario: Non-admin access rejected
    Given a non-admin logged-in user requests the Oversigt URL directly
    Then they are rejected the same way as any other admin page
