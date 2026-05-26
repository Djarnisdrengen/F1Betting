# Feature: Profile Stats Redesign — Points Hero + Status Chips (v2.2.0)

## Requirements

### Functional Requirements
- **[REQ-001]** The profile stats section is replaced by a **hero card** showing the user's season points as a large tabular number with a "pts" suffix, gold stars inline, and a "Season" / "Sæson" eyebrow label right-aligned.
- **[REQ-002]** Below the hero card, two **status chips** sit side-by-side in a 50/50 grid: a Role chip and a Competing chip. Each chip shows a coloured status dot, a small uppercase key label, and a value.
- **[REQ-003]** The Role chip dot is **red** (`--f1-red-light`) when `role = 'admin'`; **grey** (`--text-secondary`) when `role = 'user'`.
- **[REQ-004]** The Competing chip dot is **green** (`--status-success-light`) when `in_competition = 1`; **muted grey** when `in_competition = 0`. The value shows the "yes"/"no" translation.
- **[REQ-005]** Stars render as repeated `★` glyphs in gold at full strength when `stars > 0`; when `stars = 0` they render as `★ 0` in gold at 85% opacity, indicating "no stars yet" without losing the gold identity.
- **[REQ-006]** The layout never reverts to the old 4-equal-cell grid at any viewport — hero + 2 chips is the only supported shape from 320px to 1920px.
- **[REQ-007]** Both Danish and English are supported via the existing `t()` helper. Six i18n keys are consumed: `season`, `role`, `competing`, `yes`, `no`, `your_stats`.

### Non-Functional Requirements
- **[NFR-001]** No new CSS design tokens. All selectors consume existing tokens: `--bg-card`, `--border-color`, `--text-primary`, `--text-muted`, `--text-secondary`, `--gold`, `--f1-red-light`, `--font-display`.
- **[NFR-002]** No JavaScript — pure PHP markup and CSS.
- **[NFR-003]** No horizontal scroll at 320px viewport. Both chip tap targets are ≥ 44px tall.
- **[NFR-004]** The hero number uses `font-variant-numeric: tabular-nums` so adjacent text does not shift when the points value changes.
- **[NFR-005]** Both dark and light themes, and both font stacks (system and editorial), render correctly without additional overrides.

### Technical Constraints
- Procedural PHP, no framework, no build step.
- New markup lives in `public/partials/profile_stats.php`, included from `public/profile.php`.
- DB `role` column is `ENUM('user', 'admin')` — CSS targets `.role-user` (not `.role-player`).
- `in_competition` is a real DB column (`TINYINT(1)`), fetched by `getCurrentUser()` — no derivation needed.

---

## User Story

### Primary User Goal
A player opening their profile wants to see at a glance how they are performing this season and understand their status in the competition — without squinting at wrapped labels or parsing four equal boxes that make identity strings look like KPIs.

