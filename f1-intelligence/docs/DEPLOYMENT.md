# Deployment Guide

Deployment workflow for F1 Intelligence in the Paddock Picks (f1betting) project.

## Your Workflow

You don't have a local PHP environment, only:
- **Your computer** (Ubuntu with VS Code + Claude Code) → Used for Node.js/Vercel work
- **Test server**: hpovlsen.dk → Test PHP integration here first
- **Live server**: formula-1.dk → Deploy when tested

## Deployment Steps

### Step 1: Prerequisites (One-time)

Make sure you have:
- [ ] Vercel account (https://vercel.com)
- [ ] OpenAI API key (https://platform.openai.com)
- [ ] Anthropic API key (https://console.anthropic.com)
- [ ] Node.js installed locally (https://nodejs.org)
- [ ] Vercel CLI: `npm install -g vercel`

### Step 2: Build Vector Index (One-time, locally)

```bash
cd f1betting/f1-intelligence/api

# Install Node dependencies
npm install

# Set API keys for this session
export OPENAI_API_KEY="sk-proj-xxxxx"
export ANTHROPIC_API_KEY="sk-ant-xxxxx"

# Build the vector index
npm run build-index
```

This creates `data/f1-vector-index.json` (~500KB). **This file MUST be committed to git** so Vercel can deploy it.

### Step 3: Deploy API to Vercel

```bash
# Still in f1betting/f1-intelligence/api directory

# Login (browser will open)
vercel login

# Deploy to production
vercel deploy --prod
```

Follow the prompts:
- Link to existing project? **N**
- Project name? **f1-intelligence-api**
- Directory? **./

**Save the URL:** `https://f1-intelligence-api-xxxxx.vercel.app`

### Step 4: Set Environment Variables in Vercel

Go to https://vercel.com/dashboard:
1. Click your project
2. Settings → Environment Variables
3. Add:
   - `OPENAI_API_KEY` = your OpenAI key (Production)
   - `ANTHROPIC_API_KEY` = your Anthropic key (Production)
4. Redeploy: Deployments → ⋯ → Redeploy

### Step 5: Test API Directly

```bash
curl -X POST https://your-vercel-url.vercel.app/api/intelligence \
  -H "Content-Type: application/json" \
  -d '{"question": "How does Verstappen perform at Monaco?"}'
```

Should return JSON with `answer` and `sources`.

### Step 6: Configure PHP

In your f1betting project, update `config.php`:

```php
// F1 Intelligence Configuration
define('F1_INTELLIGENCE_API_URL', 'https://f1-intelligence-api-xxxxx.vercel.app');
define('F1_INTELLIGENCE_TIMEOUT', 30);
define('F1_INTELLIGENCE_DEBUG', true); // false on formula-1.dk
```

### Step 7: Deploy to Test Server (hpovlsen.dk)

Upload via FTP:
- `public/f1-intelligence/F1Intelligence.php`
- `public/f1-intelligence/test.php`
- `public/config.php` (updated with Vercel URL)

### Step 8: Test on hpovlsen.dk

Visit: `https://hpovlsen.dk/f1-intelligence/test.php`

Should see:
- ✅ API is reachable!
- ✅ Query successful!
- Answer about Verstappen at Monaco

### Step 9: Deploy to Live (formula-1.dk)

When test passes:
1. Update `config.php`: `F1_INTELLIGENCE_DEBUG = false`
2. Upload same files to formula-1.dk
3. Verify at `https://formula-1.dk/f1-intelligence/test.php`

## Updating the Knowledge Base

When you add new F1 data:

```bash
# 1. Edit the knowledge base
nano f1-intelligence/api/data/f1-knowledge-base.json

# 2. Rebuild the index
cd f1-intelligence/api
npm run build-index

# 3. Commit changes
git add data/
git commit -m "Update F1 knowledge base"

# 4. Redeploy
vercel deploy --prod
```

## Cost Monitoring

- **OpenAI:** https://platform.openai.com/usage
- **Anthropic:** https://console.anthropic.com/settings/billing
- **Vercel:** Free tier, no cost monitoring needed

Expected: ~$10/month for 1000 queries.

## Rollback

If something breaks on live:

```bash
# Rollback Vercel API
vercel rollback

# Rollback PHP: Re-upload previous version via FTP
```

## Troubleshooting

### "Cannot reach API" on hpovlsen.dk
- Verify `F1_INTELLIGENCE_API_URL` matches your Vercel URL
- Test Vercel API directly with curl
- Check Vercel deployment status

### API returns 500
- Check Vercel logs: `vercel logs`
- Verify environment variables are set in Vercel dashboard
- Check `data/f1-vector-index.json` was deployed

### Slow queries (first time)
- First query after inactivity: 3-5 seconds (cold start) - normal
- Subsequent queries: 1-3 seconds - normal
