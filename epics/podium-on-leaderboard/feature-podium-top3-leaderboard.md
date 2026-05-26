# Feature: Podium-Style Top 3 Leaderboard Display

## Requirements

### Functional Requirements
- **[REQ-001]** The leaderboard view displays the top 3 ranked players as a visual podium positioned at the top of the leaderboard page.
- **[REQ-002]** The podium follows the classic Olympic layout: 1st place in the center (tallest block), 2nd place on the left (medium block), 3rd place on the right (shortest block).
- **[REQ-003]** Each podium block displays: rank number, player display name, total season points, and a medal indicator (gold/silver/bronze).
- **[REQ-004]** Players ranked 4th and below appear in the existing ranked list directly below the podium, with no styling change to those rows.
- **[REQ-005]** The podium uses the same ordering returned by the existing leaderboard API — no client-side re-sorting or tie-resolution logic.
- **[REQ-006]** Tapping a podium block (and any list row below) navigates to that player's prediction history for the season, matching existing leaderboard row behavior.
- **[REQ-007]** On viewports narrower than 400px, podium player names truncate to first name only; full names remain available in the player detail view.

### Non-Functional Requirements
- **[NFR-001]** No additional API calls — the podium consumes the existing `/api/leaderboard` response (pure presentational change).
- **[NFR-002]** Initial render of the leaderboard page including the podium completes within 1 second on a 4G connection.
- **[NFR-003]** Layout renders correctly without horizontal scroll on viewports from 320px (small Android) to 1920px (desktop).
- **[NFR-004]** All transitions and decorative effects use CSS only — no JS animation libraries — to respect the no-build architecture.

### Technical Constraints
- Must work on simply.com shared hosting (PHP 8, MySQL, no Node.js).
- No build step — new Preact + htm component plus plain CSS file.
- Mobile-first responsive design.
- Touch targets minimum 44px for tappable podium blocks.
- Reuses existing `/api/leaderboard` endpoint — no schema changes.
- Assumes minimum 5 players exist on the leaderboard (no empty-state or partial-podium handling in this feature).

## User Story

### Primary User Goal
A player opening the leaderboard wants to see at a glance who is winning the friend group, with a visual that makes the top 3 feel celebrated — not just another row in a table.

### User Story Format
**As a** friend competing in Paddock Picks
**I want to** see the top 3 players displayed as a celebratory podium at the top of the leaderboard
**So that** I can instantly recognize who's leading the championship and feel motivated to climb onto the podium myself

### User Personas
- **Active predictor**: Opens leaderboard after every race; gets instant gratification from seeing their name on the podium, or motivation to overtake the leader.
- **Casual participant**: Checks the leaderboard occasionally; the podium gives an instant read of current standings without scanning a list.
- **Admin user**: Same benefit as players — a quick visual check of standings before announcing race winners in the group chat.

## Functionality

### User Flow
1. User opens the app and navigates to the Leaderboard tab.
2. The existing `/api/leaderboard` request returns season standings in ranked order.
3. The first 3 entries from that response render as a podium with 1st in the center, 2nd on the left, 3rd on the right.
4. Players ranked 4 and beyond render as a standard list below the podium (existing component, unchanged).
5. On mobile, the podium sits above the fold — no scrolling required to see the medal positions.
6. User can tap any podium block or any list row to view that player's predictions.

### Detailed Specifications

**Podium Layout — Mobile (320px–600px)**
- Three blocks drawn with CSS flexbox; visual order `[2nd, 1st, 3rd]`.
- Block widths: equal thirds of the available container width.
- Block heights: 1st = 140px, 2nd = 110px, 3rd = 90px.
- Each block contains (top-to-bottom): celebratory icon (🏆/🥈/🥉), rank number badge in medal color, player name (single line, ellipsis on overflow), points value in bold with "pts" suffix.
- Color tokens: gold `#FFD700`, silver `#C0C0C0`, bronze `#CD7F32`, each with a darker shade for the block base (subtle gradient).

**Podium Layout — Tablet/Desktop (>600px)**
- Same layout; block heights scale up: 1st = 180px, 2nd = 140px, 3rd = 115px.
- Container caps at 720px wide, horizontally centered.

