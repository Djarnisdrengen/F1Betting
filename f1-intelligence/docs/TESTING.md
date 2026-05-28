# Testing Guide

Testing strategy for F1 Intelligence (no local dev, test on hpovslen.dk).

## Testing Layers

```
1. Build Index Locally (your computer)
   ↓
2. Test CLI Query Locally (your computer, Node.js)
   ↓
3. Test Vercel API with curl (your computer)
   ↓
4. Test PHP on hpovslen.dk (test server)
   ↓
5. Deploy to formula-1.dk (live server)
```

## Test 1: Build Index (Your Computer)

```bash
cd f1betting/f1-intelligence/api
npm install

export OPENAI_API_KEY="sk-proj-xxxxx"
npm run build-index
```

**Expected:**
```
✅ Index built successfully!
   Documents indexed: 10
```

**Verify:** `data/f1-vector-index.json` exists.

## Test 2: CLI Query (Your Computer)

```bash
cd f1betting/f1-intelligence/api

export OPENAI_API_KEY="sk-proj-xxxxx"
export ANTHROPIC_API_KEY="sk-ant-xxxxx"

node query.js "How does Verstappen perform at Monaco?"
```

**Expected:** Detailed answer with statistics, sources listed.

## Test 3: Vercel API (After Deployment)

```bash
curl -X POST https://your-app.vercel.app/api/intelligence \
  -H "Content-Type: application/json" \
  -d '{"question": "How does Verstappen perform at Monaco?"}'
```

**Expected:** JSON response with `answer` and `sources` fields.

## Test 4: PHP on hpovslen.dk

Upload test files via FTP:
- `public/f1-intelligence/F1Intelligence.php`
- `public/f1-intelligence/test.php`
- `public/config.php` (with correct Vercel URL)

Visit: `https://hpovslen.dk/f1-intelligence/test.php`

**Expected:**
- Green ✅ for API health check
- Green ✅ for query test
- Answer displayed with sources

## Test 5: Production on formula-1.dk

After hpovslen.dk works:
1. Set `F1_INTELLIGENCE_DEBUG = false` in config
2. Upload to formula-1.dk
3. Verify: `https://formula-1.dk/f1-intelligence/test.php`

## Test Cases

### Basic Functionality
- [ ] CLI query returns sensible answer
- [ ] Vercel API responds within 5 seconds
- [ ] PHP test page shows green checkmarks
- [ ] Sources are listed correctly

### Edge Cases
- [ ] Empty question → returns error gracefully
- [ ] Very long question → still works
- [ ] Non-F1 question → returns best-effort answer
- [ ] Special characters in question → handled correctly

### Error Handling
- [ ] API down → PHP shows "API unavailable"
- [ ] Invalid API URL in config → graceful error
- [ ] Timeout → handled correctly

### Performance
- [ ] First query: <5 seconds (cold start OK)
- [ ] Subsequent queries: <3 seconds
- [ ] No memory leaks (multiple queries in a row)

## Sample Questions to Test

```
"How does Verstappen perform at Monaco?"
"What's the pole position win rate at street circuits?"
"Best overtaking opportunities at Spa?"
"Red Bull reliability statistics 2023"
"Hamilton podium rate at Silverstone"
"Strategy for predicting Monaco podium"
```

## Debugging

### Check Vercel Logs
```bash
vercel logs
```

### Check PHP Errors
On hpovslen.dk, check error logs:
- Via cPanel error log viewer
- Or via FTP: `/logs/error.log`

### Enable Debug Mode
In `config.php`:
```php
define('F1_INTELLIGENCE_DEBUG', true);
```

This logs detailed info via `error_log()`.

### Test Connectivity
From hpovslen.dk's PHP, check it can reach Vercel:
```php
$ch = curl_init('https://your-app.vercel.app/api/intelligence');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP $code"; // Should be 405 (POST required) - means reachable
```

## Pre-Deployment Checklist

Before deploying to formula-1.dk:
- [ ] All tests pass on hpovslen.dk
- [ ] `F1_INTELLIGENCE_DEBUG = false` in production config
- [ ] Vercel URL is correct in config
- [ ] API keys are set in Vercel dashboard
- [ ] Costs are monitored (OpenAI + Anthropic)
- [ ] Error handling tested
