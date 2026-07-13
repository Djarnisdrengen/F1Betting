# Implementation Plan: Paddock Challenges

Feature doc: `feature.md` · Test plan: `test-plan.md` · Design: `design_handoff_paddock_challenges/`
Participant-model feature specs: `feature-3-participant-profile.md` · `feature-4-core-member-request.md`
· `feature-5-invite-guardrails.md`

Suggested branch: `paddock-challenges`

Authored via the **design-handoff-implementer** skill: phased, additive, each phase independently
shippable and verified on the test environment (`npm run deploy:test`) before the next begins.

---

## Reading the design files (DC → PHP translation rules)

`Paddock Prototype.dc.html` is the spec; it is **not** production code.

- **Discard:** the `<helmet>`/`<x-dc>` wrappers, `sc-if`/`sc-for` tags, `{{ }}` template holes, `_ds_bundle.js`, the `class Component extends DCLogic` runtime, and the CDN Font Awesome link (FA6 is self-hosted at `public/assets/fontawesome/`).
- **Keep as spec:** the markup inside the tags, every inline style value, all copy strings (the `L.en`/`L.da` tables are the authoritative translations), and the state logic in `renderVals()`/handlers (answer flows, segment switching, toast, perfect-week fill, streak line).
- **Translate:** `sc-if` → PHP `if`; `sc-for` → `foreach`; `{{ var }}` → `<?= htmlspecialchars(...) ?>`; React state → server round-trips (forms/POST) with small progressive-enhancement JS for reveal states and toasts; `onClick` handlers → real links/forms first, JS second.
- **Reuse before creating:** `.hf-top`, `.hf-bottom`/`.hf-bb-item`, `.hf-drawer`/`.hf-drawer-row`, `.hf-seg`, `.hf-badge (.open/.soon/.done)`, `.hf-row`/`.hf-rank (.r1/.r2/.r3)/.self`, `.hf-cta-primary`, `.hf-racecard`, `.hf-hero*`, `.hf-countdown` + `renderHfCountdown()`, `.hf-stat-l`, `.hf-section-h`, `.hf-toc-title` — all exist in `public/assets/css/style.css`. Net-new CSS is the arena skin only, namespaced `.hf-arena-*`, appended to `style.css` (never edits to existing rules in this epic).
- **CSP:** every inline `<script>` needs `nonce="<?= $nonce ?>"` (`header.php:7`).

---

## Phasing

| Phase | Content | User-visible | Shippable |
| --- | --- | --- | --- |
| **0** | Migration + settings + translation stubs | no | yes (invisible) |
| **1** | Participant model (anon-first identity, access-token persistence, permanent password, admin-gated promotion request), invite plumbing + guardrails, CP ledger, hub shell (Overview), CP board, CP chip, participant profile (Feature 3), admin promotion queue + converted-guests list (Feature 4), invite guardrails & consent (Feature 5) | yes (new pages) | yes |
| **2** | Nav shell swap (bottom bar, drawer preferences) | yes (site-wide) | yes |
| **3** | Rumor or Not (+ generator + admin-page drafts) | yes | yes |
| **4** | Trivia (+ weekly cron) | yes | yes |
| **5** | Prediction Duels (+ resolution/reset hooks) | yes | yes |
| **6** | Context-aware home hero + streak surfacing + polish | yes | yes |

Dependency notes: 1 blocks 3/4/5; 2 and 6 are independent of the games but 6 reads CP/streak so it
lands last; 3, 4, 5 are mutually independent (ordered by increasing complexity). Phase 1's
identity/persistence layer is **game-independent and testable on its own**; only the *"Challenge a
friend" UI entry point* needs a finished deck, so it is wired in with the first game (Phase 3) while
its plumbing (tables, tokens, emails, owner-confirm, friend-join) is built and integration-tested in
Phase 1. This is the "refactoring of participants, tested before Section C" slice.

**Feature 5 is a hard gate on Phase 3, not a nice-to-have.** The friend-invite send becomes reachable
by real, non-seeded third parties the moment Phase 3 ships the deck-done "Challenge a friend" CTA —
so `canSendInvite()` (suppression, dedupe, per-sender cap, rate limiting) must be **live** by then, not
still the Phase 1 TODO stub. Features 3 and 4 have no game dependency at all and ship in Phase 1
alongside the identity layer they extend (participant profile is the UI home for B3/B4/B6; the
promotion queue is the admin-side counterpart of the request `getOrCreate`/`requestCoreMembership()`
already builds).

