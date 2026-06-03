# SCHEDULING

How and when the F1 knowledge base auto-updates.

## The Problem This Solves

F1 sources publish on different timelines:

- Race **results** appear ~1 hour after a race finishes (Jolpica-F1).
- F1Technical's **race-day** pieces appear Sunday evening.
- The **best analysis** (F1MATHS series, F1 TECH deep-dives, Edd Straw
  rankings, The Race "Everything we learned") lands **Tuesday through
  Thursday after the race**.

A single Sunday-night fetch would miss everything except the result. So
the pipeline is built around three phases per race, with re-runnable
enrichment.

## The Three Phases

### Phase 0 — Qualifying (pre-race grid snapshot)

| | |
|---|---|
| **Runs** | Once per round, as soon as Jolpica has qualifying data (typically ~1h after the session ends Saturday) |
| **Output** | One qualifying document: pole, top-10 grid, key gaps, race-pace context — tagged `type: qualifying` |
| **Source** | Jolpica-F1 qualifying endpoint |
| **Idempotency** | `state.rounds.N.qualifying_at` set on completion; won't repeat unless `FORCE_QUALI=true` |
| **Trigger** | Saturday crons (14:00, 16:00, 18:00 UTC) or manual `force_quali` dispatch |

### Phase 1 — Tier 1 (results + driver synthesis)

| | |
|---|---|
| **Runs** | Once per round, as soon as Jolpica has results |
| **Output** | One race document, plus per-driver season-form documents for the top 10 |
| **Source** | Jolpica-F1 structured data |
| **Idempotency** | `state.rounds.N.tier1_at` set on completion; won't repeat |

### Phase 2 — Enrichment (F1Technical analysis)

| | |
|---|---|
| **Runs** | Repeatedly during the **T+36h → T+96h** window after race finish |
| **Output** | Up to 5 summarised analysis documents per round, tagged `type: analysis`, `source: f1technical` |
| **Source** | F1Technical news index, filtered for `F1MATHS:`, `F1 TECH:`, `STRATEGY:`, `ANALYSIS:` and current GP/circuit |
| **Idempotency** | Doc IDs are deterministic slugs — re-running upserts; new articles append; existing ones refresh |
| **Failure mode** | Non-blocking. Network error or HTML parse failure logs and continues; Tier 1 docs are never blocked. |

The 12-hour minimum between successive enrichment passes prevents wasted
API calls when crons fire close together.

## Source-by-Source Publishing Windows

Reference for understanding *why* the windows are what they are. All times
are typical and approximate.

| Source | Window after race end | Captured by phase |
|--------|----------------------|-------------------|
| Jolpica results | T+0:30 to T+2h | Tier 1 |
| **Jolpica qualifying** | **T-22h (Saturday afternoon)** | **Phase 0 — dedicated qualifying doc** |
| F1Technical race-day reports | T+2h to T+12h | Not captured (too quick — caller waits for T+36h) |
| **F1Technical F1MATHS series** | **T+36h to T+96h** | **Enrichment** |
| **F1Technical F1 TECH deep-dives** | **T+48h to T+96h** | **Enrichment** |
| The Race "Everything we learned" | T+24h to T+48h | Future enrichment module |
| Edd Straw driver rankings | T+48h to T+72h | Future enrichment module |
| Pre-season testing analysis | Two Feb weeks | Manual backfill (not automated) |

The constants in `schedule.js` (`ENRICHMENT_MIN_AGE_HOURS = 36`,
`ENRICHMENT_WINDOW_HOURS = 96`) bracket the broadest analysis window.
Tune in one place if a source's cadence changes.

## The Cron Pattern

The GitHub Actions workflow (`.github/workflows/paddock-rumors.yml`) fires
the pipeline at these times (all UTC):

```
Sat  14, 16, 18                    ← qualifying-window crons
Sun  16, 18, 20, 22                ← results-window crons
Mon   0,  2,  4,  6,  8, 10, 12, 14
Tue   18                           ← analysis-window crons
Wed   18
Thu   12
```

