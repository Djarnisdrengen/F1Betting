# Epic: Paddock Challenges (Off-Race-Weekend Contest Arena)

Refined 2026-07-12 against the design handoff in `design_handoff_paddock_challenges/`.
Detailed spec: `feature.md` · Implementation: `plan.md` · Testing: `test-plan.md`

---

## User Value

Paddock Picks activity is concentrated entirely around race weekends — predictions open, race
happens, points post, then silence until the next round. Paddock Challenges gives friends (and
friends-of-friends) a reason to open the app on a random Tuesday: three lightweight games that don't
require a race to be happening, plus a public on-ramp so new people can join without a full account.

- Keeps the core group engaged during triple-header gaps and the off-season.
- Opens a low-friction door for casual F1 fans to join via email only — no commitment to the full prediction game.
- Gives existing members a second, separate scoreboard to brag about, distinct from their podium-prediction standing.

## User Experience

- A new **Challenges** area behind an accented bottom-nav doorway (red rounded-square gamepad tab), playable any day of the week. Inside: a distinctly-skinned "arena" hub with four sections — Overview · Rumors · Duels · Trivia — on a top segment control.
- The site shell changes with it (per the design handoff): bottom bar becomes **Home · Races · Board · Challenges**; Profile and the Theme/Language/Font preferences move into the burger drawer; the top bar gains a CP chip; the homepage hero is context-aware (race hero when betting is open, Challenges hero between races).
- Anyone can join with just an email address (magic link) — no display name curation, no profile required. Existing members join instantly with their profile, no second signup.
- Three games award **Challenge Points (CP)**, a currency entirely separate from betting points: **Rumor or Not** (spot the AI-generated fake, +10 CP), **Prediction Duels** (head-to-head podium picks, 15/10/5 CP), **Trivia** (weekly quiz, +5 CP per correct, +20 Perfect Week bonus). A day-streak stat rewards showing up.
- One combined public CP leaderboard, reached via the hub, shows everyone — members and guests — for shareability.
- Bilingual (da default / en) including game content. Mobile-first, same touch standards as the core app.

> **Design note:** "Rumor or Not" is a *spot-the-fake* game, not *wait-and-see*. Paddock Rumors
> content is synthetic, so there is no future event to resolve against; instead real confirmed facts
> are mixed with synthetic rumors and the player calls which is which — instant resolution, which
> fits daily off-weekend play better anyway.

## Success Metrics

- Weekday (non-race-weekend) active users, before vs. after launch.
- Guest sign-ups via email, and guest → core member conversion rate.
- % of existing core members who play at least one Challenge per month.
- CP leaderboard page views during race-free weeks.
- Average Challenges played per participant per week; day-streak retention.

---

## Decisions log (signed off)

**2026-07-11** (architecture review of the original epic):

| # | Decision | Why |
| --- | --- | --- |
| D1 | Duels score with a new fixed **5/2/0 pure function** | The epic assumed core scoring was 5/2/0; it is actually settings-driven 25/18/15 + 5 + stars (`public/includes/scoring.php`). Duels get their own deliberately simple scheme. |
| D2 | Fake rumors come from a **Picks-side generator** (Claude API) with admin review | The paddock-rumors knowledge base is factually accurate and has no `is_real` flag; the truth-labeling layer must be created at ingestion, KB read-only. |
| D3 | **Fully bilingual including content** (da+en stored per item/question) | Bilingual is a core-app standard the original epic never mentioned. |
| D4 | **Self-serve guest→core conversion**; converted users default `in_competition = 0` (confirmed 2026-07-12; admin admits them from the Challenges admin page) | Core registration is invite-only today; REQ-108 needed a path that doesn't hand strangers a seat in the money pool. |

**2026-07-12** (design-handoff refinement):