---

## Phase 0 — Foundations (no user-visible change)

1. **`database/add_challenges.sql`** — all twelve tables from `feature.md` §Architecture **plus the `password_hash` column on `challenge_participants`** and the two participant-model tables (`challenge_access_tokens`, `challenge_invites`), idempotent (`CREATE TABLE IF NOT EXISTS`; the one `ALTER … ADD COLUMN password_hash` is not idempotent — skip on a DB that already ran it); FK collation pins for `users.id` references. Register every table/column in `database/migrations.json`; mirror in `database/schema.sql`. Verify with `npm run schema:check`. **(Done — the schema mirror is already in the four files.)**
2. **Settings:** add `challenge_rumor_deck_size INT DEFAULT 3` to `settings` (same migration).
3. **Translation stubs:** all `ch_*` keys (feature.md §New Translation Keys) into `public/lang/user.php` da+en blocks; `email_magic_*` / `email_duel_result_*` into `public/lang/email.php`.
4. Deploy to test; existing pages must be pixel-identical.

## Phase 1 — Participant model refactoring + hub shell

The **participant refactoring** (feature.md §B, D11–D14) is built and verified here, before any game.
The identity/persistence layer is game-independent and fully testable now; the *"Challenge a friend"
UI entry point* needs a finished deck so it is wired in with the first game (Phase 3), but the invite
*plumbing* (tables, tokens, emails, owner-confirm, friend-join) is built and integration-tested here.

