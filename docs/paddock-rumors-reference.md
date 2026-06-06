# Paddock Rumors — Content-Gen Pipeline Reference

**Location:** `paddock-rumors/` (NEW — parallel to `f1-intelligence/`, not nested inside it).

**Status:** In development. Default mode is isolated (writes only to `paddock-rumors/data/`, does NOT touch Phase 1).

---

## Purpose

Auto-updating F1 race knowledge, distilled from the paddock's analysis sources. Fetches structured race results from Jolpica-F1, has Claude synthesise neutral tagged race + driver documents, and optionally enriches with F1Technical analysis (F1MATHS, F1 TECH series). Designed to coexist with the existing `f1-intelligence/` Phase 1 setup.

---

## Relationship to f1-intelligence/

- **`f1-intelligence/`** — existing live RAG (Vercel API + PHP client on hpovlsen.dk / formula-1.dk). Already working. Untouched by Paddock Rumors.
- **`paddock-rumors/`** — content-generation layer. Produces a richer, tagged knowledge base. Default mode: writes to `paddock-rumors/data/knowledge-base.json` (fully isolated). Integrated mode: writes directly to the live KB. See integration paths below.

**IMPORTANT RULE:** Never modify anything inside `f1-intelligence/` or `public/f1-intelligence/` based on Paddock Rumors documentation unless the user explicitly approves a specific change. The `paddock-rumors/upgrades/` folder holds two files that *would* upgrade Phase 1, but applying them requires user sign-off.

---

## File Locations

```
f1betting/
├── f1-intelligence/                # ← Phase 1, do not modify without user OK
│   ├── api/                        
│   └── docs/
├── public/f1-intelligence/         # ← Phase 1 PHP client, do not modify
│   ├── F1Intelligence.php
│   └── test.php
│
└── paddock-rumors/                 # ← this system
    ├── README.md
    ├── ROADMAP.md                  integration paths (A: isolated, B: integrated, C: parallel)
    ├── SCHEDULING.md               per-source publishing windows + cron pattern
    ├── package.json
    ├── update-kb.js                main orchestrator
    ├── schedule.js                 per-round work-decider (pure logic)
    ├── fetch-results.js            Jolpica-F1 client
    ├── synthesise.js               Claude → qualifying + race + driver docs
    ├── enrich-f1technical.js       optional F1Tech enrichment (non-blocking)
    ├── query.js                    CLI keyword search for local KB inspection
    ├── backfill-enrichment.js      one-off F1Technical backfill from monthly archives
    ├── data/
    │   └── knowledge-base.json     generated content (default output)
    ├── state/
    │   └── last_processed_round.json
    └── upgrades/                   drop-in replacements for f1-intelligence/api/ files
        ├── README.md               when/how to apply (requires user OK)
        ├── build-index.js          tag-preserving, incremental
        └── intelligence.js         season-aware retrieval
```

---

## Document Schema (tagged)

```json
{
  "id": "race-2026-r05-gilles-villeneuve",
  "title": "Canadian Grand Prix 2026 — Race Result & Analysis",
  "content": "Season 2026, Round 5: ...",
  "tags": {
    "season": 2026,
    "type": "race",          // qualifying | race | driver | analysis | testing | evergreen
    "round": 5,
    "circuit": "...",
    "drivers_top10": ["..."]
  },
  "source_url": "...",
  "updated_at": "2026-05-25T08:00:00.000Z",
  "content_hash": "a1b2c3d4e5f60718"
}
```

Tags enable sophisticated filtering and seasonal relevance boosting in the retrieval layer.

---

## Scheduling

`paddock-rumors/` runs on a GitHub Actions cron (`.github/workflows/paddock-rumors.yml`) covering the qualifying window (Sat), post-race results window (Sun–Mon), and analysis publishing window (Tue–Thu). 

Per-source timing details in `paddock-rumors/SCHEDULING.md`:
- **Jolpica-F1** — results published ~2h post-race
- **F1Technical** — articles drop Tue–Wed weekly (season)
- **Other sources** — Motorsport.com, Autosport, The Race, RacingNews365 (asynchronous)

The workflow ships in **Mode 1 (generate-only)** — commits content to `paddock-rumors/data/`, does NOT touch `f1-intelligence/`, does NOT trigger Vercel deploys. To switch to Mode 2 (integrated), follow the comments at the top of the workflow file and `paddock-rumors/ROADMAP.md` Path B.

---

## Running Manually

```bash
cd paddock-rumors
npm install
export ANTHROPIC_API_KEY="sk-ant-..."

# Default — writes to ./data/knowledge-base.json, isolated
node update-kb.js

# Integrated — writes to ../f1-intelligence/api/data/f1-knowledge-base.json
# ⚠ Only do this AFTER applying upgrades/ — see ROADMAP.md
KB_OUTPUT_PATH=../f1-intelligence/api/data/f1-knowledge-base.json node update-kb.js
```

