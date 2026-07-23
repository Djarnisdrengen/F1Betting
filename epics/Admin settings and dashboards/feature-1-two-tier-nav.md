# Feature 1: Two-Tier Admin Nav (Core / Paddock Challenges / Dashboards)

## Requirements

### Functional Requirements
- [REQ-101] The admin area exposes exactly three top-level areas — Core, Paddock Challenges, Dashboards —
  styled per the handoff's Level-1 spec (active: red border/text/bg tint; inactive: `--border-color` /
  `--bg-secondary` / `--text-secondary`).
- [REQ-102] Each area shows a row of Level-2 section-tab pills beneath the area row, styled per the handoff
  (pill shape, active red border/text, optional mono count chip).
- [REQ-103] Core's existing tabs (Races, drivers, users, invites, Bets, Security, Settings) render under the
  new chrome with **no change** to their queries, forms, or POST handlers.
- [REQ-104] Paddock Challenges' existing tabs (Members, Rumor or Not, Trivia, Duels, Suppressions) render
  under the new chrome with **no change** to their queries, forms, or POST handlers.
- [REQ-105] The existing GitHub Actions page becomes the fifth Dashboards tab; its content, AJAX endpoint,
  and behavior are unchanged — only its position in the nav moves.
- [REQ-106] Active-tab state is derived server-side from the current route (query params / filename), same
  as today — no client-side routing or SPA behavior.
- [REQ-107] The existing `$challengesPromoCount` badge (pending core-membership requests) on the Paddock
  Challenges area button is preserved in the new chrome.
- [REQ-108] Direct links to today's URLs (`admin.php?tab=races`, `admin-challenges.php?tab=members`,
  `admin-actions.php`) continue to work after the restructure — no admin bookmark breaks.

### Non-Functional Requirements
- [NFR-101] No new design tokens are hardcoded as raw hex where an existing `--*` CSS variable already
  covers it; any token genuinely missing (see design tokens list in `README.md`) is added once to
  `public/assets/css/style.css` under the existing `body.dark`/`body.light`/`body.clubhouse.*` blocks, not
  inlined per-page.
- [NFR-102] No client framework, build step, or the prototype's `.dc.html` runtime is introduced — plain PHP
  includes + the site's existing vanilla JS conventions.
- [NFR-103] Nav markup duplication is not tripled a third time — the shared nav shell is factored so adding a
  future sixth admin page doesn't mean copy-pasting the same block into a fourth file.

## User Story

### Primary User Goal
As the admin managing Paddock Picks, I want the admin pages I already use to keep working exactly as they do
today, just reachable through clearer, scalable navigation, so that adding new ops dashboards doesn't mean
an ever-wider row of top-level buttons.

### User Story Format
**As an** admin
**I want to** navigate Core, Paddock Challenges, and Dashboards through a two-tier area → section nav
**So that** I can find any admin page in at most two clicks even as the number of dashboards grows

### User Personas
- **Primary admin (Djarnis):** uses Core weekly (races/results), Paddock Challenges for moderation, and will
  use Dashboards daily once it exists — needs the switch between areas to be fast and not lose place.
- **Occasional co-admin:** less familiar with where things live — benefits most from the area/section
  structure being self-explanatory (icons + labels, no memorized URLs).

## Functionality

### User Flow
1. Admin lands on any admin page → sees the Level-1 area row (Core / Paddock Challenges / Dashboards) with
   the current area highlighted.
2. Admin clicks a different area → navigates to that area's default (or last-used) section tab.
3. Admin clicks a Level-2 section tab within the current area → the content pane swaps to that section; URL
   reflects it (`?tab=` param, consistent with today's Core/Paddock Challenges convention).
4. Existing Core/Paddock Challenges forms, tables, and POST actions behave identically to production today —
   verified by regression, not re-specified.

### Detailed Specifications
- **Level-1 row:** icons per the handoff — Core `fa-gear`, Paddock Challenges `fa-user-check`, Dashboards
  `fa-gauge-high`. Reuses `admin_area_core` / `admin_area_challenges` lang keys; adds `admin_area_dashboards`.
- **Level-2 row:** reuses the existing `.admin-nav-tab` styling family (already present in `style.css`),
  extended with the pill radius / count-chip treatment from the handoff where it doesn't already match.
- **Routing:** Core and Paddock Challenges keep their current files and `?tab=` params untouched. The
  Dashboards area's own file/route shape (one file with `?tab=` vs. reusing `admin-actions.php`'s filename)
  is an architecture decision, not a product one — left to the `/web-architecture-review` plan (see epic
  decision D4). Whatever shape is chosen, REQ-108's backward-compatible URLs are non-negotiable.
