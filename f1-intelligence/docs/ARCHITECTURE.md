# Architecture

## System Overview

```
┌─────────────────────────────────────────────────────────────┐
│                                                              │
│  USER                                                        │
│   │                                                          │
│   │ Asks: "How does Verstappen perform at Monaco?"          │
│   ▼                                                          │
│  ┌──────────────────────────────────┐                       │
│  │  Paddock Picks (PHP)             │                       │
│  │  hpovlsen.dk / formula-1.dk      │                       │
│  │                                  │                       │
│  │  public/f1-intelligence/         │                       │
│  │  └── F1Intelligence.php          │                       │
│  └──────────────┬───────────────────┘                       │
│                 │                                            │
│                 │ HTTP POST                                  │
│                 │ { question: "..." }                        │
│                 ▼                                            │
│  ┌──────────────────────────────────┐                       │
│  │  F1 Intelligence API (Vercel)    │                       │
│  │  https://*.vercel.app/api/...    │                       │
│  │                                  │                       │
│  │  1. Receive question             │                       │
│  │  2. Create embedding ────────────┼──► OpenAI API         │
│  │  3. Vector search                │                       │
│  │  4. Retrieve top 3 docs          │                       │
│  │  5. Generate answer ─────────────┼──► Anthropic API      │
│  │  6. Return response              │                       │
│  └──────────────┬───────────────────┘                       │
│                 │                                            │
│                 │ { answer, sources }                        │
│                 ▼                                            │
│  Display in Paddock Picks UI                                │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## Why This Architecture?

**Problem:** simply.com hosts only support PHP/MySQL, not Node.js.

**Solution:** 
- API computation on Vercel (free serverless Node.js)
- PHP frontend on simply.com (just makes HTTP requests)
- Communication via standard HTTP/JSON

## Components

### Vercel Side (Node.js)

**Location:** `f1betting/f1-intelligence/api/`

| File | Purpose |
|------|---------|
| `api/intelligence.js` | Serverless endpoint handler |
| `build-index.js` | One-time index builder (your computer) |
| `query.js` | CLI testing tool (your computer) |
| `data/f1-knowledge-base.json` | Source F1 data |
| `data/f1-vector-index.json` | Generated embeddings (deployed) |
| `vercel.json` | Vercel configuration |
| `package.json` | Node.js dependencies |

### simply.com Side (PHP)

**Location:** `f1betting/public/f1-intelligence/`

| File | Purpose |
|------|---------|
| `F1Intelligence.php` | PHP client class (cURL wrapper) |
| `test.php` | Standalone test page |

### Configuration

**Location:** `f1betting/public/config.php` (existing)

Add these constants:
```php
define('F1_INTELLIGENCE_API_URL', 'https://your-app.vercel.app');
define('F1_INTELLIGENCE_TIMEOUT', 30);
define('F1_INTELLIGENCE_DEBUG', false);
```

## Data Flow Example

1. **User asks:** "How does Verstappen perform at Monaco?"

2. **PHP makes HTTP request to Vercel:**
   ```
   POST https://your-app.vercel.app/api/intelligence
   Content-Type: application/json
   { "question": "How does Verstappen perform at Monaco?" }
   ```

3. **Vercel API:**
   - Creates embedding vector for the question (OpenAI)
   - Compares to all 10 document embeddings
   - Picks top 3 most similar: Verstappen Monaco history, Monaco 2023, Pole stats
   - Sends question + context to Claude
   - Returns formatted answer

4. **PHP receives:**
   ```json
   {
     "answer": "Max Verstappen has a 28.6% win rate at Monaco...",
     "sources": [
       { "title": "Max Verstappen's Monaco Track Record", "similarity": 0.94 },
       ...
     ]
   }
   ```

5. **Display in Paddock Picks UI**

## Cost Structure

| Service | Per Query | Monthly (1000 queries) |
|---------|-----------|------------------------|
| Vercel hosting | $0 | $0 |
| OpenAI embedding | $0.00002 | $0.02 |
| Anthropic Claude | $0.01 | $10 |
| **Total** | **~$0.01** | **~$10** |

## Why RAG?

Without RAG, Claude doesn't know:
- Specific F1 race results
- Driver statistics over time
- Circuit-specific performance data

With RAG:
- Claude receives relevant historical data with each question
- Answers are grounded in actual F1 statistics
- Knowledge base can be updated without retraining

## Updating the System

### Add New F1 Data

1. Edit `f1-intelligence/api/data/f1-knowledge-base.json`
2. Rebuild index: `cd f1-intelligence/api && npm run build-index`
3. Commit changes
4. Deploy: `vercel deploy --prod`

### Modify Answer Generation

Edit `f1-intelligence/api/api/intelligence.js`:
- Change Claude model (e.g., to Haiku for cheaper queries)
- Adjust prompt template
- Change max_tokens

Then redeploy: `vercel deploy --prod`

### Change PHP Behavior

Edit `public/f1-intelligence/F1Intelligence.php`:
- Adjust timeouts
- Change error handling
- Add caching

Upload via FTP to test/live servers.

## Security Considerations

- API keys stored in Vercel environment variables (never in code)
- CORS configured to allow PHP requests
- No user data stored in RAG system
- All queries are stateless
- No authentication needed (rate limiting in Vercel)

## Performance

| Metric | Target | Notes |
|--------|--------|-------|
| First query (cold start) | <5s | Vercel free tier limitation |
| Subsequent queries | <3s | Warm function |
| PHP overhead | <100ms | Just HTTP proxy |
| Vector search | <50ms | In-memory, 10 docs |

## Scalability

Current setup handles:
- 10 documents in knowledge base
- ~10,000 queries/month on free tier
- Concurrent queries (Vercel auto-scales)

For more queries/data:
- Add caching in MySQL (Paddock Picks DB)
- Use Vercel Pro for higher limits
- Switch to dedicated vector DB (Pinecone) for >100 documents
