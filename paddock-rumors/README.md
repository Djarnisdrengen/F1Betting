# Paddock Rumors

> Auto-updating F1 race knowledge, distilled from the paddock's analysis sources.

A scheduled pipeline that fetches structured race results from **Jolpica-F1**, has **Claude** synthesise neutral race + driver documents, optionally enriches with **F1Technical** analysis pieces (F1MATHS / F1 TECH), and outputs a tagged knowledge base ready for retrieval.

Lives alongside (not inside) the existing `f1-intelligence/` RAG setup. See **`ROADMAP.md`** for how the two are intended to come together.

## What this is, what it isn't

**Is:** the *content* layer. Generates fresh, tagged KB documents after each race weekend.

**Isn't:** a serving layer. It doesn't host an API or embed documents — that's the job of `f1-intelligence/api/`. By default it just writes JSON to `data/knowledge-base.json` and leaves the rest to you.

## Files

| File | Purpose |
|------|---------|
| `update-kb.js`           | Main orchestrator. Run this. |
| `schedule.js`            | Pure logic — decides per-round what work is needed. |
| `fetch-results.js`       | Jolpica-F1 API client. |
| `synthesise.js`          | Claude → qualifying doc + race doc + per-driver docs from structured data. |
| `enrich-f1technical.js`  | Optional, non-blocking F1Technical summarisation. |
| `state/last_processed_round.json` | Per-round state (schema v2). Commit it. |
| `data/knowledge-base.json` | Default KB output (generated). |
| `upgrades/`              | Phase 1 file upgrades — see `upgrades/README.md`. |
| `package.json`           | Dependencies (node-fetch) for CI installs. |
| `query.js`               | CLI keyword search tool for inspecting `knowledge-base.json` locally. |
| `backfill-enrichment.js` | One-off backfill of F1Technical enrichment from monthly archive pages. |
| `SCHEDULING.md`          | Per-source timing and cron pattern. |
| `ROADMAP.md`             | How to integrate with the live f1-intelligence API. |

## Document schema

Every doc carries season/type tags so a retrieval layer can weight current-season content highest:

```json
{
  "id": "race-2026-r05-gilles-villeneuve",
  "title": "Canadian Grand Prix 2026 — Race Result & Analysis",
  "content": "Season 2026, Round 5: Canadian Grand Prix. ...",
  "tags": {
    "season": 2026,
    "type": "race",
    "round": 5,
    "circuit": "circuit-gilles-villeneuve",
    "drivers_top10": ["kimi-antonelli", "..."]
  },
  "source_url": "https://...",
  "updated_at": "2026-05-25T08:00:00.000Z",
  "content_hash": "a1b2c3d4e5f60718"
}
```

`type` is one of: `qualifying`, `race`, `driver`, `analysis`, `testing`, `evergreen`.

## Running it

```bash
cd paddock-rumors
npm install

export ANTHROPIC_API_KEY="sk-ant-..."

node update-kb.js                # default: writes to ./data/knowledge-base.json
```

Output lands in `./data/knowledge-base.json`. **Nothing touches `f1-intelligence/` unless you ask it to** — see `ROADMAP.md` for integration paths.

Idempotent: re-running with no new round and no analysis-window work exits in under a second.

```
[paddock-rumors] schedule plan:
[paddock-rumors]   R5 Canadian Grand Prix   age=  44.0h  tier1=done  enrich=needed
[paddock-rumors] summary: 1 round(s) need work: R5[EN]
```

## Configuration (env vars)

| Var | Default | Purpose |
|-----|---------|---------|
| `F1_SEASON` | `2026` | Current championship season. |
| `TOP_N_DRIVERS` | `20` | How many drivers get a maintained per-season doc. |
| `F1TECH_ENRICH` | `1` | Set to `0` to disable enrichment (useful for backfill). |
| `F1TECH_MAX_ARTICLES` | `5` | Cap on F1Tech articles summarised per round. |
| `CLAUDE_MODEL` | `claude-sonnet-4-6` | Model used for synthesis. |
| `KB_OUTPUT_PATH` | `./data/knowledge-base.json` | Where to write the KB. Override to integrate. |
| `FORCE_QUALI` | `false` | Set to `true` to re-synthesise the qualifying doc even if already done. |
| `ANTHROPIC_API_KEY` | — | Required. |

## How it relates to f1-intelligence

`f1-intelligence/` already exists in this repo and runs the live RAG API (the test page on hpovlsen.dk and formula-1.dk is fed by it). It uses an older, untagged knowledge base format.

Paddock Rumors generates a **richer, tagged** knowledge base. It can:

1. **Stay isolated** (default) — its output lives in `paddock-rumors/data/`. Useful for testing the content quality before swapping out the live KB.
2. **Feed the live API** — set `KB_OUTPUT_PATH=../f1-intelligence/api/data/f1-knowledge-base.json` to write directly to the live KB. Requires applying the upgrades in `upgrades/` first so the API can use the tags. See `ROADMAP.md`.

## Failure model

- **Per-phase commits.** Tier 1 and enrichment commit independently.
- **Enrichment is non-blocking.** Network or parse failures log a warning; Tier 1 docs are never blocked.
- **Synthesis failure stops the run.** Tier 1 failure exits non-zero and leaves the round's state untouched. Re-run after fixing.
- **State is versioned.** Migration from v1 to v2 happens automatically on first run.

## Scheduled in production

`.github/workflows/paddock-rumors.yml` runs this on a cron covering the qualifying window (Sat), results window (Sun–Mon), and analysis publishing window (Tue–Thu). Default mode is **content-only** — generates and commits, no integration with the live API.

See `SCHEDULING.md` for the cron pattern and `ROADMAP.md` for switching the workflow to integrated mode.