---

## Configuration

| Var | Default | Purpose |
|-----|---------|---------|
| `F1_SEASON` | `2026` | Current season. Bump when the calendar rolls over. |
| `KB_OUTPUT_PATH` | `./data/knowledge-base.json` | Where to write the KB. |
| `F1TECH_ENRICH` | `1` | `0` disables F1Tech enrichment. |
| `TOP_N_DRIVERS` | `10` | Per-driver doc count. |
| `FORCE_QUALI` | `false` | `true` re-synthesises the qualifying doc even if already done. |
| `ANTHROPIC_API_KEY` | — | Required. |

All env vars can be set in `.github/workflows/paddock-rumors.yml` for GitHub Actions.

---

## Cost

- Per round (Tier 1 + enrichment): ~$0.10
- Monthly across season: ~$0.50–$1.00
- GitHub Actions minutes: $0 (free tier sufficient)
- Vercel: $0 (free tier sufficient)

---

## Integration Paths (from ROADMAP.md)

### Path A: Isolated (default)
- Paddock Rumors writes to `paddock-rumors/data/knowledge-base.json`
- Phase 1 (`f1-intelligence/`) remains untouched
- Manual step: User copies isolated KB into Phase 1 when ready
- **Lowest risk, highest control**

### Path B: Integrated
- Paddock Rumors writes directly to `f1-intelligence/api/data/f1-knowledge-base.json`
- Apply `paddock-rumors/upgrades/build-index.js` and `intelligence.js` to Phase 1
- GitHub Actions triggers Vercel redeploy automatically
- **User approves upgrade files first**

### Path C: Parallel
- Both systems run simultaneously
- Phase 1 serves via existing retrieval, Paddock Rumors builds shadow KB
- Switchover is a single-line config change in `public/config.php`
- **Future-proofing approach**

See `paddock-rumors/ROADMAP.md` for detailed decision matrix and when to use each path.

---

## Applying Upgrades (requires user OK)

The `paddock-rumors/upgrades/` folder contains two drop-in replacements for Phase 1 files:
- `build-index.js` — tag-preserving, incremental indexing
- `intelligence.js` — season-aware retrieval with relevance boosting

**Steps when user approves:**
1. Show the diff between current Phase 1 files and upgrades
2. Rename originals to `.pre-paddock-rumors` for snapshot
3. Copy upgrades into place
4. Test locally
5. Deploy to Vercel
6. Verify on test server
7. Deploy to live

---

## Per-Round State & Idempotency

Paddock Rumors tracks progress in `state/last_processed_round.json`:
```json
{
  "season": 2026,
  "round": 5,
  "qualifying_synthesised": true,
  "race_synthesised": true,
  "f1technical_enriched": true
}
```

Each phase writes state *after* completion. Partial failures never replay completed work. This enables safe reruns and manual retries.

---

## Season Relevance Boost

The built-in retrieval layer weights documents by season:
- Current season: ×1.20
- Previous season: ×1.00
- Two seasons back: ×0.90
- Older: ×0.80–0.85

Controlled by `F1_SEASON` Vercel env var. Bump it when the calendar rolls over (end of season).

---

## Important Rules for Claude Code

1. **Never modify `f1-intelligence/` or `public/f1-intelligence/`** without explicit user approval. They serve live traffic.
2. **Default to Path A (isolated)** when proposing how to use Paddock Rumors. Integration is the user's decision.
3. **When proposing the `upgrades/` files be applied**, always show the diff first, snapshot the originals (rename to `.pre-paddock-rumors`), and wait for the user's OK.
4. **`F1_SEASON` needs bumping each year** in three places:
   - Vercel env var
   - `paddock-rumors/state/last_processed_round.json`
   - `.github/workflows/paddock-rumors.yml`

---

## Full Documentation

- `paddock-rumors/README.md` — system overview
- `paddock-rumors/ROADMAP.md` — integration paths and decision matrix
- `paddock-rumors/SCHEDULING.md` — when each source publishes and the cron pattern
- `paddock-rumors/upgrades/README.md` — Phase 1 file upgrades, applied on user OK only

---

## Evaluating Upgrade Sources

Paddock Rumors currently uses Jolpica-F1 + Claude synthesis + optional F1Technical enrichment.

**Under evaluation for future versions:**
- FIA Car Presentation Submission PDFs (car upgrades)
- Pirelli tyre allocation PDFs
- FIA power unit element usage documents
- FastF1 Python library (telemetry)
- Reddit r/formula1 (community sentiment)
- RSS feeds: The Race, Motorsport.com, Autosport, RacingNews365, AMuS

See `paddock-rumors/README.md` for evaluation criteria and cost/quality tradeoffs.