| # | Decision | Why |
| --- | --- | --- |
| D5 | **Two public boards**: bottom-bar Board = betting standings; the CP board is a separate public page reached only via the Challenges hub | The handoff README and prototype contradicted each other on the Board tab; this keeps the "no duplicate Board" rule and gives CP a shareable home. |
| D6 | **Day streak ships in v1**, derived from action timestamps (Copenhagen days, no new table) | The design leans on it (hero stat + scoreboard row); it's cheap at ~10 users. |
| D7 | **Perfect Week = 6 weekly trivia questions**, tracker = correct answers, 6/6 → +20 once | Aligns the hub's 6-segment tracker exactly with the bonus; supersedes the prototype's mixed rumor+trivia feed. |
| D8 | Cadences pinned: **rumors 3/day** (configurable, roll over), **trivia 6/week** | The epic gave ranges; the design and D7 pin them. |
| D9 | **Hero windows**: race hero from 24h before the betting window opens until race end (start + 3h); Challenges hero otherwise | The status-based rule left race day showing the games hero; this pins exact boundaries. |
| D10 | **Separate Challenges admin page** with a converted-guests list (incl. `in_competition` toggle); converted guests excluded from the core users list in the admin panel | Keeps the ~10-member core list clean and gives conversions one management surface. |

---

## Shared architecture (details in `feature.md`)

- **New tables only** (`database/add_challenges.sql`, registered in `migrations.json`, mirrored in `schema.sql`): `challenge_participants`, `challenge_points` (the CP ledger, `UNIQUE(participant, source_ref)` for idempotent awards), `challenge_magic_links`, `challenge_items` + `challenge_answers`, `duels` + `duel_predictions` + `duel_quickmatch`, `challenge_trivia_questions` + `challenge_trivia_answers`. UUID PKs; FKs to `users.id` pin the legacy latin1 collation.
- **Participant model:** one record per player — guest (email + magic link, session marker `challenge_participant_id`, never `user_id`) or core-linked (silent auto-create on first hub visit). Conversion links the two, CP history intact.
- **Shared helper file** `public/includes/challenges.php`; reuse map: magic links ← `password_resets`, rate limiting ← `login_attempts` scope `'magic'`, lock boundary ← `getBettingStatus()`, duel resolution ← admin `update_race` / reversed by `reset_race_result`, picker ← `bet.php`/`bet-modal.js`, Challenges admin page ← admin-panel chrome (standalone `admin-challenges.php`, D10), cron ← Bearer `CRON_SECRET` GitHub Actions pattern, emails ← `sendEmail()`/`getEmailTemplate()`.
- **Bilingual rule:** every user-facing string through `t()`; content stored da+en.

## Delivery order (phases in `plan.md`)

Foundation (tables, participants, hub shell, CP board) → nav shell swap → Rumor or Not → Trivia →
Duels → context-aware home + polish. Each phase independently shippable.

---

## Acceptance Criteria (epic level — full gherkin in `feature.md`)

```gherkin
Feature: Paddock Challenges

  Scenario: Guest joins via email only
    Given a visitor with no Paddock Picks account
    When they submit their email and click the magic link
    Then a verified guest participant exists
    And they can play Rumor or Not, Duels, and Trivia without a core account

  Scenario: Core member joins with existing profile
    Given a logged-in core member
    When they open the Challenges area for the first time
    Then a challenge participant record is linked to their account silently
    And no signup step is shown

  Scenario: Combined CP board reflects all three games
    Given participants have earned CP in all three games
    When the public CP board (reached via the hub) is viewed
    Then each total is the sum of CP across the games
    And core podium-prediction points are not included

  Scenario: Challenges stay active between race weekends
    Given no race is scheduled within the next 3 days
    When a participant opens the Challenges area
    Then at least one game has content available to play

  Scenario: The site shell carries the new area
    Given any member-site page
    Then the bottom bar shows Home, Races, Board and an accented Challenges tab
    And preferences (theme, language, font) are available to everyone in the burger drawer
```
