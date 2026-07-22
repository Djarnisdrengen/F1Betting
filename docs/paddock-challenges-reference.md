# Paddock Challenges — Reference & Operator Runbook

**Location:** `public/challenges*.php`, `public/admin-challenges*.php`, `public/includes/challenges.php`, `public/cron/challenge_weekly.php`, `bin/generate-*.js`.

**Status:** Live (P1–P7 shipped). Coexists with the core podium-betting game — additive, does not touch bets/points/pool.

---

## Purpose

Three lightweight games players can do without a race weekend: **Rumor or Not**, **Weekly Trivia**, **Duels**. Playing earns **Challenge Points (CP)** on a public leaderboard — separate bragging rights, not the podium-betting prize pool. See [Challenges vs. the core game](#challenges-vs-the-core-podium-betting-game) below.

Player-facing explainer: `public/challenges-rules.php` (linked from the top of `challenges.php`).

---

## Relationship to Paddock Rumors / f1-intelligence

Easy to conflate — these are three different systems that share one knowledge base:

- **`f1-intelligence/`** — Phase 1 RAG chat feature (live on Vercel). Untouched by anything below.
- **`paddock-rumors/`** — content-gen layer that builds `paddock-rumors/data/knowledge-base.json` (factual F1 docs per season) from Jolpica-F1 results + F1Technical analysis. Feeds `f1-intelligence/` chat.
- **Paddock Challenges content generators** (`bin/generate-rumor-items.js`, `bin/generate-trivia-questions.js`) — read that same `knowledge-base.json` **read-only** and draft Rumor-or-Not cards / Trivia questions from it via Claude. `docs/paddock-rumors-reference.md` does not mention this — it's documented here instead.

---

## File map

```
public/
├── challenges.php              hub — ?section=overview|rumors|duels|trivia|board (board = public CP leaderboard, no auth)
├── challenges-rules.php        player-facing "how it works" page
├── challenges-join.php         guest onboarding (magic-link email)
├── challenges-verify.php       consumes magic-link / invite token
├── challenges-profile.php      guest/verified participant account page
├── challenges-board.php        redirect stub → challenges.php?section=board (for old bookmarks/links)
├── challenges-invite.php       "beat my score" friend-invite (email)
├── challenges-optout.php       HMAC-verified email unsubscribe
├── admin-challenges.php        admin control room (?tab=members|rumors|trivia|duels|suppressions)
├── cron/challenge_weekly.php   Monday cron: Perfect Week bonus + GDPR purge
├── tools/import-rumor-drafts.php    HTTP import endpoint (Bearer token), called by bin/generate-rumor-items.js
├── tools/import-trivia-drafts.php   HTTP import endpoint (Bearer token), called by bin/generate-trivia-questions.js
└── includes/
    ├── challenges.php          all shared model/helper functions (scoring, streak, CP, duel pairing/resolution)
    └── admin-challenges/{members,rumors,trivia,duels,suppressions}.php   per-tab admin partials
        (members = promotion queue + converted guests + full participant roster with delete)

bin/
├── generate-rumor-items.js     drafts Rumor-or-Not cards from paddock-rumors KB, via Claude
├── generate-trivia-questions.js drafts Trivia questions from paddock-rumors KB, via Claude
└── state/{rumor,trivia}-generator-state.<env>.json   which KB docs are already used, per environment (committed back to repo)

.github/workflows/
├── cron-challenges.yml         Monday 05:00 UTC — triggers cron/challenge_weekly.php
└── cron-content-topup.yml      Friday 06:00 UTC — runs both bin/generate-*.js against BOTH test and live, auto-publishing a batch dated the upcoming Monday
```

Schema: `database/schema.sql` lines 228–419 — `challenge_participants`, `challenge_points` (append-only CP ledger), `challenge_magic_links`, `challenge_access_tokens`, `challenge_invites`, `challenge_email_suppressions`, `challenge_items`, `challenge_answers`, `duels`, `duel_quickmatch`, `duel_predictions`, `challenge_trivia_questions`, `challenge_trivia_answers`.

---

## Scoring — how each game awards CP

All CP awards go through `awardChallengePoints($db, $participantId, $game, $points, $sourceRef)` (`public/includes/challenges.php`), which inserts into `challenge_points`. `source_ref` is a unique idempotency key per award — a duplicate call (double-submit, cron re-run) is a silent no-op, never a double-award.

**Rumor or Not — instant, per-answer, no cron.**
POST handler in `challenges.php` (`?section=rumors`). Correct guess → `+10 CP`, `source_ref = "rumor_or_not:$itemId"`. One answer per item (`UNIQUE(participant_id, item_id)`); unanswered items roll forward indefinitely — no expiry.

**Weekly Trivia — instant per-answer, plus a batched weekly bonus.**
Correct answer → `+5 CP` instantly, `source_ref = "trivia:$questionId"`. Unlike Rumors, trivia is scoped strictly to the current ISO week (`YEARWEEK(publish_date, 3)`) — it does **not** roll over; a week with no answers just ends. The **Perfect Week bonus** (`+20 CP`, `source_ref = "trivia_week:$isoWeek"`) is computed by `public/cron/challenge_weekly.php`, triggered by `.github/workflows/cron-challenges.yml` (Monday 05:00 UTC / 06:00 CET, Bearer `CRON_SECRET`). It finds participants who answered every question published in the previous ISO week correctly. The same cron also purges `challenge_participants` still `status='pending'` after 30 days (GDPR), cascading to their child rows.

**Duels — synchronous, triggered by the admin saving race results, not a cron.**
A duel starts either from **Quick Match** (queued and paired with the oldest other waiting participant for the same race) or **Challenge a friend** (`searchChallengeParticipants()` on display name). You can never duel yourself — the search filters out your own row, the `challenge_friend` POST handler rejects `opponent_id == you`, and `createDirectDuel()` throws if challenger and opponent are equal. Because a display name is usually just a first name and rarely unique, each search result also shows a **masked email hint** (`maskEmailForSearch()`: first couple of local-part chars + domain, middle redacted, e.g. `th•••g@gmail.com`) to disambiguate same-named people — the raw address is never sent to the client.

`public/admin.php`'s `update_race` handler calls `calculateRacePoints()` (core scoring) then immediately `resolveDuelsForRace()` (`includes/challenges.php`) — explicitly additive/isolated from core scoring. Per duel: `+5 CP` per driver in the exact right position, `+2 CP` for a driver placed in the top 3 but the wrong slot. Winner gets `15 CP`, loser `5 CP`, a tie pays `10/10`. If either side never locked in a pick before the race started, the duel is voided — no CP either way. `reset_race_result` calls the mirror function `resetDuelsForRace()`, which deletes the matching `challenge_points` rows and reverts non-void duels to `active` so re-entering results resolves cleanly.

**Streaks & leaderboard** (`includes/challenges.php`): `getChallengeStreak()` counts consecutive **ISO weeks** (Mon–Sun) with *any* CP-earning action across all three games (recomputed live, not stored — miss a full week and it resets going forward). Weekly rather than daily on purpose: since content auto-publishes as one atomic batch a week (see "Content pipeline" above), an engaged player naturally clears a week's Rumor/Trivia supply in one sitting and has nothing new until the next Monday — a daily-granularity streak broke on that gap every week regardless of loyalty. `getChallengeCpTotal()` is an all-time sum of the `challenge_points` ledger, no time window. `getChallengeCpThisWeek()` sums the same ledger but scoped to the current ISO week (same `YEARWEEK(...,3)` window as trivia), backing the Overview hero's "+N this week" stat. `getCpLeaderboard()` ranks all `status='verified'` participants (guests and full members together) by total CP, ties broken by earliest `created_at`; `getChallengeRank()` re-walks that same board to find one participant's 1-based position (`null` if they haven't scored yet), used by both the Overview hero's rank pill and the `board` tab's own pill. `getPendingDuelForOverview()` is a trimmed duel lookup (most recent unresolved, unlocked duel this participant hasn't picked yet) so the Overview's "Games Live Now" duels row can flag "your move" without running the full `?section=duels` setup.

### Challenges vs. the core podium-betting game

CP is a separate scoreboard — it never converts into podium-betting pool points. A participant who wants to compete for the actual pool needs to become a **full member**: request promotion from their profile (`ch_promote_*`), an admin approves it on the Members tab, which links `challenge_participants.core_user_id` to a **new** `users` row (`points=0` — nothing carries over). Only that core `users` account plays the main game and pool.

---

## Content pipeline — how the games stay stocked

Rumor-or-Not cards and Trivia questions are drafted by Claude, not written by hand.

**Source material:** both generators read `paddock-rumors/data/knowledge-base.json` read-only (~95 factual docs for the current season, shared with the `f1-intelligence` RAG feed) and track which doc IDs they've already used in a **per-environment** state file, `bin/state/{rumor,trivia}-generator-state.<env>.json` — test and live track usage independently so the same KB can be fully drawn on each without one starving the other.

**Generation:** `bin/generate-rumor-items.js` / `bin/generate-trivia-questions.js` call Claude (`claude-sonnet-5`) once per item — roughly half real / half invented-but-plausible per Rumor-or-Not batch, one multiple-choice question per Trivia item — and require a strict bilingual (DA/EN) JSON response. A single malformed Claude response is skipped, not fatal to the batch (each item generates inside its own try/catch).

**Import:** successfully-drafted items POST in one batch to `tools/import-rumor-drafts.php` / `tools/import-trivia-drafts.php` (Bearer `INTEGRATION_SEED_TOKEN`). The endpoints default to `status='draft'`, but the automated pipeline sends `"status":"published"` (the generator's `--publish` flag) so items are **inserted already live**. Each item carries a `publish_date` set to the **upcoming Monday** (computed by the generator, Europe/Copenhagen); the rumor import now writes `publish_date` explicitly — the column is `DATE NULL` with no default, and a published rumor is invisible until `publish_date <= today`, so a NULL would silently unpublish it.

**Schedule:** `.github/workflows/cron-content-topup.yml`, Fridays 06:00 UTC, **targeting both test and live** and **auto-publishing** (a deliberate product decision — a fully unattended weekly content drop, reversing the old drafts-on-test-only posture). Stamping the batch with the upcoming Monday means it goes live Monday 00:00 and, for trivia, is playable that whole ISO week. Rumors and Trivia generate as separate parallel GitHub Actions jobs, each fanning out over a test/live matrix (`fail-fast: false`), so one failing/timing out doesn't discard another's progress; because the jobs push their per-env KB-usage state to the same branch at once, each commit step rebase-and-retries (up to 8×) so a later pusher isn't lost to a non-fast-forward rejection (that state is the only thing stopping a doc being redrawn — the import is a plain INSERT, and a redraw now means duplicate *published* content). Job-level concurrency groups (`content-topup-<env>-<generator>`) keep a manual dispatch from overlapping the Friday schedule on the same env+generator. A manual `workflow_dispatch` can target a single `environment`, pick `count` (items per game, default 6), `target` (`both`/`rumors`/`trivia`, useful for re-running just one generator after a partial failure), and `publish` (`false` for a drafts-only preview you review by hand).

**Publishing (now fully automated — no weekly human step):**

1. Friday 06:00 UTC — the generators run for **both test and live**, drafting ~6 rumor cards + ~6 trivia questions per environment and importing them as `status='published'`, each dated the **upcoming Monday**.
2. Monday 00:00 — the batch becomes player-visible on both environments (rumors once `publish_date <= today`; trivia enters its playable ISO week). No admin review or Publish click is involved. The `admin-challenges.php` Rumors/Trivia tabs (bulk publish/unpublish/delete, All/Drafts/Published filters, etc.) remain for **manual correction** — e.g. deleting a bad auto-published item, or reviewing a `publish=false` preview run — but are no longer part of the routine weekly flow.
3. Monday 05:00 UTC — `cron-challenges.yml` fires `challenge_weekly.php`: Perfect Week bonuses for the week that just ended, plus the GDPR purge.

Because the content ships unreviewed, the **quality gate is after the fact**: malformed Claude JSON is skipped per-item, but a factually-wrong rumor or a mis-keyed trivia answer reaches players until someone deletes it on `admin-challenges.php`. Spot-checking the live tabs periodically is the mitigation.

**Content exhaustion — the failure mode to watch for.** The KB has under 100 docs, and each environment now draws from it independently (per-env state files). After a few months of sustained weekly runs an environment's unused pool runs out; that env's generator then hard-fails with `"Only N unused KB docs left, need M"` instead of silently generating less. **A failed matrix job in `cron-content-topup.yml` is the signal** (per environment — test and live exhaust separately) — check GitHub Actions. Fix by growing the `paddock-rumors/` KB (re-run its own `update-kb.js` pipeline) or, in future, allowing doc reuse after a cooldown (not implemented yet).

**Blind spot to know about:** `nextRumorItem()` / `nextTriviaQuestion()` (`challenges.php`) both return `null` cleanly when there's nothing left — the UI shows the same pleasant empty state whether a player has genuinely answered everything, or there was never any published content for the period. Trivia is the sharper case: `ch_all_caught_up` covers both "you finished this week's quiz" and "zero questions were published this week" with identical copy. A player screenshot alone can't tell you which — check the actual counts on `admin-challenges.php`.

---

## Regular admin duties (checklist)

1. **No weekly publish step** — `cron-content-topup.yml` auto-publishes a fresh batch to both test and live every Friday, dated the upcoming Monday. Optional: **spot-check** the newly live items on `admin-challenges.php` (Rumors + Trivia tabs) and delete anything wrong, since content ships unreviewed.
2. **Watch for `cron-content-topup.yml` failures** in GitHub Actions — that's both the KB-running-low signal (per environment) *and* the "no content got published this week" signal, not a UI symptom.
3. **As requests come in:** Members tab — approve/reject promotion requests (guest → full member); toggle `in_competition` for converted guests. The tab also carries a **full participant roster** (all `challenge_participants`: guests, native-core, promoted) showing email / display name / language / status / created / promotion-requested, with per-row **Delete** and a multiselect **bulk delete** (`delete_participant` / `bulk_delete_participants`, same bulk wiring as the Rumors/Trivia tabs). Deleting a participant cascades its challenge data (CP ledger, answers, duels, tokens) but **never** removes a promoted member's linked core `users` account — the `core_user_id` FK is `ON DELETE SET NULL` on the users side, so only the challenge-side row goes. Use the core admin Users tab to remove an actual account.
4. **As needed:** Suppressions tab — monitor/manage the email opt-out list (governs Duel "challenge a friend" invites).
5. **Duels tab is oversight-only for resolution** — duels resolve themselves off race results, no admin action required to settle them. The list has a **created-date sort toggle** (`?duel_sort=newest|oldest`, newest default). The tab does allow **deleting** duels (per-row **Delete** + multiselect **bulk delete**, `delete_duel` / `bulk_delete_duels`) for cleanup of test/bad rows; a delete also removes that duel's awarded CP (`challenge_points` rows keyed `source_ref = "duel:<id>"`, both sides), the same cleanup `resetDuelsForRace()` does — the CP ledger has no FK to `duels`, so it must be cleared explicitly. Duel predictions cascade via their FK.
6. **Emergency/manual top-up** outside the Friday schedule: run `bin/generate-rumor-items.js` / `generate-trivia-questions.js` locally (needs `ANTHROPIC_API_KEY`), or trigger `cron-content-topup.yml` manually via `workflow_dispatch`.

---

## Related docs

- `docs/architecture.md` — "Home Page Hero (Paddock Challenges)" and "Admin — Paddock Challenges Control Room" sections.
- `docs/github-actions.md` — "Content Top-up Workflow" section; authoritative on the cron schedule and manual-dispatch inputs.
- `docs/paddock-rumors-reference.md` — the separate KB-building pipeline that feeds both `f1-intelligence` chat and this content generator. Don't confuse the two.
