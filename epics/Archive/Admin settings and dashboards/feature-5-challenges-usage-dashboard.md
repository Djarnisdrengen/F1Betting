# Feature 5: Dashboards — Challenges (Usage Analytics)

Read-only usage analytics for the three Paddock Challenges games (Duels, Rumor or Not, Weekly Trivia).
Distinct from the existing Paddock Challenges **moderation** area (Members/Rumor or Not/Trivia/Duels/
Suppressions, Feature 1's reparent) — this dashboard is aggregate stats, not content management.

## Requirements

### Functional Requirements
- [REQ-501] KPI row: active participants, plays in the last 7 days, new membership/participation
  applications, participation rate. **Revised during implementation:** no existing "active participants"
  figure exists anywhere in the admin area to reuse — the Members tab has only ever shown the pending
  promotion-request queue, never a total count. Defined here for the first time: verified participants with
  ≥1 row in `challenge_points` (ever earned a Challenge Point). Participation rate = that count ÷ total
  verified participants.
- [REQ-502] "Konkurrencer": one card per game (Duels, Rumor or Not, Weekly Trivia) with participants, plays
  (7d), a per-game metric, and an 8-bar weekly sparkline. **Revised during implementation:** a uniform
  "completion %" doesn't map onto real schema for all three games — Rumor or Not / Trivia answers are scored
  the instant they're submitted (no unresolved state to measure completion against; it would be trivially
  100%), while Duels genuinely have an unresolved→resolved lifecycle (scoring waits for the race result). Each
  card shows the metric that's real for that game instead: **Duels = resolved rate** (resolved ÷ non-void
  duels), **Rumor or Not / Trivia = correct-answer rate**. Labeled per-card, not forced into one misleading
  shared label.
- [REQ-503] This dashboard performs **no writes** — every figure is a read aggregate over existing Paddock
  Challenges data (participants, plays, completions).

### Non-Functional Requirements
- [NFR-501] Aggregation queries must not double-count or diverge from whatever numbers already appear
  elsewhere in the admin area (e.g. the Members tab's own participant count) — same source of truth, just a
  different view.
- [NFR-502] No new tables — this is entirely derived from existing Paddock Challenges schema.

## User Story

**As an** admin
**I want to** see how much Duels/Rumor or Not/Trivia are actually being played, and how visitors convert
toward membership
**So that** I know whether the Challenges feature is working as an engagement/growth funnel without having to
manually query the database

## Functionality

### User Flow
1. Admin opens Dashboards → Challenges.
2. Sees the top-line KPIs (active participants, 7-day plays, applications, participation rate).
3. Scans the three game cards to see which game is most/least played and its completion rate.
4. Reviews the funnel panel (visitor → participated → registered → requested membership) to gauge growth.

### Detailed Specifications
Fields and copy match the handoff exactly (README §"Challenges (usage)"). The visitor→member funnel panel
is marked "Idé · funnel" in the handoff — per epic decision D6, ship as **deferred/nice-to-have** if the
underlying visitor-count data (top-of-funnel, pre-participation) isn't already tracked; the
participated→registered→requested-membership portion likely **is** derivable from existing Paddock Challenges
tables and can ship in v1 even if the very top "visitors" step can't be sourced yet.

### Technical Implementation
- Read-only aggregate queries over the existing Paddock Challenges participant/play/completion tables — no
  new schema. Exact query shape (and whether "visitors" is available at all) is an architecture-review
  question, not a product one.

## Test Scenarios

```gherkin
Feature: Challenges usage dashboard

  Scenario: KPIs match the underlying data
    Given 248 active participants and 1906 plays in the last 7 days
    When the admin opens Dashboards → Challenges
    Then the KPI row shows exactly those figures

  Scenario: Funnel degrades cleanly if top-of-funnel data isn't available
    Given "visitor" (pre-participation) counts aren't sourced from any existing table (see plan.md Deferred)
    When the admin views the funnel panel
    Then it either omits the visitor step entirely or clearly marks it as unavailable
    And it never shows a fabricated or silently-zeroed value presented as real data

  Scenario: Per-game stats are independent
    Given Duels has 142 participants and Rumor or Not has 98
    When the admin views the Konkurrencer cards
    Then each card shows its own game's figures, not a merged total
```

## Test Cases

```gherkin
Feature: Challenges usage dashboard

  Scenario: Completion percentage calculation
    Given Weekly Trivia had 490 plays and 397 fully completed in the window
    Then its completion badge shows 81% (397/490, rounded)

  Scenario: Sparkline reflects real daily play counts
    Given a game had plays of [10,14,12,20,18,26,22,30] over the last 8 periods
    Then the sparkline bars render in that order with proportional heights

  Scenario: Numbers agree with the Members tab
    Given the Members tab under Paddock Challenges shows 248 active participants
    When the admin opens the Challenges usage dashboard
    Then its "Aktive deltagere" KPI also shows 248, not a different number

  Scenario: Read-only guarantee
    Given the admin is on the Challenges usage dashboard
    When they interact with any element on the page
    Then no write request is issued

  Scenario: Non-admin access rejected
    Given a non-admin logged-in user requests this dashboard directly
    Then they are rejected the same way as any other admin page
