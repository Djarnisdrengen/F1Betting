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
- Opens a zero-friction door: casual fans **play first with no signup**, then save their spot with one email — and finishing a deck lets them **challenge a friend**, who joins with a single click. A built-in way for the group to grow.
- Gives participants a durable identity (a persistent access link, or an optional password) without ever touching the core money pool — promotion to full member stays admin-approved.
- Gives existing members a second, separate scoreboard to brag about, distinct from their podium-prediction standing.

## User Experience

- A new **Challenges** area behind an accented bottom-nav doorway (red rounded-square gamepad tab), playable any day of the week. Inside: a distinctly-skinned "arena" hub with four sections — Overview · Rumors · Duels · Trivia — on a top segment control.
- The site shell changes with it (per the design handoff): bottom bar becomes **Home · Races · Board · Challenges**; Profile and the Theme/Language/Font preferences move into the burger drawer; the top bar gains a CP chip; the homepage hero is context-aware (race hero when betting is open, Challenges hero between races).
- Anyone can **play anonymously first**; to keep progress or challenge a friend they save their spot with a single email — a persistent access link brings them straight back with no repeat magic links — and can optionally set a password to become a permanent participant. Existing members join instantly with their profile, no second signup.
- **Challenge a friend** turns a good score into an invite: two emails in, the friend replays the same cards to beat your score, and both get a link back and a head-to-head result.
- Three games award **Challenge Points (CP)**, a currency entirely separate from betting points: **Rumor or Not** (spot the AI-generated fake, +10 CP), **Prediction Duels** (head-to-head podium picks, 15/10/5 CP), **Trivia** (weekly quiz, +5 CP per correct, +20 Perfect Week bonus). A day-streak stat rewards showing up.
- One combined public CP leaderboard, reached via the hub, shows everyone — members and guests — for shareability.
- Bilingual (da default / en) including game content. Mobile-first, same touch standards as the core app.

> **Design note:** "Rumor or Not" is a *spot-the-fake* game, not *wait-and-see*. Paddock Rumors
> content is synthetic, so there is no future event to resolve against; instead real confirmed facts
> are mixed with synthetic rumors and the player calls which is which — instant resolution, which
> fits daily off-weekend play better anyway.

## Success Metrics

- Weekday (non-race-weekend) active users, before vs. after launch.
- Anonymous → verified "save your spot" rate; **invite conversion** (friend-invite emails → friend participants).
- Persistent return without a new magic link (access link / cookie / password) as a share of returning sessions.
- Permanent-participant (password) adoption; core-membership **requests** submitted → admin-approved.
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
| D4 | ~~**Self-serve guest→core conversion**~~ — **self-serve mechanism superseded by D14** (promotion is now an admin-approved request). Its pool-safety default is **retained**: an approved conversion still defaults `in_competition = 0`, admitted from the Challenges admin page. | Core registration is invite-only today; the money pool must stay admin-controlled — D14 makes that explicit by removing the self-serve path entirely. |

**2026-07-12** (design-handoff refinement):

| # | Decision | Why |
| --- | --- | --- |
| D5 | **Two public boards**: bottom-bar Board = betting standings; the CP board is a separate public page reached only via the Challenges hub | The handoff README and prototype contradicted each other on the Board tab; this keeps the "no duplicate Board" rule and gives CP a shareable home. |
| D6 | **Day streak ships in v1**, derived from action timestamps (Copenhagen days, no new table) | The design leans on it (hero stat + scoreboard row); it's cheap at ~10 users. |
| D7 | **Perfect Week = 6 weekly trivia questions**, tracker = correct answers, 6/6 → +20 once | Aligns the hub's 6-segment tracker exactly with the bonus; supersedes the prototype's mixed rumor+trivia feed. |
| D8 | Cadences pinned: **rumors 3/day** (configurable, roll over), **trivia 6/week** | The epic gave ranges; the design and D7 pin them. |
| D9 | **Hero windows**: race hero from 24h before the betting window opens until race end (start + 3h); Challenges hero otherwise | The status-based rule left race day showing the games hero; this pins exact boundaries. |
| D10 | **Separate Challenges admin page** with a converted-guests list (incl. `in_competition` toggle); converted guests excluded from the core users list in the admin panel | Keeps the ~10-member core list clean and gives conversions one management surface. |

**2026-07-12** (participant-model refinement — Feature 1 & 2 full specs in `feature.md` §B):