**Rest of Leaderboard (rank 4+)**
- No visual change to existing list rows.
- Rank numbering continues from "4." onward.
- 24px margin and a thin divider line separate the podium from the list.

**Ordering**
- The component takes the first three entries of the API response array as-is and assigns them to gold, silver, and bronze respectively. Any tie-breaking is already encoded in the API response order — the component does not inspect or modify it.

### Scoring Logic
No new scoring logic — purely presentational. The component consumes existing `total_points` and ranking from the leaderboard API.

### Mobile Considerations
- Total podium height (1st block 140px + icon/badge ~30px + buffer 40px) fits above the fold on a 667px-tall iPhone SE.
- Touch targets: each podium block is ≥44px in both dimensions on all supported viewports (minimum 320px ÷ 3 = ~106px wide).
- Player names truncate with ellipsis on a single line; first-name-only fallback on <400px screens.
- Points text minimum 16px for readability.
- Zero horizontal scroll at any viewport ≥320px.

### Technical Implementation

**Frontend**
- New Preact + htm component: `frontend/components/LeaderboardPodium.js`.
- `Leaderboard.js` modified to slice standings: first 3 → `<LeaderboardPodium players={top3} />`, rest → existing list rendering.
- New stylesheet: `frontend/css/podium.css`, linked from the leaderboard page. Uses CSS custom properties for medal colors so themes can override.
- No new dependencies.

**Backend**
- No backend changes. `/api/leaderboard` continues to return the ranked list as today.

**Data Contract (existing)**
- `GET /api/leaderboard` → `[{ rank, user_id, display_name, total_points }, ...]`

## Test Scenarios

```gherkin
Feature: Podium-Style Top 3 Leaderboard Display

  Scenario: Happy path — viewing leaderboard mid-season on mobile
    Given a season is in progress with at least 5 players who have scored points
    And the user opens the leaderboard on a mobile device
    When the leaderboard page loads
    Then the first 3 players from the API response are displayed as a podium with 1st in the center
    And players ranked 4th and below appear in a list below the podium
    And no horizontal scroll occurs on a 375px viewport

  Scenario: Mobile interaction — tapping a podium block
    Given the user is viewing the leaderboard on a mobile device
    When the user taps the 1st place podium block
    Then the user navigates to that player's prediction history
    And the touch target was at least 44px in both dimensions

  Scenario: Responsive scaling across devices
    Given the leaderboard is displayed
    When the viewport changes from 320px to 1920px
    Then the podium remains centered within its container
    And block heights scale proportionally without overflowing
```

## Test Cases

```gherkin
Feature: Podium-Style Top 3 Leaderboard Display

  Scenario: Full podium renders from API response
    Given the leaderboard API returns 5 players in ranked order:
      | rank | name    | points |
      | 1    | Alice   | 50     |
      | 2    | Bob     | 35     |
      | 3    | Charlie | 20     |
      | 4    | Dana    | 15     |
      | 5    | Eve     | 10     |
    When the user opens the leaderboard
    Then the gold (center) block shows "1 — Alice — 50 pts"
    And the silver (left) block shows "2 — Bob — 35 pts"
    And the bronze (right) block shows "3 — Charlie — 20 pts"
    And the list below shows "4. Dana — 15 pts" then "5. Eve — 10 pts"

  Scenario: Mobile name truncation on narrow viewport
    Given the user is on a 360px-wide device
    And the 1st place player's display name is "Alexander Maximilian Smith"
    When the leaderboard renders
    Then the visible podium name shows "Alexander"
    And no horizontal scroll is triggered

  Scenario: Touch target compliance on minimum viewport
    Given the user is on a 320px-wide device
    When the podium renders
    Then each podium block is at least 44px wide and 44px tall in tappable area

  Scenario: No additional API calls
    Given the user opens the leaderboard
    When the page renders the podium and the list
    Then only one network request is made: GET /api/leaderboard
    And no additional endpoints are called for the podium

  Scenario: Podium updates after admin enters race results
    Given the leaderboard is currently displayed with Alice in 1st
    When the admin enters results that move Bob ahead of Alice
    And the user refreshes the leaderboard
    Then the podium re-renders with Bob on gold and Alice on silver
    And the rankings reflect the updated API response order
```
