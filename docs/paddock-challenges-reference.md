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
├── tools/get-content-batch-size.php     read-only: admin-configured rumor_batch_size/trivia_batch_size
├── tools/promote-content-drafts.php     promotes oldest drafts to published (backlog-first, see below)
└── includes/
    ├── challenges.php          all shared model/helper functions (scoring, streak, CP, duel pairing/resolution)
    └── admin-challenges/{members,rumors,trivia,duels,suppressions}.php   per-tab admin partials
        (members = promotion queue + converted guests + full participant roster with delete)

bin/
├── generate-rumor-items.js     drafts Rumor-or-Not cards from paddock-rumors KB, via Claude
├── generate-trivia-questions.js drafts Trivia questions from paddock-rumors KB, via Claude
├── lib/content-review.js       pre-import fact-check + Danish-translation review pass (see below)
└── state/{rumor,trivia}-generator-state.<env>.json   which KB docs are already used, per environment (committed back to repo)

.github/workflows/
├── cron-challenges.yml         Monday 05:00 UTC — triggers cron/challenge_weekly.php
└── cron-content-topup.yml      Friday 06:00 UTC — runs both bin/generate-*.js against BOTH test and live, auto-publishing a batch dated the upcoming Monday
```

Schema: `database/schema.sql` lines 228–419 — `challenge_participants`, `challenge_points` (append-only CP ledger), `challenge_magic_links`, `challenge_access_tokens`, `challenge_invites`, `challenge_email_suppressions`, `challenge_items`, `challenge_answers`, `duels`, `duel_quickmatch`, `duel_predictions`, `challenge_trivia_questions`, `challenge_trivia_answers`. `database/add_content_archival.sql` (2026-07-27) adds `'archived'` to `challenge_items.status`/`challenge_trivia_questions.status` — see "Content expiry & archival" below.

---

## Scoring — how each game awards CP

All CP awards go through `awardChallengePoints($db, $participantId, $game, $points, $sourceRef)` (`public/includes/challenges.php`), which inserts into `challenge_points`. `source_ref` is a unique idempotency key per award — a duplicate call (double-submit, cron re-run) is a silent no-op, never a double-award.

**Rumor or Not — instant, per-answer, no cron.**
POST handler in `challenges.php` (`?section=rumors`). Correct guess → `+10 CP`, `source_ref = "rumor_or_not:$itemId"`. One answer per item (`UNIQUE(participant_id, item_id)`); unanswered items roll forward indefinitely — no expiry.

**Weekly Trivia — instant per-answer, plus a batched weekly bonus.**
Correct answer → `+5 CP` instantly, `source_ref = "trivia:$questionId"`. Unlike Rumors, trivia is scoped strictly to the current ISO week (`YEARWEEK(publish_date, 3)`) — it does **not** roll over; a week with no answers just ends. The **Perfect Week bonus** (`+20 CP`, `source_ref = "trivia_week:$isoWeek"`) is computed by `public/cron/challenge_weekly.php`, triggered by `.github/workflows/cron-challenges.yml` (Monday 05:00 UTC / 06:00 CET, Bearer `CRON_SECRET`). It finds participants who answered every question published in the previous ISO week correctly. The same cron also purges `challenge_participants` still `status='pending'` after 30 days (GDPR), cascading to their child rows.

**Duels — synchronous, triggered by the admin saving race results, not a cron.**
A duel starts either from **Quick Match** (queued and paired with the oldest other waiting participant for the same race) or **Challenge a friend** (`searchChallengeParticipants()` on display name). You can never duel yourself — the search filters out your own row, the `challenge_friend` POST handler rejects `opponent_id == you`, and `createDirectDuel()` throws if challenger and opponent are equal. Because a display name is usually just a first name and rarely unique, each search result also shows a **masked email hint** (`maskEmailForSearch()`: first couple of local-part chars + domain, middle redacted, e.g. `th•••g@gmail.com`) to disambiguate same-named people — the raw address is never sent to the client.

**Quick Match queue visibility** (`getQuickMatchPosition()` in `includes/challenges.php`): while waiting to be paired, `?section=duels` shows a persistent "you're #N in line" banner (`data-testid="duel-queued-msg"`) — 1-based position among that race's `duel_quickmatch` rows, oldest first, ties on `created_at` broken by `participant_id`. It's read fresh from the DB on every load (not a one-shot flash off a redirect flag), so it disappears the moment pairing actually happens, including the DUEL-06 concurrent-pairing case. The admin **Duels tab** (`?tab=duels`) shows the same queue, all races, one panel above the paired-duel oversight list — position, name/email, race, and time queued. REQ-302 says unmatched requests "expire" at race start, but nothing actually deletes the queue row if the race window closes before a second participant joins; the admin panel flags those with an "expired"/race-started badge (`isDuelRaceLocked()`) since they'll never pair on their own, but nothing currently removes them — cleanup, if wanted, is a manual DB operation for now.

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

**Schedule:** `.github/workflows/cron-content-topup.yml`, Fridays 06:00 UTC, **targeting both test and live** and **auto-publishing** (a deliberate product decision — a fully unattended weekly content drop, reversing the old drafts-on-test-only posture). Stamping the batch with the upcoming Monday means it goes live Monday 00:00 and, for trivia, is playable that whole ISO week. Rumors and Trivia generate as separate parallel GitHub Actions jobs, each fanning out over a test/live matrix (`fail-fast: false`), so one failing/timing out doesn't discard another's progress; because the jobs push their per-env KB-usage state to the same branch at once, each commit step rebase-and-retries (up to 8×) so a later pusher isn't lost to a non-fast-forward rejection (that state is the only thing stopping a doc being redrawn — the import is a plain INSERT, and a redraw now means duplicate *published* content). Job-level concurrency groups (`content-topup-<env>-<generator>`) keep a manual dispatch from overlapping the Friday schedule on the same env+generator. A manual `workflow_dispatch` can target a single `environment`, `target` (`both`/`rumors`/`trivia`, useful for re-running just one generator after a partial failure), `publish` (`false` for a drafts-only preview you review by hand), and optionally `count` to override the batch size for that one run.

**Batch size — admin-configurable, per environment, per game.** How many items each generator drafts per Friday run is no longer hardcoded — it's the `rumor_batch_size`/`trivia_batch_size` columns on the `settings` table (`database/add_content_batch_size.sql`), editable on `admin.php?tab=settings` → Recap Carousel section → "Weekly Content Batch Size" (default 6 each, same as the old hardcoded value). Since test and live are separate databases, this setting is naturally per-environment — no cross-environment coordination needed. The workflow itself has no DB access, so each rumors/trivia×env job fetches its own environment's value over HTTP from `public/tools/get-content-batch-size.php` (Bearer-token auth, same `INTEGRATION_SEED_TOKEN` already trusted for the import endpoints below — this just adds a read) immediately before generating, falling back to `6` if that fetch fails for any reason. A manual `workflow_dispatch` `count` input still overrides this and skips the fetch entirely — leave it blank (the schedule always does) to use the admin-configured value.

**Draft backlog is drawn down first, before any Claude call.** Both environments carry a
sizeable backlog of unused `status='draft'` rows — leftovers from old `publish=false` preview
runs and the pre-automation "drafts on test, human publishes" era. Each rumors/trivia×env job
now calls `public/tools/promote-content-drafts.php` (same Bearer auth as the endpoints above)
*before* generating: it promotes up to `batch_size` of the oldest existing drafts to
`published`, stamped with the same upcoming-Monday `publish_date` a freshly-drafted item would
get, so promoted rows land in the same weekly batch indistinguishably. Only the shortfall
(`batch_size` minus however many were promoted) still calls Claude — if the backlog alone
covers the full batch, generation is skipped for that job that week. This is gated to
`publish=true` runs only (a `publish=false` preview run never touches real drafts) and never
touches `bin/state/{rumor,trivia}-generator-state.<env>.json`, since that file tracks which
*knowledge-base docs* have been drawn for generation — orthogonal to which existing rows get
published. Practically: this paces out the existing backlog at the normal weekly cadence
instead of it sitting inert, and defers KB exhaustion (see below) for as long as backlog lasts.

**Publishing (now fully automated — no weekly human step):**

1. Friday 06:00 UTC — the generators run for **both test and live**, drafting ~6 rumor cards + ~6 trivia questions per environment and importing them as `status='published'`, each dated the **upcoming Monday**.
2. Monday 00:00 — the batch becomes player-visible on both environments (rumors once `publish_date <= today`; trivia enters its playable ISO week). No admin review or Publish click is involved. The `admin-challenges.php` Rumors/Trivia tabs (bulk publish/unpublish/delete, All/Drafts/Published filters, per-item answer count + correct-rate, etc.) remain for **manual correction** — e.g. deleting a bad auto-published item, or reviewing a `publish=false` preview run — but are no longer part of the routine weekly flow.
3. Monday 05:00 UTC — `cron-challenges.yml` fires `challenge_weekly.php`: Perfect Week bonuses for the week that just ended, plus the GDPR purge.

**Pre-import review pass.** After drafting, each card/question gets one more Claude call —
`reviewDraft()` in `bin/lib/content-review.js` — that checks it against the exact rubric documented
in the `f1-data-validation` and `motorsport-en-da-translator` skills: a fact-check against the same
KB doc it was grounded on (for a fabricated rumor card, `is_real=false`, this checks
`explain_da/explain_en` instead of `text_da/text_en`, since the claim itself is *supposed* to be
false), and a Danish translation-quality check against the project's established glossary/register.
A flagged item imports as `status='draft'` regardless of the batch's `--publish` flag (a per-item
override in `import-{rumor,trivia}-drafts.php`, `item.status === 'draft'` beats the batch default —
never the other way round), so it lands inert on `admin-challenges.php` for a human to fix or
delete instead of shipping straight to players. The review call itself fails **open**: if it errors
or returns unparsable JSON, the item ships as originally intended and a warning is logged — a
reviewer hiccup must not silently mass-downgrade a whole Friday batch.

This narrows the gap but doesn't close it: the check is doc-grounded only (catches Claude
misreading/embellishing the KB doc it was given), not a live formula1.com/Jolpica cross-check — a
bad or stale KB doc itself, or a fabricated rumor that coincidentally turns out true, still slips
through. **Spot-checking the live tabs periodically (or running the `f1-data-validation` skill
directly against recent content) remains the deeper mitigation.**

**Content exhaustion — the failure mode to watch for.** The KB has under 100 docs, and each environment now draws from it independently (per-env state files). After a few months of sustained weekly runs an environment's unused pool runs out; that env's generator then hard-fails with `"Only N unused KB docs left, need M"` instead of silently generating less. **A failed matrix job in `cron-content-topup.yml` is one signal** (per environment — test and live exhaust separately) — check GitHub Actions. As of 2026-07-27 there's also a proactive one: the **Content Supply** panel on `admin-dashboards.php?tab=challenges` shows estimated weeks of KB runway remaining per generator for this environment (see "Content expiry & archival" below) — no need to wait for a failed run to notice, and check both hosts separately since each only ever shows its own. Fix by growing the `paddock-rumors/` KB (re-run its own `update-kb.js` pipeline) or, in future, allowing doc reuse after a cooldown (not implemented yet).

**Blind spot to know about:** `nextRumorItem()` / `nextTriviaQuestion()` (`challenges.php`) both return `null` cleanly when there's nothing left — the UI shows the same pleasant empty state whether a player has genuinely answered everything, or there was never any published content for the period. Trivia is the sharper case: `ch_all_caught_up` covers both "you finished this week's quiz" and "zero questions were published this week" with identical copy. A player screenshot alone can't tell you which — check the actual counts on `admin-challenges.php`.

**Per-item usage:** the Rumors/Trivia tabs on `admin-challenges.php` show an answer count + correct-rate per item/question (e.g. "3 answers · 67% correct" / "No answers yet"), aggregated live from `challenge_answers` / `challenge_trivia_answers`. This is the only per-item usage view — the Dashboards area's Challenges tab (`admin-dashboards.php?tab=challenges`) only has game-level aggregates (players, 7-day plays, correct %), not a per-item breakdown.

---

## Content expiry & archival — stale content rotates out automatically

Added 2026-07-27 (`epics/Real expiry & rotation for content-topup/`). Since the content pipeline
above never expired anything, `challenge_items`/`challenge_trivia_questions` grew unbounded —
every Friday's batch stacked on top of the last, forever. Both tables' `status` enum gained a
third value, `'archived'` (migration `database/add_content_archival.sql`), alongside
`'draft'`/`'published'`. Archiving is a pure status flip — **never a row delete** — so
`challenge_answers`/`challenge_trivia_answers`/`challenge_points` and all CP history stay intact
forever regardless of archival, and the Perfect Week denominator can't be affected by it either.

`archiveStaleContent($db)` (`public/includes/challenges.php`) runs every Monday from the existing
`challenge_weekly.php` cron (`.github/workflows/cron-challenges.yml`), immediately after the
Perfect Week bonus + GDPR purge — no new scheduled workflow:

- **Trivia**: a question becomes eligible once its ISO week is 2+ full weeks in the past.
  `nextTriviaQuestion()` already scopes serving to the *current* week only (see "Scoring" above),
  so archiving an elapsed week can never remove anything still playable.
- **Rumor**: an item becomes eligible past `RUMOR_STALE_WEEKS` (6, constant near the function) —
  but archiving never drops the published-and-in-window count below `RUMOR_MIN_LIVE` (6). The
  floor guard (`rumorArchiveBudget()`, pure/unit-tested) codifies in code what used to be a manual
  "would this leave the live deck empty" check an admin had to remember to do by hand before ever
  unpublishing anything. If the guard blocks a run, nothing is archived that run — surfaced as a
  flag on the Content Supply panel below, never failed silently.

**Manual admin control:** `admin-challenges.php`'s Rumors and Trivia tabs each gained an
**Archive** action (per-row and bulk) and a **Restore** action (archived → draft, for a mistaken
archive — not straight back to live, so it goes through Publish again for review), plus an
"Archived" option on the existing All/Drafts/Published status filter.

**Content Health visibility — `admin-dashboards.php?tab=challenges`.** A second panel, **Content
Supply**, sits below the existing player-usage panel (`chGetContentHealthSnapshot()` in
`challenges-usage-lib.php`): live/archived counts per game, the rumor guard-blocked flag, and two
things that previously required checking GitHub Actions or running ad-hoc SQL by hand —

- **Batch cadence**, this environment only (cross-environment cleanup, 2026-07-31 — this panel
  used to show test and live side by side, the one exception to the rest of the admin area's
  single-environment rule): last `cron-content-topup.yml` run's status for this instance's own
  `APP_ENV`, reusing the existing cached GitHub Actions run data (`ghListWorkflowRunsMulti()` — no
  new API calls). Needs the *job*-level result of the latest run (the workflow fans out into a job
  per generator per environment), not just the run-level status, since this environment's own
  failure/success must never be conflated with the other's — only the job(s) matching this
  environment's `(test)`/`(live)` name suffix are ever read.
- **KB runway**, one row per generator, this environment only: reads
  `bin/state/{rumor,trivia}-generator-state.<env>.json` for this instance's own `<env>` — the
  other environment's file is never opened. These files are committed by `cron-content-topup.yml`
  but live at the **repo root**, outside `public/` — `build-deploy/deploy.js` was extended in the
  content-topup epic to also upload them to `{remoteDir}/bin/state/` (same non-web-accessible
  placement as `config.php`) specifically so this panel could read them; before that change, the
  PHP process on the deployed server had no way to see this data at all. A missing/malformed file
  degrades to "Unknown," never a fabricated number or a fatal error. The weekly draw rate used for
  the estimate is the admin-configured `rumor_batch_size`/`trivia_batch_size` (see "Batch size"
  above) for this environment's own setting.
- The panel header carries a single "Test"/"Live" indicator (same `$envLabel` convention as Nøgler
  & Rotation) so which environment the numbers reflect is still explicit.

See `docs/admin-dashboards.md` for the panel's own reference entry, including why the GitHub
Actions dashboard itself was deliberately left showing both environments' job data (shared CI/CD
ops view, not per-environment app state — a scoping decision, not an oversight).

---

## Regular admin duties (checklist)

1. **No weekly publish step** — `cron-content-topup.yml` auto-publishes a fresh batch to both test and live every Friday, dated the upcoming Monday. Optional: **spot-check** the newly live items on `admin-challenges.php` (Rumors + Trivia tabs) and delete anything wrong, since content ships unreviewed.
2. **No weekly archival step either** — `challenge_weekly.php`'s Monday run also archives stale content automatically (see "Content expiry & archival" above). Only step in if the Content Supply panel flags the rumor guard as blocked (deck can't rotate safely) — that's a signal to generate more content sooner, not something to fix by hand.
3. **Check `admin-dashboards.php?tab=challenges`'s Content Supply panel** first for batch cadence and KB runway — it's the proactive view. `cron-content-topup.yml` failures in GitHub Actions are still the underlying signal if you need the raw run history, but the panel surfaces the same "overdue batch" / "KB running low" states without checking Actions by hand.
4. **As requests come in:** Members tab — approve/reject promotion requests (guest → full member); toggle `in_competition` for converted guests. The tab also carries a **full participant roster** (all `challenge_participants`: guests, native-core, promoted) showing email / display name / language / status / created / promotion-requested, with per-row **Delete** and a multiselect **bulk delete** (`delete_participant` / `bulk_delete_participants`, same bulk wiring as the Rumors/Trivia tabs). Deleting a participant cascades its challenge data (CP ledger, answers, duels, tokens) but **never** removes a promoted member's linked core `users` account — the `core_user_id` FK is `ON DELETE SET NULL` on the users side, so only the challenge-side row goes. Use the core admin Users tab to remove an actual account.
5. **As needed:** Suppressions tab — monitor/manage the email opt-out list (governs Duel "challenge a friend" invites).
6. **Duels tab is oversight-only for resolution** — duels resolve themselves off race results, no admin action required to settle them. The list has a **created-date sort toggle** (`?duel_sort=newest|oldest`, newest default). The tab does allow **deleting** duels (per-row **Delete** + multiselect **bulk delete**, `delete_duel` / `bulk_delete_duels`) for cleanup of test/bad rows; a delete also removes that duel's awarded CP (`challenge_points` rows keyed `source_ref = "duel:<id>"`, both sides), the same cleanup `resetDuelsForRace()` does — the CP ledger has no FK to `duels`, so it must be cleared explicitly. Duel predictions cascade via their FK. Above that list, the same tab's **Quick Match Queue** panel shows everyone still waiting to be paired (not yet a `duels` row) — read-only, no delete action; a row badged "expired" (race already locked) means it will never pair on its own and is worth a manual look.
7. **Emergency/manual top-up** outside the Friday schedule: run `bin/generate-rumor-items.js` / `generate-trivia-questions.js` locally (needs `ANTHROPIC_API_KEY`), or trigger `cron-content-topup.yml` manually via `workflow_dispatch`.

---

## Multi-week simulation harness (TEST only)

`bin/simulate-challenges.js` plays out N weeks of all three games end to end for a synthetic roster — real Claude-drafted content (backdated), real per-answer CP scoring, real Duel resolution off a real `update_race` submission, and the real weekly cron for Perfect Week bonuses. It's not a mock of the system; it drives the same code paths a live quarter of play would, compressed into one run. Useful for pre-launch rehearsal (Challenges has never been deployed to live — see the top of this doc) or after a scoring-relevant change, to see multi-week behavior (streaks, Perfect Weeks, KB draw-down) without waiting real weeks for it. Never touches LIVE.

### Running it

1. **Prerequisites**: `config.test.php` locally, and `ANTHROPIC_API_KEY_SIMULATION` in `build-deploy/.env` (gitignored — never commit it; see `.env.example`). This is a **dedicated** key — deliberately separate from the shared `ANTHROPIC_API_KEY` that `cron-content-topup.yml` / a manual `bin/generate-*.js` run use — so simulation runs don't share budget/usage with the real weekly content pipeline. If one isn't already saved from prior setup, generate one at console.anthropic.com → Settings → API Keys, named `paddock-challenges-simulation` so it's identifiable there too; the harness passes it to `bin/generate-*.js` as `ANTHROPIC_API_KEY` for that child process only, so those scripts don't need to know about the separate name. Tracked for rotation in the admin **Nøgler & Rotation** panel (`admin-dashboards.php?tab=keys`, record-mode) — see `docs/admin-dashboards.md`.
2. **Consider `npm run sync:live` first** if you want the simulation running against the real season calendar/results instead of whatever's currently on TEST. Order matters: sync **before** the simulation creates its roster, never after — `sync:live` unconditionally wipes `challenge_participants` (see gotcha #23), so running it afterward deletes everyone the simulation just seeded. Sanity-check the target races' dates with `list_races` (via `test-seed.php?action=list_races&token=...`) rather than assuming they're right — a synced race's stored date can be wrong (a real one was found stuck at a placeholder date during the first run of this harness; not a harness bug, just don't assume the calendar is trustworthy).
3. **Preflight**: `node bin/simulate-challenges-preflight.js` — admin login/CSRF, recon endpoints, and one tiny real (backdated to 2099 so it's never player-visible) content-generation call. Under a minute; catches a bad config/token/API key before the full run seeds a whole roster and starts spending real API credits.
4. **Full run**: `node bin/simulate-challenges.js`. Configured out of the box for Q1 2026 (Rounds 1-6, 9-person roster) — see its header comment for what to edit (`WEEKS`, `ROSTER`) to target a different quarter/season. Takes several minutes to ~15 (real sequential Claude calls per week, real HTTP round trips per answer/duel). Output: `bin/simulate-runs/<timestamp>/{log.jsonl,report.md,data.json}` (gitignored — the harness is checked in, its output isn't). `report.md` is the human-readable per-week write-up; `data.json` is what the verify/cleanup scripts below read.
5. **Verify**: `node bin/simulate-challenges-verify.js` (defaults to the latest run). Independently recomputes expected CP per participant from raw answer/duel-score data — rumor `correct×10`, trivia `correct×5 + 20×perfectWeeks`, duels from each `duel_predictions.score` pair via the documented win/loss/tie rule — and diffs against the actual `challenge_points` ledger. Real production scoring functions (`awardChallengePoints()`, `resolveDuelsForRace()`) were used throughout the run, which lowers risk of a scoring bug, but isn't the same as having checked; run this rather than trusting the report at face value.
6. **Cleanup, when you're done exploring the data on TEST**:
   - Content (Rumor/Trivia items — routinely 100+ rows, impractical via checkboxes): `node bin/simulate-challenges-cleanup.js` (defaults to the latest run's date range; `--dry-run` to preview, `--from=/--to=` to target a different range). Uses the real `admin-challenges.php` bulk-delete endpoints, not direct DB access, and verifies the actual row count afterward rather than trusting the HTTP response — see gotchas.md #24 for why that verification step exists.
   - Participants/core members/duels (small — one roster's worth): admin-challenges.php's Members and Duels tabs, checkboxes are fine here. A promoted core member's linked `users` row needs removing separately on the core admin Users tab — deleting the participant never cascades to it (`core_user_id` is `ON DELETE SET NULL`, by design).

### What supports it

A few `test-seed.php` actions were added specifically for this harness, reusable for other tooling: `seed_rumor_answer` / `seed_trivia_answer` (answer an arbitrary already-published item, awarding CP through the real `awardChallengePoints()` — distinct from the existing fixed-fixture `seed_challenge_answer`), `list_races` / `list_drivers` / `list_challenge_content` (read-only recon, so scripts don't need direct DB access), and `simulation_status` (per-participant CP/streak/raw-answer-count rollup, the verify script's data source). The generators (`bin/generate-{rumor,trivia}-*.js`) also gained an optional `--publish-date=YYYY-MM-DD` override, and `cron/challenge_weekly.php` an optional `?score_week=YYYY-MM-DD` — both no-ops unless passed explicitly, so the real Friday/Monday automation is unaffected.

---

## Related docs

- `docs/architecture.md` — "Home Page Hero (Paddock Challenges)" and "Admin — Paddock Challenges Control Room" sections.
- `docs/github-actions.md` — "Content Top-up Workflow" section; authoritative on the cron schedule and manual-dispatch inputs.
- `docs/paddock-rumors-reference.md` — the separate KB-building pipeline that feeds both `f1-intelligence` chat and this content generator. Don't confuse the two.
- `docs/gotchas.md` #22–23 — a stray E2E-seeded race hijacking `getNextDuelRace()` for every duels user, and what `npm run sync:live` clears (and deliberately doesn't) in `challenge_participants` and related tables.
