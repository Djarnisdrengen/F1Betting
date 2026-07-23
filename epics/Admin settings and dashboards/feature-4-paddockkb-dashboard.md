# Feature 4: Dashboards — PaddockKB

Read-only status view over the PaddockKB knowledge base (the content-gen pipeline behind Rumor or Not and
Trivia — see `docs/paddock-rumors-reference.md`). No write actions in this feature besides triggering an
existing ingest job to run early.

## Requirements

### Functional Requirements
- [REQ-401] Two status cards: "Sidste opdatering" (last update — relative time, status, duration) and
  "Næste planlagte" (next scheduled run — countdown + human schedule string). **Revised during
  implementation:** the handoff's "entries added / source count" per run isn't available from the GitHub
  Actions run API without parsing job step output text — already out of scope per the GitHub Actions
  sub-epic's own decision 7 (raw log text deferred). Dropped; relative time/status/duration are all real
  `ghListWorkflowRuns()` fields, same source the GitHub Actions tab itself uses.
- [REQ-402] A primary "Kør opdatering nu" button enqueues the same ingest job the nightly cron already runs
  — it does not implement a second, parallel ingest path.
- [REQ-403] KPI row: total entries, category count, index size. **Revised during implementation:** the
  handoff's fourth KPI ("queries in the last 7 days") assumed query-log data that doesn't exist —
  `public/paddock-rumors/query.php` (the only query entry point) calls an external Vercel API and logs
  nothing locally, and it's admin-only (manual testing), not end-user traffic, so even adding local logging
  there wouldn't produce a meaningful usage metric. Dropped from the committed KPI grid; folds into the
  already-deferred "Query-brug & svar-kvalitet" panel (D6) if real query telemetry is ever added upstream.
- [REQ-404] "Indhold pr. kategori": one row per KB category with a red progress bar (share of total), a mono
  count, and a freshness dot (green/orange/red by staleness).
- [REQ-405] "Seneste ingest-kørsler": a run log (when, source, entries added, OK/Fejl badge) reflecting real
  run outcomes, including failures — not filtered to successes only.
- [REQ-406] This dashboard performs **no writes** to KB content itself — only reads ingest/run metadata and,
  via REQ-402, enqueues the existing job.

### Non-Functional Requirements
- [NFR-401] "Kør opdatering nu" must not be triggerable more than once concurrently (a second click while a
  run is in progress should reflect a running/queued state, not start a duplicate run) — the content-gen
  pipeline already has its own concurrency guards (per-env job-level `concurrency` groups on the underlying
  workflow, per `docs/github-actions.md`'s Content Top-up section); this button must respect them, not
  bypass them.
- [NFR-402] Reads are cheap — this dashboard must not run its own expensive KB scan on every page load; it
  reads metadata/run-history already maintained by the ingest job.

## User Story

**As an** admin
**I want to** see PaddockKB's ingest health and trigger an update on demand
**So that** I notice a failed nightly ingest before it causes stale rumors/trivia, and can force a refresh
ahead of schedule if needed

## Functionality

### User Flow
1. Admin opens Dashboards → PaddockKB.
2. Sees last-update summary and next-scheduled countdown at a glance.
3. If content feels stale or a run is known to have failed, clicks "Kør opdatering nu."
4. Sees the run reflected in "Seneste ingest-kørsler" once it completes (success or failure).
5. Uses the per-category freshness view to spot which content type (Rygter/Trivia/Kørere/Løb/Historik) is
   going stale.

### Detailed Specifications
Category list, freshness thresholds, and copy match the handoff's exact fields (README §"PaddockKB"). The
"Query-brug & svar-kvalitet" panel (top queries + source-hit coverage %) is marked as an "Idé" in the
handoff — per epic decision D6, ship as **deferred/nice-to-have**, not committed v1 scope, since it requires
query logging that may not currently exist for this pipeline.

### Technical Implementation
- Reads: KB metadata (entry/category counts, index size), the ingest job's own run-history log, and — if
  ready to commit to it — query logs for the deferred panel.
- "Kør opdatering nu" enqueues the same job the nightly cron triggers (see `docs/github-actions.md`'s
  Content Top-up workflow) — exact enqueue mechanism (direct workflow_dispatch call vs. an internal queue)
  is an architecture-review decision.

## Test Scenarios

```gherkin
Feature: PaddockKB dashboard

  Scenario: Last-run failure is visible, not hidden
    Given the most recent ingest run failed
    When the admin opens PaddockKB
    Then the run log shows that run with a "Fejl" badge, not omitted

  Scenario: Manual trigger enqueues the real job
    Given the admin clicks "Kør opdatering nu"
    Then the same ingest job the nightly cron uses is triggered
    And a new row appears in the run log once it completes

  Scenario: Missing workflow-trigger permission fails clearly
    Given GITHUB_TOKEN has only read-only scope, not the write scope this button needs
    When the admin clicks the manual-trigger button
    Then a clear "insufficient permissions - token needs write access" message is shown, not a generic or
      silent failure

  Scenario: Category freshness reflects real staleness
    Given the "Historik" category hasn't been updated in over 90 days
    When the admin views "Indhold pr. kategori"
    Then its freshness dot is red
```

## Test Cases

```gherkin
Feature: PaddockKB dashboard

  Scenario: Concurrent trigger is blocked
    Given an ingest run is already in progress
    When the admin clicks "Kør opdatering nu" again
    Then no second run starts, and the UI reflects the in-progress state

  Scenario: KPI accuracy
    Given the KB has 3284 entries across 5 categories
    Then the KPI row shows "3.284" entries and "5" categories, matching the underlying data exactly

  Scenario: Run log shows both outcomes
    Given the last 4 runs include 3 successes and 1 failure
    Then all 4 appear in "Seneste ingest-kørsler" with correct OK/Fejl badges, newest first

  Scenario: Read-only guarantee
    Given the admin is on PaddockKB
    When they interact with any element other than "Kør opdatering nu"
    Then no write/enqueue request is issued

  Scenario: Non-admin access rejected
    Given a non-admin logged-in user requests this dashboard directly
    Then they are rejected the same way as any other admin page
