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
├── challenges.php              hub — ?section=overview|rumors|duels|trivia
├── challenges-rules.php        player-facing "how it works" page
├── challenges-join.php         guest onboarding (magic-link email)
├── challenges-verify.php       consumes magic-link / invite token
├── challenges-profile.php      guest/verified participant account page
├── challenges-board.php        public CP leaderboard, no auth
├── challenges-invite.php       "beat my score" friend-invite (email)
├── challenges-optout.php       HMAC-verified email unsubscribe
├── admin-challenges.php        admin control room (?tab=members|rumors|trivia|duels|suppressions)
├── cron/challenge_weekly.php   Monday cron: Perfect Week bonus + GDPR purge
├── tools/import-rumor-drafts.php    HTTP import endpoint (Bearer token), called by bin/generate-rumor-items.js
├── tools/import-trivia-drafts.php   HTTP import endpoint (Bearer token), called by bin/generate-trivia-questions.js
└── includes/
    ├── challenges.php          all shared model/helper functions (scoring, streak, CP, duel pairing/resolution)
    └── admin-challenges/{members,rumors,trivia,duels,suppressions}.php   per-tab admin partials

bin/
├── generate-rumor-items.js     drafts Rumor-or-Not cards from paddock-rumors KB, via Claude
├── generate-trivia-questions.js drafts Trivia questions from paddock-rumors KB, via Claude
└── state/{rumor,trivia}-generator-state.json   which KB docs are already used (committed back to repo)

.github/workflows/
├── cron-challenges.yml         Monday 05:00 UTC — triggers cron/challenge_weekly.php
└── cron-content-topup.yml      Friday 06:00 UTC — runs both bin/generate-*.js scripts, always against TEST unless manually dispatched to live
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
`public/admin.php`'s `update_race` handler calls `calculateRacePoints()` (core scoring) then immediately `resolveDuelsForRace()` (`includes/challenges.php`) — explicitly additive/isolated from core scoring. Per duel: `+5 CP` per driver in the exact right position, `+2 CP` for a driver placed in the top 3 but the wrong slot. Winner gets `15 CP`, loser `5 CP`, a tie pays `10/10`. If either side never locked in a pick before the race started, the duel is voided — no CP either way. `reset_race_result` calls the mirror function `resetDuelsForRace()`, which deletes the matching `challenge_points` rows and reverts non-void duels to `active` so re-entering results resolves cleanly.

**Streaks & leaderboard** (`includes/challenges.php`): `getChallengeStreak()` counts consecutive calendar days with *any* CP-earning action across all three games (recomputed live, not stored — miss a day and it resets going forward). `getChallengeCpTotal()` is an all-time sum of the `challenge_points` ledger, no time window. `getCpLeaderboard()` ranks all `status='verified'` participants (guests and full members together) by total CP, ties broken by earliest `created_at`.

### Challenges vs. the core podium-betting game

CP is a separate scoreboard — it never converts into podium-betting pool points. A participant who wants to compete for the actual pool needs to become a **full member**: request promotion from their profile (`ch_promote_*`), an admin approves it on the Members tab, which links `challenge_participants.core_user_id` to a **new** `users` row (`points=0` — nothing carries over). Only that core `users` account plays the main game and pool.

---

## Content pipeline — how the games stay stocked

Rumor-or-Not cards and Trivia questions are drafted by Claude, not written by hand.

**Source material:** both generators read `paddock-rumors/data/knowledge-base.json` read-only (~95 factual docs for the current season, shared with the `f1-intelligence` RAG feed) and track which doc IDs they've already used in `bin/state/{rumor,trivia}-generator-state.json`.

**Generation:** `bin/generate-rumor-items.js` / `bin/generate-trivia-questions.js` call Claude (`claude-sonnet-5`) once per item — roughly half real / half invented-but-plausible per Rumor-or-Not batch, one multiple-choice question per Trivia item — and require a strict bilingual (DA/EN) JSON response. A single malformed Claude response is skipped, not fatal to the batch (each item generates inside its own try/catch).

