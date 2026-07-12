# Feature: Paddock Challenges

Epic: `paddock-challenges-epic.md` · Plan: `plan.md` · Test plan: `test-plan.md`
Design handoff: `design_handoff_paddock_challenges/` (README + `Paddock Prototype.dc.html` — the hifi spec)

Refined 2026-07-12 against the design handoff, via the design-handoff-implementer skill. Supersedes the
feature blocks previously embedded in the epic file.

---

## Contents

1. [Scope](#scope)
2. [Decisions (signed off)](#decisions-signed-off)
3. [Requirements](#requirements) — A: Navigation & home · B: Foundation · C: Rumor or Not · D: Duels · E: Trivia · F: Admin
4. [User Stories](#user-stories)
5. [Architecture](#architecture)
6. [Security Model](#security-model)
7. [Design Fidelity Notes](#design-fidelity-notes)
8. [Acceptance Criteria](#acceptance-criteria)
9. [New Translation Keys](#new-translation-keys)
10. [Files to Change](#files-to-change)

---

## Scope

**In:** A new Challenges area playable any day: three games (Rumor or Not, Prediction Duels, Trivia)
earning **Challenge Points (CP)** — a ledger fully separate from betting points; email-only guest
access via magic links; a public CP leaderboard; the site-wide navigation change the design handoff
specifies (bottom bar → Home·Races·Board·Challenges, preferences → burger drawer); a context-aware
home hero; a day-streak stat.

**Out (v1):** auto-generated trivia from Jolpica data (NFR-401 keeps authoring manual/admin);
push/real-time duel notifications beyond email; any change to core scoring, `bets`, or pool logic;
guest access to core betting features.

---

## Decisions (signed off)

### 2026-07-11 (architecture review round)

| # | Decision |
| --- | --- |
| D1 | **Duel scoring is a new fixed 5/2/0 pure function** (`scoreDuelPrediction()`). The epic's claim that core scoring is 5/2/0 was wrong — core scoring is settings-driven 25/18/15 exact + 5 wrong-position + perfect stars (`public/includes/scoring.php:4`) and is not touched. |
| D2 | **Fake rumors are generated Picks-side.** `paddock-rumors/data/knowledge-base.json` is read-only input (it has NO `is_real` or `language` fields — verified). A generator script calls the Claude API to produce plausible-but-false variants (`is_real=0`) and real-fact cards (`is_real=1`); admin reviews drafts before publish. |
| D3 | **Fully bilingual including content** — rumor items and trivia questions store da+en text. UI strings use the existing `t()` system; the prototype's `L` tables are the authoritative copy. |
| D4 | **Self-serve guest→core conversion** for verified guests (password + display name, no invite token). Converted users default `in_competition = 0` — **confirmed 2026-07-12**; admin flips it from the converted-guests list on the Challenges admin page (D10). |

### 2026-07-12 (design-handoff round)

| # | Decision |
| --- | --- |
| D5 | **Two public leaderboards.** Bottom-bar **Board** = existing betting standings (`public/leaderboard.php`). The **CP board is a separate public page reached only via the Challenges hub** — linked from hub Overview, the burger-menu "Public CP leaderboard" row, and the between-races home top-3 section, all deep-linking into the hub's board. |
| D6 | **Day streak ships in v1.** A Europe/Copenhagen calendar day with ≥1 challenge action (rumor answer, trivia answer, or duel prediction submission) counts; streak = consecutive such days ending today or yesterday. Computed from answer timestamps — no new table. |
| D7 | **Perfect Week = 6 weekly trivia questions** (one per day Mon–Sat, published upfront for the ISO week). Tracker segments = correct trivia answers this week; 6/6 → +20 CP awarded once. Supersedes the prototype's mixed 3-rumor+3-trivia tracker feed and the epic's "3–5 questions" cadence. |
| D8 | **Cadences pinned:** rumors = daily deck of 3 (setting-configurable); trivia = 6/week per D7. Unanswered rumor items roll over (don't expire). |
| D9 | **Hero windows pinned:** the race hero shows from **24 hours before the betting window opens** until **race end (race start + 3 hours)**; the Challenges hero shows the rest of the time. Supersedes the earlier `status === 'open'` rule and closes its race-day edge. |
| D10 | **Challenges gets its own admin page** (`public/admin-challenges.php`), not a tab in `admin.php`. Converted guests are listed and managed there (including the `in_competition` flip) and are **excluded from the core users list** in the existing admin panel. |

---

## Requirements

### A. Navigation & context-aware home (new scope from the design handoff)

Functional:
- [REQ-001] The bottom nav bar becomes four items on all member-site pages: **Home · Races · Board · Challenges**. The Challenges cell is accented: 30×30 red rounded square (radius 9px), white gamepad glyph, red glow — it reads as a doorway, not a peer utility.
- [REQ-002] Profile leaves the bottom bar and moves into the burger drawer (signed-in: Profile + Sign out; signed-out: Sign in).
- [REQ-003] Theme / Language / Font toggles leave the bottom bar and become a **Preferences** block at the bottom of the burger drawer, rendered as `.hf-seg` segmented controls ([moon|sun], [DA|EN], [Brand|System]). Available to every visitor regardless of auth.
- [REQ-004] The drawer nav rows become: Home, Races, Leaderboard, Rules, **Challenges** (red gamepad icon + "New" badge), **Public CP leaderboard** (external-link icon → the hub's CP board, per D5).
- [REQ-005] The top bar gains a **CP chip** (bolt icon + "N CP", red-tinted pill, `--mono` font) shown whenever the visitor has an active challenge identity (core member with a participant record, or verified guest session). Tapping it opens the hub.
- [REQ-006] The homepage hero is context-aware per **D9**. The **race hero** (eyebrow, title, countdown, bet CTA) shows during the race-hero window: from `windowOpen − 24h` until `raceStart + 3h`, where `windowOpen = raceStart − betting_window_hours` (settings-driven, default 48 — the same derivation `getBettingStatus()` uses at `functions.php:154-159`). Outside that window the **Challenges hero** shows (radial-glow background, "Paddock Challenges" title, CP/Rank/Streak stat row when signed in, "Play now" CTA), followed by a Next-race card and a top-3 CP leaderboard section linking into the hub's board. New helper `isRaceHeroWindow($race, $settings, $now)` in `public/includes/challenges.php`; with no upcoming race, the Challenges hero always shows.
  - Inside the race-hero window the existing status logic still gates the CTA and countdown target (bet CTA only while `open`; countdown to window-open while `pending`, to race start while `open`).
- [REQ-007] On the race-weekend home, Challenges appears as a slim tappable strip below the hero (gamepad icon, "N CP · trivia live · a duel waiting" style status line, chevron) linking to the hub.
- [REQ-008] Inside the Challenges hub, sub-sections (**Overview · Rumors · Duels · Trivia**) ride a top segment control; the bottom bar stays put with Challenges lit and takes the arena tint `rgba(13,13,16,.95)`. Never a second bottom bar.

Non-functional:
- [NFR-001] Preference toggles keep the existing server round-trip pattern: `?toggle_theme=1|toggle_lang=1|toggle_font=1` handled in `public/includes/header.php:34-59` before output, via `setTheme()/setLang()/setFont()` (`public/includes/functions.php:110/68/130` — session + cookie + DB when logged in). **Never call `setcookie()` from page code** (security headers are already sent by `config.shared.php`). The drawer's segmented controls are anchors to these same URLs.
- [NFR-002] "Brand" font = the existing `editorial` stack; "System" = existing `system` stack. No new webfonts (see Design Fidelity Notes).
- [NFR-003] The bottom bar remains excluded on admin (`public/includes/footer.php:3`); the profile-page exclusion is dropped since Profile leaves the bar.
- [NFR-004] Mobile-first: 44px minimum touch targets, 16px minimum input font, no layout shift on hero swap.

### B. Guest access & CP foundation

Functional (retained/amended from the epic):
- [REQ-101] Any visitor can register for Challenges with an email address only — no password for guests.
- [REQ-102] Guest registration sends a single-use magic link; clicking it verifies the address and starts a **challenge session**.
- [REQ-103] A guest participant record is distinct from a core `users` record — no display name, theme, font required at signup. Language IS captured (from the visitor's current `getLang()` value) so emails and content render correctly.
- [REQ-104] A logged-in core member gets a challenge participant record **silently auto-created and linked on first hub visit**, reusing their display name — no prompt (per the epic's core-member flow).
- [REQ-105] CP lives in a dedicated ledger (`challenge_points`). No arithmetic ever combines CP with betting points.
- [REQ-106] A public CP leaderboard shows rank, display name (or "Guest ####"), and total CP (sum across all three games). Public to logged-out visitors, reached via the hub only (D5).
- [REQ-107] Verified guests may optionally set a display name; otherwise they appear as "Guest" + last 4 chars of participant id.
- [REQ-108] A verified guest can self-serve convert to a full core member (password + display name; email already verified). CP history is preserved — the participant row gains `core_user_id`; no CP reset. Converted users default `in_competition = 0` (D4, confirmed); admin flips it from the converted-guests list on the Challenges admin page (REQ-505).
- [REQ-109] **Streak** (D6): qualifying action = rumor answer, trivia answer, or duel prediction submission; a Copenhagen calendar day with ≥1 action counts; streak = consecutive days ending today or yesterday (so it doesn't die at midnight before the user has played). Shown in the home hero stat row and the hub scoreboard.
- [REQ-110] Unverified participants (status `pending`, never clicked a link) are purged after 30 days (GDPR hygiene; piggybacks the existing cron).
- [REQ-111] Joining with an email that already belongs to a core `users` account creates **no** guest participant. The HTTP response stays identical (NFR-106); the email sent instead says "you already have an account — log in and open Challenges" (core members get their participant via REQ-104). This prevents duplicate identities (guest + core-linked for the same person) and the conversion-time email collision. If a guest's email becomes a core account *after* joining (edge), conversion refuses with a log-in prompt instead of creating a duplicate `users` row.

Non-functional (retained from the epic):
- [NFR-101] simply.com shared hosting: PHP 8 + PDO/MySQL, Apache + mod_rewrite, no Node runtime in production.
- [NFR-102] No build step; plain PHP/JS/CSS.
- [NFR-103] Session-based auth for guests using the core app's session, **but a distinct marker**: `$_SESSION['challenge_participant_id']`, never `user_id` (precedent: `mfa_pending` in `public/mfa_challenge.php`). Only full conversion calls `establishSession()`.
- [NFR-104] Magic-link tokens: `bin2hex(random_bytes(32))`, single-use (`used` flag), 30-minute expiry. Pattern: `password_resets` (`public/forgot_password.php:39-48`, `public/reset_password.php:24-59`).
- [NFR-105] 44px touch targets, 16px inputs (as NFR-004).
- [NFR-106] Magic-link request responses are **byte-identical** whether or not the email exists (stricter than the current password-reset copy, which differs between branches — do not copy that nuance).
- [NFR-107] Magic-link requests are rate-limited via the existing `login_attempts` mechanism with a new scope `'magic'` (`isRateLimited()/recordLoginAttempt()` at `public/includes/functions.php:491/513`), fail-closed in the caller, `Retry-After: 900` header, **no HTTP 429** (OpenResty proxy limitation, see `public/login.php:42-46`).

### C. Rumor or Not

- [REQ-201] A feed of short items, each a real confirmed F1 fact or a synthetic rumor, tagged `is_real` at ingestion.
- [REQ-202] Participant guesses "Real" or "Rumor"; immediate reveal (REAL/RUMOR stamp, correct/missed line, explanation) and CP.
- [REQ-203] Each item answerable once per participant (DB-enforced UNIQUE).
- [REQ-204] Daily deck of 3 published items (setting-configurable, D8); unanswered items roll over.
- [REQ-205] Correct = 10 CP, ledger `source_ref = "rumor_or_not:<item-id>"`. Incorrect = 0 CP, no penalty.
- [REQ-206] The generator reads `paddock-rumors/data/knowledge-base.json` **read-only** — no write ever touches `paddock-rumors/`.
- [REQ-207] Generator output lands as `status='draft'` items (bilingual text + context tag + explanation + `is_real`); admin reviews, edits, publishes or vetoes on the Challenges admin page (REQ-502).
- [NFR-201] Ground truth (`is_real`) is created at Picks-side ingestion (the KB has no such flag — corrected from the epic). No fact-checking at play time.
- [NFR-202] Card UI per prototype: context badge, claim text, two 56px buttons (Rumor red-tinted / Real green-tinted), border turns green/red on answer, "Next card →" / "Finish deck →", champagne done-state.

### D. Prediction Duels

- [REQ-301] Any participant (guest or core) can challenge another participant to a head-to-head podium duel for the next upcoming race.
- [REQ-302] "Quick Match" queues the participant in `duel_quickmatch` (at most one open request per participant per race, DB-enforced). Pairing runs in a single transaction (`SELECT … FOR UPDATE` on the oldest waiting row, delete both queue rows, insert the duel) so two concurrent requests produce **exactly one** duel. Unmatched requests expire at race start, no penalty.
- [REQ-303] Each side submits a top-3 podium pick, independent of (and invisible to) any core bet. Opponent's pick is hidden until the duel locks.
- [REQ-304] Duel predictions lock at race start — same boundary as core betting (`getBettingStatus()` returns `closed` at `race_date`+`race_time`).
- [REQ-305] Raw duel score per side = **fixed 5/2/0** (D1): 5 per exact position, 2 per correct driver wrong position, 0 otherwise.
- [REQ-306] Higher raw score wins: winner 15 CP, loser 5 CP, tie 10 CP each. Ledger `source_ref = "duel:<duel-id>"`.
- [REQ-307] Multiple open duels allowed; one prediction per participant per duel (DB-enforced).
- [REQ-308] A duel where either side never submits by race start is **void** — no CP either way.
- [REQ-309] Resolution runs when race results are entered — hooked after `calculateRacePoints()` in the admin `update_race` handler (`public/admin.php:123-152`). Resolution **skips duels already `resolved` or `void`**: re-saving results is a no-op for settled duels. Changing an already-entered result therefore requires `reset_race_result` first, then re-entry — the same discipline the core flow already imposes (its guard restricts resets to the most-recently-completed race).
- [REQ-310] **`reset_race_result` (`public/admin.php:164-206`) must also reverse duel outcomes**: delete the duel's CP ledger rows, clear duel scores/winner, and return the duel to `active` (it remains locked because the race has started; only re-entering results can settle it again). (Edge case the epic missed.)
- [REQ-311] Both sides are notified of the outcome by bilingual email (`sendEmail()` + `getEmailTemplate()`, `public/includes/smtp.php:362/506`).
- [NFR-301] Duel picks live in `duel_predictions` — never read from or write to `bets`.
- [NFR-302] Picker reuses the core podium-picker pattern (`public/bet.php` + `public/assets/js/bet-modal.js`). Validation = missing/duplicate/invalid-driver checks only; the core-only rules in `validateBetCombination()` (combo-already-taken, quali-order ban) do **not** apply to duels.

### E. Trivia

- [REQ-401] Multiple-choice F1 questions (2–4 options, one correct), tagged with a topic, stored bilingually.
- [REQ-402] **6 questions per ISO week** (D7), `publish_date` one per day Mon–Sat; a question becomes playable on its publish date and stays open **until its ISO week ends (Sunday 23:59:59 Europe/Copenhagen)**. Answers after week end are rejected — so Perfect Week eligibility is unambiguous and the Monday cron never races late answers.
- [REQ-403] Each question answerable once per participant (DB-enforced UNIQUE); revisiting shows the original result.
- [REQ-404] Correct = 5 CP, `source_ref = "trivia:<question-id>"`. Incorrect = 0 CP with the correct answer revealed.
- [REQ-405] **Perfect Week**: all 6 of a week's questions answered correctly → +20 CP, awarded exactly once as a single ledger entry `source_ref = "trivia_week:<iso-week>"` (e.g. `trivia_week:2026-W28`).
- [REQ-406] Perfect Week is evaluated by a weekly cron (`public/cron/challenge_weekly.php`), Bearer-`CRON_SECRET` GitHub Actions pattern (model: `.github/workflows/cron-notifications.yml`), ISO week boundaries in Europe/Copenhagen. If a week had no published questions, no evaluation occurs.
- [REQ-407] The hub Overview's 6-segment Perfect Week tracker shows correct answers this week (filled = `--gold`), aligned 1:1 with the bonus condition (D7).
- [NFR-401] Question authoring is manual (Challenges admin page, REQ-503) in v1; Jolpica auto-generation is a future enhancement.
- [NFR-402] Option buttons per prototype: 44px+, letter chips, immediate green/red state with icons, others dim, `pointer-events` locked after answering.

### F. Admin — Challenges control room (D10)

- [REQ-501] A **separate admin page** `public/admin-challenges.php` (standard opening: `requireAdmin()`, `requireCsrf()` on POSTs) reusing the admin panel's chrome, linked from the existing admin panel navigation. It is not a tab in `admin.php` — no `$allowedTabs` entry.
- [REQ-502] **Rumor drafts**: list `challenge_items` drafts with bilingual edit, `is_real` display, publish date, publish/veto actions (REQ-207).
- [REQ-503] **Trivia authoring**: create/edit bilingual questions (options, correct option, topic, explanation, publish date), publish per ISO week (REQ-401/402).
- [REQ-504] **Duel oversight**: read-only list of open/locked/settled/void duels per race, with each side's submission state (no pick contents before lock, REQ-303).
- [REQ-505] **Converted guests list**: every participant with `email IS NOT NULL AND core_user_id IS NOT NULL` (guest-origin conversions), showing display name, email, conversion date, CP total, and an **`in_competition` toggle** — this is where admin lets a converted guest into the money pool (D4).
- [REQ-506] **Converted guests never appear in the core users list** in the existing admin panel (`public/includes/admin/users.php` filters them out). They are managed exclusively from the Challenges admin page; user-level actions the admin needs for them (e.g. deactivate) live on the converted-guests list.
- [NFR-501] Admin-facing strings go to `public/lang/admin.php` (da+en), not `user.php`.

---

## User Stories

**Casual F1 fan (guest):** join the off-weekend games with just my email, so I can play without a full account. Found the CP board from a friend's link; wants zero-friction entry.

**Core member:** jump into Challenges with my existing profile — no second signup, my display name follows me, and my CP brag is separate from my betting points.

**Rival member:** challenge a specific friend (or quick-match) to a podium duel so race week starts with a grudge.

**Admin (Djarnis):** review AI-drafted rumor cards, author weekly trivia, and trust that entering race results (or resetting them) settles/unsettles duels without manual bookkeeping.

---

## Architecture

### Data model (additive — `database/add_challenges.sql`)

All ids `VARCHAR(36)` via `generateUUID()` unless noted. Any FK to `users.id` pins
`CHARACTER SET latin1 COLLATE latin1_swedish_ci` (legacy collation — see `database/schema.sql:26`).
Register every object in `database/migrations.json` and mirror in `database/schema.sql`.

```sql
challenge_participants
  id VARCHAR(36) PK
  email VARCHAR(255) NULL UNIQUE          -- NULL for core-linked rows
  core_user_id VARCHAR(36) NULL UNIQUE    -- FK users.id (latin1 pin), ON DELETE SET NULL
  display_name VARCHAR(100) NULL
  language CHAR(2) NOT NULL DEFAULT 'da'
  status ENUM('pending','verified') NOT NULL DEFAULT 'pending'
  created_at / verified_at

challenge_points                          -- the CP ledger; append-only except duel reversal
  id VARCHAR(36) PK
  participant_id FK → challenge_participants ON DELETE CASCADE
  game ENUM('rumor_or_not','duel','trivia')
  points INT NOT NULL
  source_ref VARCHAR(64) NOT NULL
  awarded_at DATETIME
  UNIQUE (participant_id, source_ref)     -- idempotent awards (perfect week, duel resolve)

challenge_magic_links                     -- clone of password_resets shape
  id INT AUTO_INCREMENT PK
  participant_id FK CASCADE
  token VARCHAR(64) UNIQUE                -- bin2hex(random_bytes(32))
  expires_at DATETIME                     -- +30 minutes
  used TINYINT(1) DEFAULT 0
  created_at

challenge_items                           -- Rumor or Not content
  id VARCHAR(36) PK
  text_da / text_en TEXT
  context_da / context_en VARCHAR(64)     -- badge, e.g. "Grid expansion"
  explain_da / explain_en TEXT
  is_real TINYINT(1) NOT NULL
  status ENUM('draft','published') DEFAULT 'draft'
  publish_date DATE NULL
  source_ref VARCHAR(255) NULL            -- KB id + content_hash for real-fact provenance
  created_at
  INDEX (status, publish_date)

challenge_answers
  id VARCHAR(36) PK
  participant_id FK CASCADE · item_id FK CASCADE
  guess_real TINYINT(1) · correct TINYINT(1) · answered_at
  UNIQUE (participant_id, item_id)

duels
  id VARCHAR(36) PK
  race_id FK → races(id)
  challenger_id / opponent_id FK → challenge_participants
  is_quick_match TINYINT(1) DEFAULT 0
  status ENUM('pending','active','resolved','void') DEFAULT 'pending'
  winner_id VARCHAR(36) NULL              -- NULL on tie/void
  created_at / resolved_at

duel_quickmatch                           -- waiting room; rows deleted on pairing (REQ-302)
  race_id FK → races(id)
  participant_id FK → challenge_participants ON DELETE CASCADE
  created_at
  UNIQUE (race_id, participant_id)        -- one open request per participant per race

duel_predictions
  id VARCHAR(36) PK
  duel_id FK CASCADE · participant_id FK
  p1 / p2 / p3  (driver ids, same type as bets.p1)
  score INT NULL                          -- raw 5/2/0 result, set at resolution
  submitted_at
  UNIQUE (duel_id, participant_id)

challenge_trivia_questions
  id VARCHAR(36) PK
  question_da / question_en TEXT
  options_da / options_en TEXT            -- JSON array, 2–4 strings
  correct_option TINYINT NOT NULL         -- 0-based index
  topic VARCHAR(32)                       -- t() key for the badge
  explain_da / explain_en TEXT
  status ENUM('draft','published') DEFAULT 'draft'
  publish_date DATE NULL
  created_at
  INDEX (status, publish_date)

challenge_trivia_answers
  id VARCHAR(36) PK
  participant_id FK CASCADE · question_id FK CASCADE
  chosen_option TINYINT · correct TINYINT(1) · answered_at
  UNIQUE (participant_id, question_id)
```

CP leaderboard = `SELECT participant_id, SUM(points) FROM challenge_points GROUP BY participant_id`
joined to `challenge_participants`, modeled on `getLeaderboard()` (`public/includes/functions.php:324`).
Streak (D6) = distinct Copenhagen dates from the union of the three action tables, walked backwards.

### Components

| Component | Path (new unless noted) | Role |
| --- | --- | --- |
| Shared helpers | `public/includes/challenges.php` | `getChallengeParticipant()`, `requireChallengeParticipant()`, `awardChallengePoints()`, `scoreDuelPrediction()`, `getChallengeStreak()`, `getCpLeaderboard()` |
| Hub | `public/challenges.php` | Arena-skinned; `?section=overview\|rumors\|duels\|trivia` (server-rendered + progressive JS); public teaser state for visitors with no session |
| CP board | `public/challenges-board.php` | Public, arena-skinned, hub-linked only (D5) |
| Guest join | `public/challenges-join.php` | Email form → participant (pending) + magic link email |
| Magic landing | `public/challenges-verify.php` | Token consume → verified + challenge session + optional display-name prompt |
| Conversion | `public/challenges-upgrade.php` | Verified guest → core user (`validatePasswordStrength()`, `establishSession()`, link `core_user_id`) |
| Game POST endpoints | inside hub page or `public/challenges-answer.php` | CSRF-guarded answers/picks; JSON responses for the progressive JS |
| Admin page | `public/admin-challenges.php` (standalone, admin chrome reused, linked from admin nav) | Rumor drafts, trivia authoring, duel oversight, converted-guests list + `in_competition` toggle (REQ-501..505) |
| Users-list filter | `public/includes/admin/users.php` (modified) | Excludes guest-origin conversions (REQ-506) |
| Generator | `bin/generate-rumor-items.js` (repo tooling, not deployed) | Reads KB read-only, Claude API drafts bilingual items |
| Weekly cron | `public/cron/challenge_weekly.php` + `.github/workflows/cron-challenges.yml` | Perfect Week evaluation + pending-participant purge (REQ-110) |
| Nav shell | `public/includes/header.php`, `bottom_bar.php`, `footer.php` (modified) | REQ-001..008 |
| Home | `public/index.php` (modified) | Context-aware hero (REQ-006/007) |

### Identity & session flow

```text
visitor ──(email)──► participant(pending) ──(magic link ≤30min)──► participant(verified)
                                                                    $_SESSION['challenge_participant_id']
core member ──(first hub visit)──► participant(verified, core_user_id=uid) — silent, REQ-104
verified guest ──(conversion, REQ-108)──► users row + establishSession(); participant keeps CP history
```

`getChallengeParticipant()` resolves in order: core session (`user_id` → participant by
`core_user_id`, auto-creating per REQ-104) → guest marker (`challenge_participant_id`) → null.
The guest marker never grants access to core pages; core auth checks are untouched.

---

## Security Model

- **Session separation:** `challenge_participant_id` is the only thing a magic link grants. No code path sets `user_id` except `establishSession()` (login, MFA promotion, and now conversion). Guests hitting core pages get the normal logged-out behavior.
- **Tokens:** 64-hex random, single-use, 30 min, old tokens deleted on re-request (password-reset precedent). Stored raw as the existing pattern does — accepted precedent, single-use + short TTL mitigates.
- **Enumeration:** join/resend responses byte-identical for known vs unknown emails (NFR-106).
- **Rate limiting:** scope `'magic'` per IP and per email; fail-closed in the caller; `Retry-After: 900`, never HTTP 429 (NFR-107).
- **CSRF:** every POST (join, answers, duel create/accept/pick, admin actions) uses `csrfField()` / `requireCsrf()`.
- **XSS:** all content (rumor text, trivia options, display names — including AI-generated drafts) escaped at render with `htmlspecialchars()`; options JSON decoded then escaped per option.
- **CSP:** any inline JS on new pages carries the per-request nonce from `header.php` (`$nonce`, line 7).
- **Isolation:** CP ledger and duel predictions never touch `users.total_points`, `bets`, or pool math; admin `update_race`/`reset_race_result` hooks are additive calls after the core logic.
- **Ledger integrity:** `UNIQUE(participant_id, source_ref)` makes every award idempotent; duel reversal deletes by `source_ref`.

---

## Design Fidelity Notes

Verified against the live `public/assets/css/style.css` (the handoff README's assumptions were mostly right; corrections below):

- **Existing tokens confirmed:** `--f1-red`, `--f1-red-light`, `--gold`, `--bg-primary/secondary/card/hover`, `--border-color`, `--border-soft`, `--text-primary/secondary/muted`, `--status-success`, `--status-warning`, `--display`, `--body`, `--mono`.
- **Corrections:** `--radius-sm/md/lg/xl/pill` do **not** exist — keep hard-coded radii per house style. Medal classes are `.hf-rank.r1/.r2/.r3` (on the rank badge, not the row) — the prototype already uses this correctly. `--bg-primary` (dark) is `#131316`, so the arena base `#0b0b0d` is genuinely deeper, as intended.
- **Fonts:** the site's `--display/--body` resolve to the *editorial* stack (Kalam/Courier Prime via Google Fonts), not Chivo/Manrope — the prototype rendered with the claude.ai/design bundle's fonts. **Use the site tokens as-is; do not add Chivo/Manrope.** "Brand" in the Font preference = existing `editorial` (NFR-002).
- **Net-new arena values** (scope to the hub + CP board only, namespaced classes e.g. `.hf-arena-*`): base `#0b0b0d`; bottom-bar tint `rgba(13,13,16,.95)`; header band `linear-gradient(90deg,#17171b,#0d0d10)`; tile `rgba(35,35,40,.62)` / active card `rgba(35,35,40,.7)`; light-on-dark `#f5f5f7`; success text `#34d399`; trivia blue `#3b82f6` / icon `#7fb2ff`; scoreboard glow `0 0 34px rgba(225,6,0,.16)`; number glow `0 0 24px rgba(251,191,36,.4)`; checker strip `repeating-conic-gradient(#f5f5f7 0 25%, #0b0b0d 0 50%) 0 0/14px 14px`; toast shadow `0 8px 24px rgba(0,0,0,.45)`.
- **Animations:** `pp-fade` (.25s), `pp-pop` (.28s), `pp-drop`; reuse existing `star-pulse` and card hover lift. No springs/parallax.
- **Icons:** FA6 Free solid, self-hosted (`public/assets/fontawesome/`) — all glyphs in the handoff's list ship with `fa-solid-900.woff2`.
- **Light theme:** the arena skin is intentionally dark in both themes (it's "a different room"), but chrome outside the hub must honor `body.light` as today.
- **Screens the handoff does NOT cover** (spec here, plain site style): guest join form (single email field + CTA, REQ-101/NFR-106 copy), magic-link landing with optional display-name prompt (REQ-107), duel creation (pick opponent from participant list / Quick Match button) — the prototype only shows accept + settled states, conversion page (REQ-108), CP board page (model: `leaderboard.php` rows + arena chrome), **Challenges admin page (REQ-501–505) — built in house style without a design round, reusing the admin panel chrome**.

---

## Acceptance Criteria

### A. Navigation & home

```gherkin
Feature: Navigation and context-aware home

  Scenario: Bottom bar shows the four destinations
    Given any member-site page on mobile
    Then the bottom bar shows Home, Races, Board, Challenges
    And the Challenges cell is a red rounded square with a gamepad glyph
    And Profile is not in the bar

  Scenario: Preferences live in the drawer for everyone
    Given a signed-out visitor opens the burger menu
    Then Theme, Language and Font segmented controls are visible and functional
    And toggling one performs a server round-trip that preserves other query params

  Scenario: Between-races hero
    Given the time is outside the race-hero window (after race end + 3h, more than 24h before betting opens)
    When a signed-in participant opens the homepage
    Then the Challenges hero shows with CP, rank and streak stats
    And a next-race card and a top-3 CP section (linking into the hub board) follow

  Scenario: Race-weekend hero
    Given the time is inside the race-hero window (windowOpen − 24h through raceStart + 3h)
    When any visitor opens the homepage
    Then the race hero with countdown and bet CTA shows
    And Challenges appears as a slim strip below it

  Scenario: CP chip
    Given a visitor with an active challenge identity
    Then the top bar shows the CP chip with their current total
    And a visitor without one sees no chip
```

### B. Foundation

```gherkin
Feature: Guest access & Challenge Points foundation

  Scenario: New guest signs up and verifies
    Given a visitor with no account submits a valid email
    And they click the magic link within 30 minutes
    Then their participant status becomes "verified"
    And a challenge session starts without any core login

  Scenario: Magic link expires
    Given a magic link issued 31 minutes ago
    When it is clicked
    Then verification is refused with an expiry message and a re-request option

  Scenario: Magic link is single-use
    Given an already-used magic link
    When it is clicked again
    Then it is refused as already used

  Scenario: Enumeration-safe join
    Given one join request with a registered email and one with an unknown email
    Then both HTTP responses are identical

  Scenario: Duplicate email signup
    Given an email already registered as a verified guest
    When the same email is submitted again
    Then no duplicate participant is created and a fresh link goes to the existing record

  Scenario: Core member auto-links silently
    Given a logged-in core member with no participant record
    When they open the Challenges hub
    Then a verified participant record linked to their account exists
    And their display name is reused with no extra prompt

  Scenario: Guest session grants no core access
    Given a verified guest session
    When they request a login-protected core page
    Then they are treated as logged out

  Scenario: Combined CP board totals are correct
    Given a participant with 30 CP from rumors, 15 from duels and 10 from trivia
    When the CP board renders
    Then their total shows 55 CP
    And betting points appear nowhere on it

  Scenario: Guest without display name
    Given a verified guest with no display name
    Then the CP board shows "Guest" plus the last 4 characters of their participant id

  Scenario: Conversion preserves CP
    Given a verified guest with 45 CP
    When they complete self-serve conversion with the same email
    Then a core user is created with in_competition = 0
    And their participant row is linked and still totals 45 CP
    And they appear on no betting leaderboard and in no pool calculation

  Scenario: Join with a core member's email creates no guest
    Given an email that belongs to an existing core account
    When it is submitted to the Challenges join form
    Then the HTTP response is identical to a normal join
    And no participant record is created
    And the email received says to log in instead of containing a magic link

  Scenario: Content follows the participant's language
    Given a rumor item and trivia question stored in Danish and English
    When a participant with language "en" plays and then switches to "da"
    Then item text, options and explanations render in the selected language

  Scenario: Streak counts consecutive Copenhagen days
    Given a participant answered something yesterday and today
    Then their streak is at least 2
    And a participant whose last action was 2 days ago has streak 0
```

### C. Rumor or Not scenarios

```gherkin
Feature: Rumor or Not

  Scenario: Correct guess awards exactly 10 CP
    Given an unanswered published item tagged is_real = false
    When the participant guesses "Rumor"
    Then 10 CP is recorded with source_ref "rumor_or_not:<item-id>"
    And the reveal shows the RUMOR stamp and the explanation

  Scenario: Incorrect guess awards nothing
    Given an unanswered item tagged is_real = true
    When the participant guesses "Rumor"
    Then 0 CP is awarded and the correct answer "Real" is revealed

  Scenario: Item cannot be answered twice
    Given the participant already answered an item
    Then it never reappears in their unanswered queue
    And a re-posted answer for it is rejected without a second ledger entry

  Scenario: Deck cleared state
    Given the participant answered every published item
    Then the done state shows "Deck cleared" with fresh-cards copy

  Scenario: Rollover between race weekends
    Given no race this week and yesterday's deck is partly unanswered
    Then those items are still playable today

  Scenario: KB is read-only
    Given the generator ingests new items
    Then it performs no write inside paddock-rumors/
```

### D. Prediction Duels scenarios

```gherkin
Feature: Prediction Duels

  Scenario: Exact win under 5/2/0
    Given A picks P1=Verstappen, P2=Norris, P3=Leclerc
    And B picks P1=Norris, P2=Verstappen, P3=Piastri
    And the result is P1=Verstappen, P2=Norris, P3=Leclerc
    When the duel resolves
    Then A scores 15 raw (5+5+5) and earns 15 CP
    And B scores 4 raw (2+2+0) and earns 5 CP

  Scenario: Tie pays both sides
    Given both sides score equal raw points
    Then each is awarded 10 CP

  Scenario: Unanswered challenge voids
    Given the opponent never submits before race start
    Then the duel is void and no CP is awarded to either side

  Scenario: Late pick blocked
    Given the race has started
    When a participant submits a duel pick
    Then it is rejected with a race-started message

  Scenario: Quick Match pairs first-come
    Given two participants request Quick Match in the same window
    Then exactly one duel is created between them for the next race
    And both queue rows are gone, even when the requests arrive concurrently

  Scenario: Re-saving results does not disturb settled duels
    Given a resolved duel for a race
    When the admin saves that race's results again
    Then the duel outcome and its CP entries are unchanged

  Scenario: Isolation from core bets
    Given a core member with both a core bet and a duel pick for the same race
    Then neither read nor write of one affects the other

  Scenario: Reset reverses duel CP
    Given a resolved duel awarded 15/5 CP
    When the admin runs reset_race_result for that race
    Then those ledger rows are removed and the duel returns to unresolved
    And re-entering results re-awards identical CP exactly once
```

### E. Trivia scenarios

```gherkin
Feature: Trivia

  Scenario: Correct answer awards 5 CP
    Given an unanswered published question
    When the participant picks the correct option
    Then 5 CP is recorded with source_ref "trivia:<question-id>"

  Scenario: Incorrect answer reveals the correct option
    When the participant picks a wrong option
    Then 0 CP is awarded, their pick shows red and the correct option shows green

  Scenario: Question cannot be answered twice
    Given an already-answered question
    Then revisiting shows the original result and rejects new answers

  Scenario: Perfect Week awarded once
    Given a participant answered all 6 of the week's questions correctly
    When the weekly cron runs
    Then exactly one +20 CP entry with source_ref "trivia_week:<iso-week>" exists
    And a second cron run adds nothing

  Scenario: No bonus on a miss
    Given 5 of 6 correct this week
    Then no Perfect Week entry is created and trivia CP totals 25

  Scenario: Late answer rejected
    Given a question from last ISO week
    When a participant submits an answer on Monday
    Then it is rejected and no CP or answer row is written

  Scenario: Empty week
    Given no questions were published this week
    Then the cron neither awards nor denies a bonus for it

  Scenario: Tracker matches the bonus condition
    Given 4 correct answers this week
    Then the hub tracker shows 4 of 6 segments filled
```

### F. Admin scenarios

```gherkin
Feature: Challenges admin page

  Scenario: Admin page requires admin role
    Given a core member without the admin role, or a challenge guest session
    When they request admin-challenges.php
    Then access is denied exactly like admin.php

  Scenario: Converted guest is managed from the Challenges admin page
    Given a guest who converted to a core account
    Then they appear in the converted-guests list with CP total and conversion date
    And they do not appear in the admin panel's core users list

  Scenario: Admin admits a converted guest to the competition
    Given a converted guest with in_competition = 0
    When the admin flips their in_competition toggle
    Then the user appears in betting leaderboard and pool calculations from then on

  Scenario: Draft rumor is not playable until published
    Given a challenge item with status "draft"
    Then it never appears in any participant's deck
    And after the admin publishes it with today's date it does
```

---

## New Translation Keys

Add to `public/lang/user.php` under both `da` and `en` blocks (copy lifted from the prototype `L`
tables — authoritative); email keys to `public/lang/email.php` (nested `['da'=>[],'en'=>[]]`,
`sprintf` placeholders).

| Key | da | en |
| --- | --- | --- |
| `ch_nav_board` | Stilling | Board |
| `ch_nav_challenges` | Challenges | Challenges |
| `ch_hero_eyebrow` | Ingen løb i denne uge · Challenges live | No race this week · Challenges live |
| `ch_hero_sub` | Tre hurtige spil, intet løb nødvendigt. Hold dine point stigende. | Three quick games, no race needed. Keep your points climbing. |
| `ch_your_cp` | Dine CP | Your CP |
| `ch_rank` | Plads | Rank |
| `ch_streak` | Dages stime | Day streak |
| `ch_play_now` | Spil nu | Play now |
| `ch_not_open` | Ikke åben | Not open |
| `ch_full_board` | Hele tavlen | Full board |
| `ch_board_lede` | Rumor or Not + Duels + Trivia, lagt sammen. | Rumor or Not + Duels + Trivia, combined. |
| `ch_public_board` | Offentlig CP-tavle | Public CP leaderboard |
| `ch_preferences` | Præferencer | Preferences |
| `ch_font_brand` | Brand | Brand |
| `ch_font_system` | System | System |
| `ch_games_zone` | SPILZONE · LIVE | GAMES ZONE · LIVE |
| `ch_your_standing` | Din placering | Your standing |
| `ch_challenge_points` | Challenge points | Challenge points |
| `ch_this_week` | i denne uge | this week |
| `ch_perfect_week` | Perfekt uge | Perfect Week |
| `ch_games_live` | Spil live nu | Games live now |
| `ch_overview` | Oversigt | Overview |
| `ch_rumors` | Rygter | Rumors |
| `ch_duels` | Dueller | Duels |
| `ch_trivia` | Trivia | Trivia |
| `ch_your_move` | Din tur | Your move |
| `ch_streak_line` | %d-dages stime · hold den i live | %d-day streak · keep it alive tonight |

Plus (copy to draft during implementation, same style): join/verify flow strings (`ch_join_*`,
`ch_verify_*`, `ch_display_name_*`), game states (`ch_deck_cleared`, `ch_quiz_complete`,
`ch_correct`, `ch_missed`, `ch_next_card`, `ch_finish_deck`, `ch_next_question`, `ch_finish_quiz`,
`ch_back_overview`, `ch_settled`, `ch_locked_in`, `ch_accept_lock`, `ch_quick_match`,
`ch_challenge_friend`, `ch_race_started`, `ch_all_caught_up`), toasts (`ch_toast_cp`,
`ch_toast_duel_locked`), and email keys (`email_magic_subject/greeting/intro/button/expiry/ignore`,
`email_duel_result_subject/won/lost/tie/body`).

Admin-page strings (draft review, trivia authoring, converted-guests list) go to
`public/lang/admin.php` per NFR-501.

Existing keys reused: `theme`, `language`, `rules`, `profile`, `leaderboard`, nav home/races, sign
in/out — do not duplicate.

---

## Files to Change

**New:** `database/add_challenges.sql` · `public/includes/challenges.php` · `public/challenges.php` ·
`public/challenges-board.php` · `public/challenges-join.php` · `public/challenges-verify.php` ·
`public/challenges-upgrade.php` · `public/admin-challenges.php` ·
`public/cron/challenge_weekly.php` · `.github/workflows/cron-challenges.yml` ·
`bin/generate-rumor-items.js` · arena CSS block appended to `public/assets/css/style.css`

**Modified:** `public/includes/header.php` (drawer rows + Preferences block + CP chip) ·
`public/includes/bottom_bar.php` (four-destination bar) · `public/includes/footer.php` (include rule)
· `public/index.php` (context hero) · `public/includes/admin/users.php` (converted-guest filter, REQ-506) · `public/admin.php` (admin-nav link + duel hooks in
`update_race`/`reset_race_result`) · `public/lang/user.php` + `public/lang/email.php` ·
`database/migrations.json` + `database/schema.sql` · `public/tools/test-seed.php` (see test plan) ·
`tests/run-e2e-suites.js` + `tests/playwright.config.js` (`@challenges` suite)

**Docs to update at implementation time:** `docs/architecture.md` (new area + cron), `docs/testing.md`
(seed actions + suite), `docs/paddock-rumors-reference.md` (generator's read-only consumption),
`CLAUDE.md` only if a new top-level command appears.