1. **`public/includes/challenges.php`** — `getChallengeParticipant()` resolves **core session → challenge session marker → valid `ch_access` device cookie (re-establishing the session + rotating the token) → null** (REQ-121); `requireChallengeParticipant()`; `getOrCreateAnonymousParticipant()` (pending, `email NULL`, sets the session marker — called on first game answer in Phase 3/4); `awardChallengePoints()` (idempotent via UNIQUE); `getCpLeaderboard()`, `getChallengeStreak()`, `scoreDuelPrediction()` (defined + unit-tested here, used Phase 5).
2. **Access tokens** (REQ-120–124): `issueAccessToken($pid)` (raw token → `ch_access` httponly/secure/`SameSite=Lax`/90-day cookie **and** the emailed access link; store only `hash('sha256', …)` in `challenge_access_tokens`), `rotateAccessToken()` on cookie re-establishment, `revokeAccessToken()` / `revokeAllAccessTokens()` (sign-out). Set/clear the cookie **before any output** — in the resolver / verify / login handler, never from page body after headers (the lang/theme/font cookies are the precedent; `setcookie()` from page code is banned — NFR-001).
3. **Verify / return** (`public/challenges-verify.php`): consumes an **owner-confirm magic link** *or* a **friend-invite token** → `status='verified'`, `verified_at`, session marker, `issueAccessToken()`. Owner-confirm attaches nothing (their play is already on their row, REQ-115); friend-invite marks the invite `accepted` and drops the friend into the same item set (the play UI lands Phase 3/4, REQ-116). Emailed **access links** (REQ-120) resolve through here too. Token consume mirrors `reset_password.php:24-59`.
4. **Invite plumbing** (`public/challenges-invite.php`, POST, CSRF): given the current participant's just-played item set + score (Phase 3/4 supplies these live; Phase 1 accepts a **seeded** set so it's testable now), set the owner's email + send the owner-confirm link (reuse `challenge_magic_links`, scope `'magic'`), insert `challenge_invites`, send the friend invite (scope `'invite'`), byte-identical responses (NFR-106). Per-sender caps / dedupe / suppression are the **full Feature 5 guardrail matrix** (step 12 below) — gated by `canSendInvite()` from the start, not a stub, since Phase 3 exposes this to real third parties.
5. **Permanent password** (`public/challenges-upgrade.php`, **repurposed** from the old self-serve conversion): a **verified** participant sets a password → `hashPassword()` into `challenge_participants.password_hash` (REQ-125). **No** `users` row, **no** `establishSession()`. Add the participant branch to **`public/login.php`**: resolve `users` first, then a `challenge_participants` password login; on success set **`challenge_participant_id` only** (never `user_id`) + `issueAccessToken()` (REQ-126/127). "Sign out" clears `ch_access` + revokes its token (REQ-123). This logic is the engine behind the **Feature 3** profile page's Account tab (step 10) — that page is the user-facing surface; this step ships the underlying handlers.
6. **Promotion request** (admin-gated, REQ-108/D14): a "request to become a core member" action stores the request via `requestCoreMembership()`; **no participant-initiated path writes `users`**. The full approval loop — queue, atomic `users`-row creation (`in_competition=0`, link `core_user_id`, carry CP history via the retained `participant_id`), **converted-guests list + `in_competition` toggle** on `admin-challenges.php` (REQ-505), and the users-tab exclusion (REQ-506) — is **Feature 4**, built in this same phase (step 11), not deferred: it has no game dependency.
7. **Hub:** `public/challenges.php` — arena chrome (`.hf-arena-*` CSS block), Overview only (CP scoreboard tower, Perfect Week tracker 0/6 until Phase 4, game tiles teased), public teaser + "play now / save your spot" CTAs for no-session visitors.
8. **CP board:** `public/challenges-board.php` — public, arena-skinned, `getCpLeaderboard()` rows; **verified participants only** (anonymous email-null rows unlisted, REQ-106); "Guest ####" fallback.
9. **CP chip** in `header.php` when `getChallengeParticipant()` resolves (cheap SUM, per-request).
10. **Participant profile (Feature 3):** `public/challenges-profile.php` — Profile / Preferences / Account tabs (model: `public/profile.php`'s tab layout, **no History tab** per REQ-128). Profile tab edits `display_name`; Preferences tab is the participant-scoped theme/font/language round-trip (`language` persists to `challenge_participants`, not `users`); Account tab wraps step 5's password handlers (set → permanent / change), step 2/3's `revokeAccessToken()` / `revokeAllAccessTokens()` (sign out / sign out everywhere), and step 6's `requestCoreMembership()` (collapses to a "pending review" state once requested). Anonymous participants get a save-your-spot prompt instead of tabs (REQ-607); a resolved core member is redirected to `/profile.php` (REQ-608). Drawer "Profile" row resolves to this page or `/profile.php` by identity.
11. **Admin promotion queue (Feature 4):** `public/admin-challenges.php` — **Promotion requests** section listing every `promotion_requested_at IS NOT NULL AND core_user_id IS NULL` participant (name, email, permanent-vs-verified, CP total, request date); **Approve** (one transaction: re-check for an email collision in `users`, insert the `users` row — password carried over if permanent, else an unusable hash + emailed set-password link — link `core_user_id`, clear the marker, send the welcome email) and **Reject** (clear the marker, no `users` write). This is also where the **converted-guests list** (REQ-505, `in_competition` toggle) lives, since approval is what produces a converted guest; `public/includes/admin/users.php` gets the REQ-506 exclusion filter in the same step.
12. **Invite guardrails & consent (Feature 5):** `canSendInvite()` in `challenges.php` (suppression → per-friend dedupe → per-sender daily cap `challenge_invite_daily_cap` setting → `isRateLimited(..., 'invite', ...)`, all fail-closed); new `challenge_email_suppressions` table; public `public/challenges-optout.php` (HMAC-verified, idempotent suppression write, no login); friend-email template gains the why-line + opt-out link. Wired into step 4's send path so it is live, not stubbed, before Phase 3.
13. E2E/integration: `@challenges` **identity + persistence** specs (game-independent) + seed actions (see `test-plan.md`).

## Phase 2 — Nav shell swap (site-wide; additive first, delete after verification)

1. **`public/includes/bottom_bar.php`**: replace the profile/toggles strip with the four `.hf-bb-item` destinations; accented Challenges cell (30×30 red square, radius 9px, glow); active state from `$currentPage`; arena tint when `$currentPage === 'challenges'`.
2. **`public/includes/header.php` drawer**: rows per REQ-004 (Challenges row with red gamepad + "New" `.hf-badge.open`; Public CP leaderboard row → `challenges-board.php`); **Preferences block** at the bottom — three `.hf-seg` controls whose buttons are anchors to the existing `?toggle_theme=1`/`?toggle_lang=1`/`?toggle_font=1` handlers (active side from `getTheme()/getLang()/getFont()`); account block (Profile/Sign out vs Sign in).
3. **`public/includes/footer.php`**: include rule becomes "not admin" only.
4. Delete the old bottom-strip markup/CSS only after E2E passes on test (skill principle: additive, not destructive).

## Phase 3 — Rumor or Not

1. **Play:** rumors section in `challenges.php` — first answer calls `getOrCreateAnonymousParticipant()` (Phase 1) so anonymous play works with no email; today's deck query (`status='published' AND publish_date <= today`, minus answered, oldest first; rollover for free), card UI per prototype, POST answer (CSRF) → `challenge_answers` insert + `awardChallengePoints(..., "rumor_or_not:<id>")` when correct → reveal state; done state when queue empty; deck counter `answered-today / deck_size`. **Deck-done state carries the "Challenge a friend" CTA** (B2) — posts the just-played item set + score to `challenges-invite.php` (Phase 1 plumbing); this is where the invite loop's UI entry point lands.
2. **Admin drafts:** rumor-drafts block on `public/admin-challenges.php` (REQ-502) — draft list with bilingual edit, `is_real` display, publish date, publish/veto. No `$allowedTabs` entry (D10); the page reuses the admin chrome and is linked from the admin nav.
3. **Generator:** `bin/generate-rumor-items.js` — reads `paddock-rumors/data/knowledge-base.json` (read-only), Claude API (latest Sonnet-class model) drafts real-fact cards (`is_real=1`, `source_ref` = KB id+hash) and plausible-false variants (`is_real=0`), both da+en, writes drafts via SQL file or seed endpoint for admin review. Run locally/CI — never on shared hosting (NFR-101).

## Phase 4 — Trivia

1. **Play:** trivia section — first answer calls `getOrCreateAnonymousParticipant()` (anonymous play, Phase 1); week's published questions (Mon–Sat `publish_date`), option buttons with answered-state styling per prototype, POST answer → `challenge_trivia_answers` + 5 CP when correct; per-question reveal + explanation; done states (Perfect Week vs summary), each carrying the **"Challenge a friend"** CTA (B2) posting the played set + score to `challenges-invite.php`.
2. **Perfect Week tracker** on Overview now live: filled = correct answers this ISO week (0–6).
3. **Cron:** `public/cron/challenge_weekly.php` — Bearer `CRON_SECRET` (`getBearerToken()` + `hash_equals`), evaluates the **previous** ISO week (Europe/Copenhagen): for each participant with 6/6 correct, `awardChallengePoints(..., 'trivia', 20, "trivia_week:<iso-week>")` (idempotent); also purges 30-day-old pending participants (REQ-110). Workflow `.github/workflows/cron-challenges.yml`, Monday 06:00 CET, modeled on `cron-notifications.yml` incl. the `_TEST` secret variant.
4. **Admin:** trivia authoring block on the Challenges admin page (REQ-503 — bilingual question/options/explanation, correct option, topic, publish date).

## Phase 5 — Prediction Duels

1. **Create/accept:** duels section — "Challenge a friend" (participant picker), "Quick Match" (transactional pairing against `duel_quickmatch` per REQ-302 — `SELECT … FOR UPDATE` on the oldest waiting row, else queue), VS card with accept-&-lock flow per prototype; settled history list. Plus the read-only **duel oversight list** on the Challenges admin page (REQ-504 — no pick contents before lock).
2. **Picks:** podium picker reusing the `bet.php`/`bet-modal.js` pattern against `duel_predictions`; validation = presence/duplicate/valid-driver only (NFR-302); submissions blocked once `getBettingStatus()` says `closed` (REQ-304); opponent's pick hidden until lock (REQ-303).
3. **Resolution hook** in `admin.php` `update_race` (after `calculateRacePoints()`, `:145`): for each locked duel on the race — both picks present → `scoreDuelPrediction()` each side, store scores, winner/tie, award 15/5 or 10/10 CP (`source_ref "duel:<id>"`), send outcome emails; any side missing → `status='void'`, no CP.
4. **Reset hook** in `reset_race_result` (`:164-206`): delete `challenge_points` rows with `source_ref = "duel:<id>"` for the race's duels, clear scores/winner, return duels to locked-unresolved (REQ-310).

## Phase 6 — Context-aware home + polish

1. **`public/index.php`:** hero choice via `isRaceHeroWindow($heroRace, $settings)` (new helper in `challenges.php`, per D9): race hero from `windowOpen − 24h` (`windowOpen = raceStart − betting_window_hours`) until `raceStart + 3h` — with the existing status logic still gating CTA/countdown inside the window — plus the Challenges slim strip below it (REQ-007). Outside the window (or no upcoming race) → Challenges hero (radial-glow background, stat row CP / rank / streak via `getChallengeStreak()`, "Play now" CTA) + next-race card + top-3 CP section linking to `challenges-board.php`.
2. **Toast** component (pill, gold border, bolt icon, `bottom: 84px`, auto-dismiss ~1.6s) for CP gains and duel lock — shared JS in `app.js`, nonce-safe.
3. **Animations:** `pp-fade`/`pp-pop`/`pp-drop` keyframes appended; applied per handoff (hub enter, fresh cards).
4. Verify both themes, both languages, 320px width, and the `hf-badge` action-CTA convention on race cards is untouched.

---

## Acceptance checklist per phase (validate before moving on)

- **P0:** `npm run schema:check` green · existing pages identical · no console/CSP errors.
- **P1 (participant refactoring — the slice to test before Section C):** AC-B gherkin — anonymous play creates a pending/email-null row · owner-confirm + friend-invite verify & issue access token/cookie · persistent return via access link **and** via device cookie (token rotates) · permanent password set + participant login sets `challenge_participant_id` only (never `user_id`) · sign-out revokes this device only · enumeration-safe invite/confirm · silent core auto-link · guest session grants no core access · CP board public, correct, anonymous unlisted · promotion request is admin-gated (no `users` write) · chip visibility rules. **Plus, now shipped in this same phase:** the participant profile page gates correctly (anonymous → prompt, core → redirect) and its Account tab drives the same password/sign-out/promotion assertions as above (**Feature 3**) · admin Approve is atomic and idempotent, carries the permanent-participant password or emails a set-password link, preserves CP with no data migration, and Reject writes nothing to `users` (**Feature 4**) · every friend-invite send passes `canSendInvite()` (suppression, dedupe, daily cap, rate limit) and the opt-out endpoint suppresses only its own HMAC-verified address (**Feature 5**).
- **P2:** AC-A bottom bar + drawer scenarios · toggles work signed-out on every page · admin excluded · no double bottom bar in hub.
- **P3:** AC-C rumor scenarios incl. UNIQUE re-answer rejection and read-only KB.
- **P4:** AC-E trivia scenarios incl. idempotent cron re-run and empty week.
- **P5:** AC-D duel scenarios incl. 5/2/0 arithmetic, void, reset-reversal round-trip.
- **P6:** hero switch both states incl. the D9 boundaries (window-open − 25h/− 23h, race start + 2h/+ 4h) · streak numbers match seeded history · 320px/light/dark/da/en pass.

Full test detail, seeding, and suite wiring: `test-plan.md`.

---

## Design-handoff conformance check (design-handoff-implementer review)

Conflicts and gaps found between the handoff files and repo reality, with resolutions:

| # | Finding | Resolution |
| --- | --- | --- |
| 1 | README says Board = betting standings; prototype's Board screen renders CP data with the CP lede; home top-3 links "Full board" → Board | **D5** (user decision): Board tab = betting standings; CP board = separate public page via the hub; home top-3 and menu row deep-link to it |
| 2 | Streak appears in the design but nowhere in the epic | **D6**: ships in v1 with a precise derivation rule |
| 3 | Perfect Week tracker fed by 3 rumors + 3 trivia in the prototype; +20 bonus is trivia-only | **D7**: trivia moves to 6/week; tracker = bonus progress exactly |
| 4 | README assumes `--radius-*` tokens and Chivo/Manrope fonts exist in the live CSS | Neither exists: hard-coded radii per house style; site font tokens used as-is, "Brand" = existing editorial stack |
| 5 | Prototype uses CDN Font Awesome and DC runtime | Self-hosted FA6; DC constructs discarded per translation rules above |
| 6 | Handoff has no screens for guest join, magic-link landing, duel creation/quick-match, conversion, CP board, admin page | Spec'd as site-style pages in `feature.md` §Design Fidelity Notes; guest join/verify/conversion/CP board are candidates for follow-up design. The Challenges admin page is built in house style (D10) reusing existing admin chrome. |
| 7 | `getBettingStatus` signature assumed `($race,$now)`; actual is `($race,$settings)`; the handoff's "betting open" hero trigger left race day itself ambiguous | **D9** (user decision): race hero from 24h before window-open until race end (start + 3h); REQ-006 rewritten with `isRaceHeroWindow()` |
| 8 | Prototype's demo `signIn` sets `cp:15` and hardcodes names/stats | Demo scaffolding — ignored |

No blocking contradictions remain; the handoff is implementable as specified in `feature.md`.
