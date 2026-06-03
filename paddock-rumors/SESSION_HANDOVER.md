# Paddock Rumors — Session Handover

> **Point-in-time snapshot, last updated 2026-06-03 (eve of Monaco R6).** This
> captures session state (what's done vs pending), not permanent architecture.
> Verify "current status" claims against the actual repo and `git log` before
> trusting them — this file goes stale. Durable docs live in `README.md`,
> `SCHEDULING.md`, `ROADMAP.md`, and the Paddock Rumors section of the root
> `CLAUDE.md`.

## What this is
A content-generation pipeline (`paddock-rumors/`) that auto-builds a tagged F1 knowledge base for the Paddock Picks prediction game. It runs **parallel to and isolated from** the existing live `f1-intelligence/` RAG system — that Phase 1 setup was never touched. Default operating mode is **Mode 1 (generate-only)**: commits content to git, no Vercel/live-API integration.

## Current status: Phase B live, caught up, monitoring
- KB has **86 docs** for 2026 R1–R5 (verified): race ×5, qualifying ×5, driver ×22, analysis ×51, sprint ×3 (R2/R4/R5). `data/` ↔ `public/paddock-rumors/` confirmed in sync.
- Pipeline is **fully caught up**: R5 Canada (2026-05-24) is the last completed round and is fully in the KB. R6 Monaco is 2026-06-07. No backfills outstanding.
- GitHub Actions cron **active** (workflow ID 288044984) in Mode 1, committing to `main`. Last 3 runs all succeeded; the Tue analysis-window run correctly ran as a no-op.
- `ANTHROPIC_API_KEY` set as GitHub Actions secret (reused from f1-intelligence)
- **Phase C (live-API integration via `upgrades/`) NOT started** — requires explicit user sign-off, show diffs first

### Pre-flight verified (2026-06-03, eve of R6)
- Schedule decider (`schedule.js`) run against real calendar/state: `latestFinishedRound=5`, summary "nothing to do" — correct.
- R6 fetch probes: quali/sprint/pit return empty gracefully; `getRaceResults(R6)` throws "No race found" **by design**. Safe because `getLatestFinishedRound` is results-driven (`/last/results.json`), so R6 only enters the tier-1 loop once its results publish — the throw is never hit prematurely. Monaco weekend cron is sound.

## What was built this session
**Pipeline features:**
- Qualifying docs (`type: qualifying`) — full 20-driver grid
- Race docs — expanded to full grid + pit stop data (`getPitStops`)
- Driver docs — `TOP_N` raised 10→20 (all drivers)
- Sprint docs (`type: sprint`) — sprint qualifying grid (from `grid` field) + sprint race result. 2026 sprint rounds: **R2, R4, R5, R9, R12, R16**
- Model default fixed to `claude-sonnet-4-6`; upfront API-key check added

**Backfill scripts (one-off):** `backfill-qualifying.js`, `backfill-races.js` (`--force`), `backfill-drivers.js`, `backfill-sprints.js`, `backfill-enrichment.js` (F1Technical, with pass-2 for series-only articles — never SKIPs F1MATHS/F1TECH)

**Inspection/query tools:**
- `paddock-rumors/query.js` — CLI keyword search
- `public/paddock-rumors/test.php` — admin KB inspector (reads synced JSON)
- `public/paddock-rumors/query.php` — admin query page; markdown rendering + session history. Calls a **dedicated Vercel project** (`paddock-rumors/api/query.js` + `health.js`), key in Vercel env. URL in `config.shared.php` as `PADDOCK_RUMORS_API_URL`.
  - **KB sync (fixed 2026-06-03):** the Vercel functions now **fetch `knowledge-base.json` from GitHub raw at runtime** (5-min warm cache), so the query API always reflects the latest committed KB — **no Vercel redeploy needed after cron commits**. Previously the KB was bundled at deploy time and went stale (the bug that surfaced: deployed API stuck at 53 docs while the repo had 86). `KB_RAW_URL` env var overrides the source. A Vercel redeploy is now only needed when the **function code** changes, not when the KB changes.

**Cron schedule (all UTC):** Sat 12,13,14,15,16,18 (sprint+quali) · Sun 16,18,20,22 + Mon 0–14 (results) · Tue/Wed 18, Thu 12 (analysis). Manual `workflow_dispatch` with `force_quali`, `tier1_only` inputs.

## Key conventions / gotchas
- **Never modify `f1-intelligence/` or `public/f1-intelligence/`** without explicit user approval
- Jolpica API has transient 504s — retry logic added in `fetch-results.js`
- Sprint qualifying (shootout) NOT in Jolpica; only GP qualifying + sprint race results
- Two consumers of the KB, kept in sync differently:
  - `test.php` (KB inspector) reads `public/paddock-rumors/knowledge-base.json` on the **PHP server** → needs `cp data/ → public/` + `npm run deploy:test` (cron does the cp automatically on its commits)
  - `query.php` (query API) reads the KB via the **Vercel function**, which now pulls from **GitHub raw at runtime** → a `git push` alone makes new KB live within ~5 min, no deploy step
- After a **local** backfill, do: `cp data/knowledge-base.json public/paddock-rumors/` → commit → push → `npm run deploy:test` (covers both consumers)
- Correct test domain is `hpovlsen.dk` (typo `hpovslen.dk` was fixed everywhere)
- All commits end with `Co-Authored-By: Claude ...`

## Likely next steps
- Monitor Monaco (R6) weekend — first real cron test. Quali window Sat 2026-06-06, results window Sun 2026-06-07 → Mon.
- **Backfills mostly done, one gap:** sprint docs (R2/R4/R5), full-grid race/quali/driver docs, and F1Technical analysis are all committed. **Pit-stop data is in R1–R3 race docs but NOT R4/R5** (those race docs predate the pit-stop feature and were never regenerated). To fix: `ANTHROPIC_API_KEY=... node backfill-races.js --force` (regenerates all 5 race docs with pit stops), then `cp data/knowledge-base.json public/paddock-rumors/` + commit + push. (The earlier handover claim that "every R1–R5 race doc contains pit-stop detail" was wrong.)
- After 2–3 good weekends: decide on Phase C integration (show `upgrades/` diffs first)
