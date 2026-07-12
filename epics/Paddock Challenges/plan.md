# Implementation Plan: Paddock Challenges

Feature doc: `feature.md` · Test plan: `test-plan.md` · Design: `design_handoff_paddock_challenges/`

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
| **1** | Participants, magic links, CP ledger, hub shell (Overview), CP board, CP chip | yes (new pages only) | yes |
| **2** | Nav shell swap (bottom bar, drawer preferences) | yes (site-wide) | yes |
| **3** | Rumor or Not (+ generator + admin-page drafts) | yes | yes |
| **4** | Trivia (+ weekly cron) | yes | yes |
| **5** | Prediction Duels (+ resolution/reset hooks) | yes | yes |
| **6** | Context-aware home hero + streak surfacing + polish | yes | yes |

Dependency notes: 1 blocks 3/4/5; 2 and 6 are independent of the games but 6 reads CP/streak so it
lands last; 3, 4, 5 are mutually independent (ordered by increasing complexity).

---

## Phase 0 — Foundations (no user-visible change)

1. **`database/add_challenges.sql`** — all ten tables from `feature.md` §Architecture, idempotent (`CREATE TABLE IF NOT EXISTS`); FK collation pins for `users.id` references. Register every table in `database/migrations.json`; mirror in `database/schema.sql`. Verify with `npm run schema:check`.
2. **Settings:** add `challenge_rumor_deck_size INT DEFAULT 3` to `settings` (same migration).
3. **Translation stubs:** all `ch_*` keys (feature.md §New Translation Keys) into `public/lang/user.php` da+en blocks; `email_magic_*` / `email_duel_result_*` into `public/lang/email.php`.
4. Deploy to test; existing pages must be pixel-identical.

## Phase 1 — Foundation backend + hub shell

1. **`public/includes/challenges.php`**: `getChallengeParticipant()` (core-session → auto-link per REQ-104; guest marker; null), `requireChallengeParticipant()`, `awardChallengePoints($db,$pid,$game,$points,$sourceRef)` (idempotent via the UNIQUE key), `getCpLeaderboard()`, `getChallengeStreak()`, `scoreDuelPrediction()` (pure, used in Phase 5 but defined+unit-tested here).
2. **Join flow:** `public/challenges-join.php` (email form → pending participant + magic link + `sendEmail()`; byte-identical response either way; rate-limit scope `'magic'`, fail-closed, `Retry-After: 900`). `public/challenges-verify.php` (token consume exactly like `reset_password.php:24-59`; sets `$_SESSION['challenge_participant_id']`; optional display-name form).
3. **Conversion:** `public/challenges-upgrade.php` (verified guest session required; `validatePasswordStrength()`; create `users` row `in_competition=0`; link `core_user_id`; `establishSession()`). Ships together with its management surface: the **Challenges admin page skeleton** `public/admin-challenges.php` (REQ-501, admin chrome + nav link) carrying the **converted-guests list + `in_competition` toggle** (REQ-505), and the users-tab filter excluding guest-origin conversions (`public/includes/admin/users.php`, REQ-506).
4. **Hub:** `public/challenges.php` — arena chrome (checkered strip, broadcast band, segment control, `.hf-arena-*` CSS block), Overview section only (CP scoreboard tower, Perfect Week tracker reading trivia answers — renders 0/6 until Phase 4, game tiles in teaser state), public teaser for visitors without a session (games teased + join CTA per the epic's guest flow).
5. **CP board:** `public/challenges-board.php` — public, arena-skinned, `getCpLeaderboard()` rows via `.hf-row`/`.hf-rank`, "Guest ####" fallback.
6. **CP chip** in `header.php` when `getChallengeParticipant()` resolves (cheap SUM, per-request).
7. E2E: `@challenges` suite bootstrap + seed actions (see `test-plan.md`).

## Phase 2 — Nav shell swap (site-wide; additive first, delete after verification)

1. **`public/includes/bottom_bar.php`**: replace the profile/toggles strip with the four `.hf-bb-item` destinations; accented Challenges cell (30×30 red square, radius 9px, glow); active state from `$currentPage`; arena tint when `$currentPage === 'challenges'`.
2. **`public/includes/header.php` drawer**: rows per REQ-004 (Challenges row with red gamepad + "New" `.hf-badge.open`; Public CP leaderboard row → `challenges-board.php`); **Preferences block** at the bottom — three `.hf-seg` controls whose buttons are anchors to the existing `?toggle_theme=1`/`?toggle_lang=1`/`?toggle_font=1` handlers (active side from `getTheme()/getLang()/getFont()`); account block (Profile/Sign out vs Sign in).
3. **`public/includes/footer.php`**: include rule becomes "not admin" only.
4. Delete the old bottom-strip markup/CSS only after E2E passes on test (skill principle: additive, not destructive).

## Phase 3 — Rumor or Not

1. **Play:** rumors section in `challenges.php` — today's deck query (`status='published' AND publish_date <= today`, minus answered, oldest first; rollover for free), card UI per prototype, POST answer (CSRF) → `challenge_answers` insert + `awardChallengePoints(..., "rumor_or_not:<id>")` when correct → reveal state; done state when queue empty; deck counter `answered-today / deck_size`.
2. **Admin drafts:** rumor-drafts block on `public/admin-challenges.php` (REQ-502) — draft list with bilingual edit, `is_real` display, publish date, publish/veto. No `$allowedTabs` entry (D10); the page reuses the admin chrome and is linked from the admin nav.
3. **Generator:** `bin/generate-rumor-items.js` — reads `paddock-rumors/data/knowledge-base.json` (read-only), Claude API (latest Sonnet-class model) drafts real-fact cards (`is_real=1`, `source_ref` = KB id+hash) and plausible-false variants (`is_real=0`), both da+en, writes drafts via SQL file or seed endpoint for admin review. Run locally/CI — never on shared hosting (NFR-101).

## Phase 4 — Trivia

1. **Play:** trivia section — week's published questions (Mon–Sat `publish_date`), option buttons with answered-state styling per prototype, POST answer → `challenge_trivia_answers` + 5 CP when correct; per-question reveal + explanation; done states (Perfect Week vs summary).
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
- **P1:** AC-B gherkin (join/verify/expiry/single-use/enumeration/auto-link/no-core-access/conversion) · CP board public and correct · chip visibility rules · admin page reachable by admin only, converted guest in its list and absent from the core users tab (AC-F).
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