This is the *wake-up* schedule. The script then decides what (if
anything) to do based on `schedule.js`'s plan output:

```
[paddock-rumors] schedule plan:
[paddock-rumors]   R5 Canadian Grand Prix         age=  44.0h  tier1=done    enrich=needed
[paddock-rumors]   R6 Monaco Grand Prix           age=  18.0h  tier1=needed  enrich=too_fresh
[paddock-rumors] summary: 2 round(s) need work: R5[EN], R6[T1]
```

On a Sunday off-week with no race for two weeks, the plan reports
"nothing to do" and the script exits in seconds.

## Status Vocabulary

The plan output uses these statuses:

- **`needed`** — phase should run now
- **`done`** — already completed (Tier 1) or within MIN→WINDOW with a
  recent run (enrichment)
- **`done_recent`** — enrichment ran less than 12h ago; skip until next
  cron
- **`too_fresh`** — race is younger than `ENRICHMENT_MIN_AGE_HOURS`;
  analysis isn't published yet
- **`window_passed`** — race is older than `ENRICHMENT_WINDOW_HOURS`;
  no further enrichment attempted

## How to Run It Yourself

### Trigger manually
```bash
# Anywhere, anytime
cd paddock-rumors
ANTHROPIC_API_KEY=... node update-kb.js
```

### Trigger via GitHub Actions UI
Go to the repo → Actions tab → "Paddock Rumors — KB Update" → "Run
workflow". Optionally override the season via the input field.

### Force Tier 1 only (skip enrichment)
```bash
F1TECH_ENRICH=0 node update-kb.js
```
Useful for backfilling many rounds at once without F1Tech overhead.

## Backfill

To populate the KB for rounds you haven't processed yet (e.g. 2026
rounds 1–5 the first time you set this up):

```bash
# Pre-populate Tier 1 only — cheap, fast
F1TECH_ENRICH=0 node update-kb.js

# Then re-run with enrichment ON. Only rounds within the enrichment
# window (last ~4 days) will get F1Technical content; older rounds will
# show enrich=window_passed.
node update-kb.js
```

This is by design: backfilling enrichment for races months in the past
would (1) hit a cold news index where targeted articles are buried, and
(2) cost API spend for analysis that's already irrelevant to current
predictions.

## State File

`state/last_processed_round.json` — commit this to git. The GitHub
Actions workflow does that automatically after each successful run.

Schema (v2):

```json
{
  "season": 2026,
  "rounds": {
    "5": {
      "tier1_at": "2026-05-24T22:00:00.000Z",
      "enrichment_last_at": "2026-05-27T06:00:00.000Z"
    }
  },
  "schema_version": 2
}
```

Migration from v1 (`{ season, round }`) is automatic on first run.
Legacy round numbers ≤ the recorded value are marked Tier 1 complete
with a placeholder timestamp so they're not re-synthesised.

## Tuning

If you find that:

- **Enrichment runs too early and misses good content** → bump
  `ENRICHMENT_MIN_AGE_HOURS` in `schedule.js` (e.g. 48).
- **You miss late-published pieces** → bump `ENRICHMENT_WINDOW_HOURS`
  (e.g. 120) and add a Friday cron.
- **You hit GitHub Actions minute limits** → fewer Monday hours in the
  results-window cron; results don't change after Monday morning.
- **You want to add a new source** (e.g. The Race) → create
  `enrich-therace.js` mirroring `enrich-f1technical.js`, and add it to
  `doEnrichment()` in `update-kb.js`. The schedule and cron don't
  need to change; the new module is just another best-effort step.

## What's NOT Scheduled

- **OpenF1 live timing** (qualifying / sprint / practice data). Adding
  this is Phase 4 work — a `fetch-live.js` and a Tier 2 path in the
  orchestrator. The current cron pattern already covers the right times
  (Saturday cron coverage would extend the results window backward).
- **Pre-season testing.** Currently manual. The two test windows in Feb
  are predictable; an annual one-off run when results are out is
  sufficient.
- **The Race / Edd Straw enrichment.** The pattern is in place; copy
  `enrich-f1technical.js` to add it.
