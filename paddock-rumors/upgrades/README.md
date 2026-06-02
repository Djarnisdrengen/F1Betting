# upgrades/

Two files here are **drop-in replacements** for files in `f1-intelligence/api/`. They are *not* applied by the Paddock Rumors installer — applying them is an explicit decision you make when you're ready to integrate (see `../ROADMAP.md`, Path B).

## What's here

```
upgrades/
├── README.md              ← this file
├── build-index.js         ← replaces f1-intelligence/api/build-index.js
└── intelligence.js        ← replaces f1-intelligence/api/api/intelligence.js
```

## When you need these

You don't need them at all if you're staying on Path A (Paddock Rumors writes to its own folder, your live API keeps using the static legacy KB).

You **do** need them for Path B (Paddock Rumors feeds the live API). The reasons:

| File | Why it must be upgraded |
|------|-------------------------|
| `build-index.js` | The version in your repo today **drops the `tags` field** when building the vector index. Without these tags, season weighting at query time has nothing to weight on. The upgrade preserves every field and also adds incremental embedding (only re-embeds changed docs, so per-run cost drops to a few cents). |
| `intelligence.js` | The version in your repo today does straight cosine similarity. The upgrade adds a `seasonBoost()` step that multiplies similarity by a per-season factor (current ×1.20, prev ×1.00, older ×0.85). Without this, the tags are stored but never consulted. |

## How to apply them

From the repo root, with a clean working tree:

```bash
# 1. Snapshot the current versions in case you want to revert
git mv f1-intelligence/api/build-index.js          f1-intelligence/api/build-index.js.pre-paddock-rumors
git mv f1-intelligence/api/api/intelligence.js     f1-intelligence/api/api/intelligence.js.pre-paddock-rumors

# 2. Drop in the upgraded versions
cp paddock-rumors/upgrades/build-index.js     f1-intelligence/api/build-index.js
cp paddock-rumors/upgrades/intelligence.js    f1-intelligence/api/api/intelligence.js

# 3. Stage and commit so you have a clean "before/after" pair in history
git add f1-intelligence/api/build-index.js f1-intelligence/api/api/intelligence.js \
        f1-intelligence/api/build-index.js.pre-paddock-rumors \
        f1-intelligence/api/api/intelligence.js.pre-paddock-rumors
git commit -m "f1-intelligence: apply Paddock Rumors upgrades (tag-aware build, season-boosted retrieval)"
```

After applying, rebuild the index and redeploy:

```bash
cd f1-intelligence/api
npm run build-index   # first run after the upgrade re-embeds everything; subsequent runs are incremental
vercel deploy --prod  # or trigger your deploy hook
```

Once you've verified the API behaves correctly with the upgrades, delete the `.pre-paddock-rumors` files.

## What changes in behaviour

After applying:

- **Embedding step** now reuses existing embeddings for unchanged docs. The first post-upgrade build re-embeds everything (because the file format changes — tags get stored alongside the embedding). Subsequent runs only embed new/changed docs.
- **Query step** boosts current-season docs by ×1.20, last-season by ×1.00, two seasons back by ×0.90, older or untagged by ×0.80–0.85. The boost is multiplied with cosine similarity before ranking.
- **Response payload** gains a few extra fields per source: `similarity`, `boost`, `season`, `type`. Useful while you're tuning. Remove from the response if you'd rather not expose them.
- **Env var** `F1_SEASON` controls "current season" — defaults to 2026, override in Vercel when the calendar rolls over.

## Rolling back

If something doesn't behave the way you expect:

```bash
git mv f1-intelligence/api/build-index.js.pre-paddock-rumors    f1-intelligence/api/build-index.js
git mv f1-intelligence/api/api/intelligence.js.pre-paddock-rumors f1-intelligence/api/api/intelligence.js
cd f1-intelligence/api
npm run build-index
vercel deploy --prod
```

The KB content itself (whether legacy or Paddock-Rumors-generated) is harmless — only the way it's embedded and queried differs.