- **Badge:** `$challengesPromoCount` continues to compute the same way (pending Paddock Challenges core-
  membership requests); only its container markup may change to match the new area-button style.

### Mobile Considerations
Admin area is desktop-primary in practice (an admin managing races/secrets rarely does it from a phone), but
must not break on mobile since the rest of the site is mobile-first: Level-1 and Level-2 rows wrap
(`flex-wrap: wrap`) rather than overflow, consistent with the handoff's `flex-wrap:wrap` on both rows.

### Technical Implementation
- No new DB tables or queries — this feature is pure presentation/routing.
- Lang keys: add `admin_area_dashboards` (da: "Dashboards" or "Instrumentbrædter" — confirm with existing
  admin-area naming convention) to `public/lang/admin.php`, checked against existing keys for accidental
  duplicates (per this repo's known lang-array duplicate-key footgun).
- CSS: extend `.admin-area-nav` / `.admin-area-tab` / `.admin-nav-tab` rules already in `style.css` rather
  than introducing a parallel class family.

## Test Scenarios

```gherkin
Feature: Two-tier admin nav

  Scenario: Existing Core behavior is unchanged after the nav restructure
    Given an admin is logged in
    When they open Core → Races and edit a race
    Then the save behaves exactly as it did before this feature shipped

  Scenario: Existing Paddock Challenges behavior is unchanged after the nav restructure
    Given an admin is logged in
    When they open Paddock Challenges → Suppressions and restore a suppressed item
    Then the restore behaves exactly as it did before this feature shipped

  Scenario: GitHub Actions is reachable only via Dashboards
    Given an admin is logged in
    When they open the Dashboards area
    Then GitHub Actions appears as one of its five section tabs
    And its content matches the already-shipped admin-actions.php page

  Scenario: Old bookmarked URLs still work
    Given an admin has a bookmark to admin-challenges.php?tab=members
    When they open it after this feature ships
    Then they land on the same content as before, correctly highlighted in the new nav

  Scenario: admin-actions.php redirects to its new home
    Given an admin has a bookmark to admin-actions.php (with or without an ?ajax=run_jobs&run_id= query string)
    When they open it after this feature ships
    Then they are 302-redirected to admin-dashboards.php?tab=actions with the query string preserved
    And the resulting page's content matches what admin-actions.php used to render directly
```

## Test Cases

```gherkin
Feature: Two-tier admin nav

  Scenario: Level-1 active state
    Given the admin is on any Core tab
    When the area row renders
    Then "Core" has the active style (red border, red text, red-tinted background)
    And "Paddock Challenges" and "Dashboards" show the inactive style

  Scenario: Level-2 active state and count chip
    Given the admin is on Core → Races
    When the section tab row renders
    Then "Races" is styled active
    And any tab with a count (e.g. drivers = 10) shows a mono count chip, colored per active/inactive state

  Scenario: Pending-promotion badge still renders
    Given there are 2 pending core-membership requests
    When the admin views the Level-1 area row
    Then the Paddock Challenges area button shows a badge with "2"

  Scenario: Non-admin access is rejected
    Given a logged-in user without admin rights
    When they request any Dashboards URL directly
    Then they are redirected/rejected the same way they are today for admin.php

  Scenario: Mobile wrap, no horizontal overflow
    Given the viewport is 375px wide
    When the area and section tab rows render
    Then buttons wrap to additional lines instead of causing horizontal scroll on the page body

  Scenario: No regression in Core POST handlers
    Given an admin submits the "Nyt løb" (new race) form under the restructured nav
    When the form posts
    Then the race is created exactly as it is today, with the same redirect-with-message pattern