### User Story Format
**As a** Paddock Picks player  
**I want to** see my season points and stars as a prominent hero, and my role and competition status as clearly labelled chips below  
**So that** I can instantly understand both my performance (how many points/stars I've earned) and my status (am I even in the running this season?)

### User Personas
- **Active predictor**: After every race, checks their points total. The big hero number makes the update obvious at a glance.
- **New player**: Zero points, zero stars. The chips below immediately explain why: "Player · No" — they're either not yet in competition or waiting for their first race.
- **Admin user**: Sees at a glance that they are admin and not in competition, reinforcing that their zero points are expected behaviour.

---

## Functionality

### User Flow
1. User logs in and navigates to `/profile.php`.
2. The profile head (avatar, name, email) renders as before — unchanged.
3. Below the head, the stats section renders: a hero card (points + stars + season label) followed by a row of two chips (role + competing).
4. User reads their points total and star count in the hero; reads role and competition status in the chips.
5. The rest of the profile page (tabs, bet history) is unchanged.

### Detailed Specifications

**Hero Card**
- Full-width card with `border-radius: 12px`, `background: var(--bg-card)`, `border: 1px solid var(--border-color)`.
- Horizontal flex layout: `[big number + pts] [stars] [season eyebrow right-aligned]`.
- Points: `font-size: 38px` (XS), `44px` (MD+); `font-weight: 900`; `font-variant-numeric: tabular-nums`.
- Stars: always gold (`var(--gold)`). Empty state `★ 0` at `14px / 0.85 opacity`. Earned state `★★★...` at `18px` (XS) / `22px` (MD+) full opacity.
- Season eyebrow: `10px` uppercase, `margin-left: auto` to push it right.
- Padding: `14px 16px` (XS), `18px 20px` (MD+).

**Chip Row**
- `display: grid; grid-template-columns: 1fr 1fr; gap: 8px`.
- Each chip: transparent background, `border: 1px solid var(--border-color)`, `border-radius: 8px`, `min-height: 44px` (XS) / `52px` (MD+).
- Left: 6px coloured dot (state indicator). Right: key label (10px uppercase, muted) stacked above value (13–14px, bold, primary).

**Breakpoints**
- XS (320px+): hero 38px number, chips 44px tall.
- MD (768px+): hero 44px number, chips 52px tall. Same layout shape — no reflow.

---

## Test Scenarios

```gherkin
Feature: Profile Stats Redesign — Hero Card + Status Chips

  Scenario: Regular player sees points hero with out-of-competition chip
    Given I am logged in as a regular player with 0 points and 0 stars
    And I am not in competition (in_competition = 0)
    When I open /profile.php
    Then I see a hero card showing "0 pts" and "★ 0" (dimmed)
    And I see a role chip with a grey dot labelled "ROLE" / "User"
    And I see a competing chip with a grey dot labelled "COMPETING" / "No"
    And I do not see four equal stat cells

  Scenario: Admin user sees zero-state hero with admin chip
    Given I am logged in as admin with 0 points and 0 stars
    And I am not in competition (in_competition = 0)
    When I open /profile.php
    Then I see a hero card showing "0 pts" and "★ 0" (dimmed)
    And I see a role chip with a red dot labelled "ROLE" / "Admin"
    And I see a competing chip with a grey dot labelled "COMPETING" / "No"

  Scenario: No horizontal scroll at 320px
    Given I am logged in and on /profile.php
    When I view the stats section at 320px viewport width
    Then the stats section has no horizontal overflow
    And both chips are fully visible and single-line

  Scenario: Layout unchanged at tablet width
    Given I am logged in and on /profile.php
    When I view the page at 768px viewport width
    Then the layout is still one hero card + two chips
    And the old four-cell grid is not present

  Scenario: Danish labels render correctly
    Given my language preference is Danish (DA)
    When I open /profile.php
    Then the hero eyebrow shows "SÆSON"
    And the role chip label shows "ROLLE"
    And the competing chip label shows "KONKURRENCE"

  Scenario: English labels render correctly
    Given my language preference is English (EN)
    When I open /profile.php
    Then the hero eyebrow shows "SEASON"
    And the role chip label shows "ROLE"
    And the competing chip label shows "COMPETING"
```

---

## Test Cases

```gherkin
Feature: Profile Stats Redesign — Hero Card + Status Chips

  Scenario: AC-PROF-04 — Hero + chips layout; old grid absent
    Given the user is logged in
    When the user opens /profile.php
    Then [data-testid="profile-stats"] is visible
    And [data-testid="stats-hero"] is visible
    And [data-testid="stats-chip-role"] is visible
    And [data-testid="stats-chip-competing"] is visible
    And .hf-profile-stats has count 0 (old markup removed)

  Scenario: AC-PROF-05 — No overflow on stats section at 320px
    Given the viewport is set to 320×568px
    When the user opens /profile.php
    Then [data-testid="profile-stats"].scrollWidth equals [data-testid="profile-stats"].clientWidth
    And both chips are visible

  Scenario: AC-PROF-07 — Stars zero state
    Given the user has stars = 0
    When the user opens /profile.php
    Then [data-testid="stats-stars"] contains "★ 0"
    And the element has class "empty"

  Scenario: AC-PROF-07 — Stars earned state
    Given the user has stars = 3
    When the user opens /profile.php
    Then [data-testid="stats-stars"] contains "★★★"
    And the element has class "has"

  Scenario: AC-PROF-08 — Admin role chip dot
    Given I am logged in as admin
    When I open /profile.php
    Then [data-testid="stats-chip-role"] has class "role-admin"

  Scenario: AC-PROF-08 — User role chip dot
    Given I am logged in as a regular user
    When I open /profile.php
    Then [data-testid="stats-chip-role"] has class "role-user"

  Scenario: AC-PROF-08 — Competing chip: not in competition
    Given in_competition = 0
    When the user opens /profile.php
    Then [data-testid="stats-chip-competing"] has class "out"

  Scenario: AC-PROF-09 — MD+ same shape (768px)
    Given the viewport is set to 768×1024px
    When the user opens /profile.php
    Then the layout is still one hero + two chips
    And .hf-profile-stats has count 0

  Scenario: AC-PROF-10 — Dark theme renders correctly
    Given the body has class "dark"
    When the user opens /profile.php
    Then [data-testid="profile-stats"] is visible and not overflowing

  Scenario: AC-PROF-10 — Light theme renders correctly
    Given the body has class "light"
    When the user opens /profile.php
    Then [data-testid="profile-stats"] is visible and not overflowing

  Scenario: AC-PROF-11 — Chip tap targets >= 44px
    When the user opens /profile.php
    Then [data-testid="stats-chip-role"] boundingBox height >= 44px
    And [data-testid="stats-chip-competing"] boundingBox height >= 44px
```