| # | Decision | Why |
| --- | --- | --- |
| D11 | **Anonymous-first play**: visitors play Rumor or Not / Trivia with no email; an anonymous participant (`status='pending'`, `email NULL`) holds their answers, CP and streak until they save their spot | Friction kills the off-weekend funnel; the email is only needed to persist progress or challenge a friend. |
| D12 | **"Challenge a friend" = async beat-my-score**, not a duel: a two-email share (owner + friend) sends the owner a confirm link and the friend an invite to replay the same item set; scores are compared for bragging rights, **no extra CP** | Turns finishing a deck into the viral hook without touching the CP economy or overlapping the Duels game. |
| D13 | **Persistent return** via a hashed, rotating **access token** — an emailed access link plus a 90-day device cookie, resolved in `getChallengeParticipant()` after the session marker | Removes the "request a new magic link every visit" friction that made the email-only model cumbersome; the shared PHP session lifetime is left untouched. |
| D14 | **Two save-your-spot options; promotion is admin-gated**: a verified participant may set a **password** to become a *permanent participant* (stays in `challenge_participants`, unified `/login.php`, no core rights); becoming a **core member** is a *request an admin approves*, never self-serve. **Supersedes D4's self-serve mechanism** (its `in_competition=0` pool default is kept). Full admin flow = Feature 4 | Keeps the ~10-seat money pool admin-controlled while giving participants a durable, password-backed identity that is cleanly separate from core members. |

**2026-07-13** (Feature 3/4/5 specs — closes an open item from REQ-117):

| # | Decision | Why |
| --- | --- | --- |
| D15 | **A "challenge a friend" win pays no bonus CP — permanently, not just in v1.** Both sides earn only their normal per-game CP for the items they answered; the head-to-head result (win/lose/tie) is bragging rights only. **Closes REQ-117's "open decision if desired later."** | Confirmed 2026-07-13. Keeps the invite loop (Feature 1/B2) a pure growth mechanic with zero CP-economy surface — nothing to balance, exploit, or explain, and no overlap with Duels (which is the CP-earning head-to-head game). |

---

## Shared architecture (details in `feature.md`)

- **New tables only** (`database/add_challenges.sql`, registered in `migrations.json`, mirrored in `schema.sql`): `challenge_participants` (+ `password_hash` column, D14), `challenge_points` (the CP ledger, `UNIQUE(participant, source_ref)` for idempotent awards), `challenge_magic_links`, `challenge_access_tokens` (persistent return, D13), `challenge_invites` (beat-my-score share, D12), `challenge_items` + `challenge_answers`, `duels` + `duel_predictions` + `duel_quickmatch`, `challenge_trivia_questions` + `challenge_trivia_answers`. UUID PKs; FKs to `users.id` pin the legacy latin1 collation.
- **Participant model:** four tiers, all in `challenge_participants` (never `users`): **anonymous** (plays, `email NULL`), **verified** (confirmed email → emailed access link + a rotating device-cookie token in `challenge_access_tokens`), **permanent** (verified + `password_hash`, logs in via unified `/login.php`), and admin-linked **core** members (silent auto-create on first hub visit). Session marker is always `challenge_participant_id`, never `user_id`. Becoming a core member is an **admin-approved request** (D14), not self-serve; CP history is preserved on promotion.
- **Shared helper file** `public/includes/challenges.php`; reuse map: magic links ← `password_resets`, rate limiting ← `login_attempts` scope `'magic'`, lock boundary ← `getBettingStatus()`, duel resolution ← admin `update_race` / reversed by `reset_race_result`, picker ← `bet.php`/`bet-modal.js`, Challenges admin page ← admin-panel chrome (standalone `admin-challenges.php`, D10), cron ← Bearer `CRON_SECRET` GitHub Actions pattern, emails ← `sendEmail()`/`getEmailTemplate()`.
- **Bilingual rule:** every user-facing string through `t()`; content stored da+en.

## Delivery order (phases in `plan.md`)

Foundation (tables, participants, hub shell, CP board) → nav shell swap → Rumor or Not → Trivia →
Duels → context-aware home + polish. Each phase independently shippable.

---

## Acceptance Criteria (epic level — full gherkin in `feature.md`)

```gherkin
Feature: Paddock Challenges

  Scenario: Play first, save your spot later
    Given a visitor with no Paddock Picks account
    When they play Rumor or Not and then challenge a friend with their own and a friend's email
    Then they get an owner-confirmation link and the friend gets an invite
    And after clicking their links both are verified participants who return via a persistent access link

  Scenario: Permanent participant without a core account
    Given a verified participant
    When they set a password
    Then they can log in at /login.php as a participant
    And they gain no core-member functionality

  Scenario: Promotion to core member is admin-approved
    Given a participant requests to become a core member
    Then the request awaits admin approval
    And no participant-initiated path creates a core users row

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
