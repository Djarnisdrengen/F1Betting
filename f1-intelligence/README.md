# F1 Intelligence (RAG System)

AI-powered F1 racing insights for Paddock Picks predictions.

## What This Does

Provides intelligent answers to F1 questions like:
- "How does Verstappen perform at Monaco?"
- "Best overtaking opportunities at Spa?"
- "Pole position conversion rates at street circuits?"

Uses Retrieval-Augmented Generation (RAG) with historical F1 data.

## Architecture

**Hybrid deployment** (because simply.com only supports PHP):
- **API**: Node.js on Vercel (free serverless)
- **PHP Client**: In `public/f1-intelligence/` (deployed with main app)

## Directory Structure

```
f1-intelligence/                    # You are here
├── api/                            # Vercel deployment
│   ├── api/intelligence.js         # Serverless endpoint
│   ├── data/
│   │   ├── f1-knowledge-base.json  # Source data (edit this)
│   │   └── f1-vector-index.json    # Generated (don't edit)
│   ├── build-index.js              # Index builder
│   ├── query.js                    # CLI test tool
│   ├── package.json
│   └── vercel.json
└── docs/
    ├── DEPLOYMENT.md               # How to deploy
    ├── TESTING.md                  # How to test
    └── ARCHITECTURE.md             # System design
```

## Quick Reference

### Test locally (your computer)
```bash
cd api
npm install
export OPENAI_API_KEY="sk-proj-..."
export ANTHROPIC_API_KEY="sk-ant-..."
npm run build-index           # First time only
node query.js "test question"
```

### Deploy API to Vercel
```bash
cd api
vercel deploy --prod
```

### Update F1 Data
1. Edit `api/data/f1-knowledge-base.json`
2. Run `npm run build-index`
3. Deploy: `vercel deploy --prod`

### PHP Integration

The PHP client is in `../public/f1-intelligence/F1Intelligence.php`.

Use anywhere in Paddock Picks:
```php
require_once __DIR__ . '/f1-intelligence/F1Intelligence.php';
$intel = new F1Intelligence(F1_INTELLIGENCE_API_URL);
$result = $intel->query("Your question");
```

## Documentation

- **docs/DEPLOYMENT.md** - Step-by-step deployment for hpovslen.dk + formula-1.dk
- **docs/TESTING.md** - Testing strategy and test cases
- **docs/ARCHITECTURE.md** - System design and data flow

## Cost

~$10/month for 1000 queries (mostly Claude API).
