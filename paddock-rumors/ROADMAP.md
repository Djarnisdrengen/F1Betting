# ROADMAP — From Paddock Rumors to Live API

How Paddock Rumors comes together with the Phase 1 `f1-intelligence/` setup that's already serving traffic on hpovlsen.dk and formula-1.dk.

## Where things stand

You already have:
- **`f1-intelligence/api/`** — a Vercel-deployed serverless RAG endpoint (working).
- **`f1-intelligence/api/data/f1-knowledge-base.json`** — a static, untagged knowledge base.
- **`public/f1-intelligence/F1Intelligence.php`** + `test.php` — a PHP client and test page on simply.com (working).

Paddock Rumors adds:
- **`paddock-rumors/`** — a content-generation pipeline that produces a *tagged* knowledge base, refreshed automatically after each race weekend.
- **`paddock-rumors/upgrades/`** — drop-in replacements for two `f1-intelligence/` files that enable tag-aware behaviour.

Today: Paddock Rumors writes to its own folder and your live API is untouched.
Goal: have the live API serve the rich, auto-refreshing KB.

## Three integration paths (pick one when ready)

### Path A — Stay isolated (do nothing)

Keep using your existing static `f1-intelligence/api/data/f1-knowledge-base.json`. Paddock Rumors generates `paddock-rumors/data/knowledge-base.json` purely for inspection. Useful when:

- You want to evaluate the content quality before going live.
- You want to keep the option of rolling back trivially.

No code changes needed. The cron will commit fresh content to `paddock-rumors/data/` but nothing serves it.

### Path B — Integrated, single Vercel project (recommended)

Make your existing `f1-intelligence/api/` serve the Paddock Rumors KB. This is the lowest-friction long-term setup.

**One-time steps:**

1. **Apply the upgraded API files** (these enable tag-aware retrieval):
   ```bash
   cp paddock-rumors/upgrades/build-index.js     f1-intelligence/api/build-index.js
   cp paddock-rumors/upgrades/intelligence.js    f1-intelligence/api/api/intelligence.js
   ```
   See `upgrades/README.md` for what these change.

2. **Wire the pipeline output to the live KB:**
   ```bash
   # In paddock-rumors/, edit or override:
   export KB_OUTPUT_PATH=../f1-intelligence/api/data/f1-knowledge-base.json
   # or just use the npm script:
   npm run update:integrated
   ```

3. **Generate fresh content and deploy:**
   ```bash
   # First time — backfill Tier 1 only (cheaper, faster)
   F1TECH_ENRICH=0 KB_OUTPUT_PATH=../f1-intelligence/api/data/f1-knowledge-base.json node update-kb.js

   # Then re-embed
   cd ../f1-intelligence/api
   npm run build-index

   # Then redeploy the API
   vercel deploy --prod   # or fire the deploy hook
   ```

4. **Set `F1_SEASON=2026` in Vercel** (Project → Settings → Environment Variables). Without it the season-boost defaults to 2026 but it's clearer to make it explicit, and you'll need to bump it when the calendar rolls over.

5. **Switch the workflow to integrated mode** — see `.github/workflows/paddock-rumors.yml`. Comments at the top show which blocks to uncomment.

**What changes for users:** the API's responses become more current-season-aware. Queries about 2026 retrieve current-season race + driver docs preferentially. Queries about historical/evergreen topics still work via the lower-boost fallback.

**Rollback:** if you don't like the results, revert `build-index.js` and `intelligence.js` from git, re-run `npm run build-index`, redeploy. The KB content itself is harmless even if you keep it.

### Path C — Separate parallel Vercel project

Run Paddock Rumors as its own Vercel app on its own URL. Use both, route as you like. Higher operational overhead; only makes sense if you want A/B testing or to keep Phase 1 frozen indefinitely.

To do it: create a new `paddock-rumors/api/` with its own `intelligence.js` (copy from `upgrades/`) and `vercel.json`. Deploy separately. Point a second `F1Intelligence` PHP client at the new URL. **Not recommended** for a hobby project — Path B is simpler.

## Phased rollout suggestion

```
Now ─────────────► Stay isolated (Path A)
  │                Run the cron. Read paddock-rumors/data/knowledge-base.json
  │                manually each week. Get a feel for the content quality.
  ▼
After 2-3 races ─► Integrated (Path B)
                   Apply upgrades. Backfill Tier 1. Redeploy. Verify with
                   public/f1-intelligence/test.php on hpovlsen.dk that
                   queries about 2026 now pull current-season context.
                   Then promote to formula-1.dk.
```

## What to commit

The GitHub Actions cron (`paddock-rumors.yml`) will commit on your behalf:
- `paddock-rumors/state/last_processed_round.json` (always)
- `paddock-rumors/data/knowledge-base.json` (Path A) **or**
  `f1-intelligence/api/data/f1-knowledge-base.json` (Path B, after switch)

It will **not** commit:
- `node_modules/` (gitignored)
- `f1-vector-index.json` if you're on Path A (the cron only embeds in Path B mode)

## When to bump `F1_SEASON`

In December/January each year, before the new season starts:

1. Update the env var in Vercel: `F1_SEASON=2027`.
2. Update `paddock-rumors/state/last_processed_round.json` to `{ "season": 2027, "rounds": {}, "schema_version": 2 }`. (Or let the pipeline migrate automatically — it detects season rollover.)
3. Update the `F1_SEASON` in `.github/workflows/paddock-rumors.yml`.

The previous-season retrieval boost drops to ×1.00 automatically and older seasons further down the scale — no other changes needed.

## When something looks wrong

- **"Paddock Rumors content is good but the live API doesn't seem to use it"** → You're on Path A. Switch to Path B.
- **"Integration done but old-season questions return 2026 results"** → Expected behaviour: with no question-time season filter, the retriever prefers current season. Adjust the boost curve in `f1-intelligence/api/api/intelligence.js` (function `seasonBoost`) if it's too aggressive.
- **"Cron is committing things but nothing redeploys"** → On Path A by design. Or `VERCEL_DEPLOY_HOOK_URL` secret missing. Check `.github/workflows/paddock-rumors.yml`.