**Import:** successfully-drafted items POST in one batch to `tools/import-rumor-drafts.php` / `tools/import-trivia-drafts.php` (Bearer `INTEGRATION_SEED_TOKEN`), which insert them as **`status='draft'`, always** — nothing is ever auto-published.

**Schedule:** `.github/workflows/cron-content-topup.yml`, Fridays 06:00 UTC. The scheduled run **always targets TEST**, never live (deliberate — matches the `deploy:live` "explicit human directive" convention). Rumors and Trivia generate as two separate parallel GitHub Actions jobs so one failing/timing out doesn't discard the other's progress. A manual `workflow_dispatch` can target `environment=live`, pick `count` (items per game, default 6), and `target` (`both`/`rumors`/`trivia`, useful for re-running just one generator after a partial failure).

**Publishing (the part that needs a human every week):**
1. Friday 06:00 UTC — ~6 rumor drafts + ~6 trivia drafts land on **TEST** as `status='draft'`.
2. Before Monday — review them on `admin-challenges.php` → Rumors / Trivia tabs. Edit anything that needs fixing (editing never changes status), veto/delete anything bad, and click **Publish** on the ones that are good (`quick_publish_rumor_item` / `quick_publish_trivia_question` — a plain status flip, nothing else). Rumors can also be unpublished after the fact; Trivia can only be deleted.
3. **This only populates TEST.** Getting the same content onto **live** needs either a manual `workflow_dispatch` with `environment=live`, or manually re-publishing the equivalent items on live's own `admin-challenges.php`. Monday's automation does not do this for you.
4. Monday 05:00 UTC — `cron-challenges.yml` fires `challenge_weekly.php`: Perfect Week bonuses for the week that just ended, plus the GDPR purge.

**Content exhaustion — the failure mode to watch for.** The shared KB has under 100 docs. After a few months of sustained weekly runs it will run out; the generators then hard-fail with `"Only N unused KB docs left, need M"` instead of silently generating less. **A failed `cron-content-topup.yml` run is the signal** — check GitHub Actions. Fix by growing the `paddock-rumors/` KB (re-run its own `update-kb.js` pipeline) or, in future, allowing doc reuse after a cooldown (not implemented yet).

**Blind spot to know about:** `nextRumorItem()` / `nextTriviaQuestion()` (`challenges.php`) both return `null` cleanly when there's nothing left — the UI shows the same pleasant empty state whether a player has genuinely answered everything, or there was never any published content for the period. Trivia is the sharper case: `ch_all_caught_up` covers both "you finished this week's quiz" and "zero questions were published this week" with identical copy. A player screenshot alone can't tell you which — check the actual counts on `admin-challenges.php`.

---

## Regular admin duties (checklist)

1. **Weekly, before Monday:** review and publish Friday's drafts on `admin-challenges.php` (Rumors + Trivia tabs) — on test, and separately on live if you want it there too.
2. **Watch for `cron-content-topup.yml` failures** in GitHub Actions — that's the KB-running-low signal, not a UI symptom.
3. **As requests come in:** Members tab — approve/reject promotion requests (guest → full member); toggle `in_competition` for converted guests.
4. **As needed:** Suppressions tab — monitor/manage the email opt-out list (governs Duel "challenge a friend" invites).
5. **Duels tab is read-only** — duels resolve themselves off race results, no admin action required.
6. **Emergency/manual top-up** outside the Friday schedule: run `bin/generate-rumor-items.js` / `generate-trivia-questions.js` locally (needs `ANTHROPIC_API_KEY`), or trigger `cron-content-topup.yml` manually via `workflow_dispatch`.

---

## Related docs

- `docs/architecture.md` — "Home Page Hero (Paddock Challenges)" and "Admin — Paddock Challenges Control Room" sections.
- `docs/github-actions.md` — "Content Top-up Workflow" section; authoritative on the cron schedule and manual-dispatch inputs.
- `docs/paddock-rumors-reference.md` — the separate KB-building pipeline that feeds both `f1-intelligence` chat and this content generator. Don't confuse the two.
