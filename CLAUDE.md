# CLAUDE.md

## Contents

- [What this project is](#what-this-project-is)
- [Infrastructure at a glance](#infrastructure-at-a-glance)
- [Commands](#commands)
  - [Deploy](#deploy)
  - [Test](#test)
- [Architecture](#architecture)
- [PHP conventions](#php-conventions)
- [Key gotchas](#key-gotchas)

---

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

Formula 1 prediction game. Players pick the top-3 podium finishers (P1/P2/P3) before each race. Points are awarded per position, with bonus pool payouts for perfect predictions. Two environments: **test** (`www.hpovlsen.dk`) and **live** (`www.formula-1.dk`). Bilingual: Danish (default) and English, stored per user in the DB.

Full docs are in `docs/` — see `docs/architecture.md`, `docs/patterns.md`, `docs/gotchas.md`, `docs/testing.md`, `docs/github-actions.md`.

## Infrastructure at a glance

| Concern | Provider |
|---|---|
| Hosting & DNS | Simply.com |
| Transactional email (primary) | Proton Mail (SMTP) |
| Transactional email (fallback) | Resend API — automatic fallback in `smtp.php` on SMTP failure |
| Test email interception | Mailsac — seeded test users use `@mailsac.com` addresses; owned inboxes are purged before each E2E run |

---

## Commands

All commands require environment config files (`config.test.php`, `config.live.php`) on disk — they are not in git.

### Deploy

```bash
npm run deploy:test          # upload to test server → run smoke + E2E tests
npm run deploy:live          # upload to live server → run smoke + E2E (01-smoke.spec.js only)
npm run sync:live            # copy live DB → test DB (rewrites emails to @mailsac.com)
```

Deploy includes automatic backup and rollback on test failure. Confirm prompts require typing `YES`.

### Test

```bash
npm run test:smoke           # HTTP endpoint checks (fast, no browser)
npm run test:unit            # Node mailer unit tests only
npm run test:e2e:test        # Playwright full suite against test env
npm run test:e2e:test:mailsac # Same suite with real SMTP + Mailsac delivery assertions
npm run test:e2e:live        # Playwright 01-smoke.spec.js only against live
npm run test:security        # OWASP headers/cookies/access control (test env)
npm run test:security:live   # Same against live
npm run test:all             # smoke + unit + e2e:test
```

**All tests run against a deployed server over HTTP — there is no local test server.**

To run a single Playwright spec:
```bash
DEPLOY_ENV=test npx playwright test tests/e2e/admin/10-content.spec.js --config tests/playwright.config.js
```

To run a single test by title:
```bash
DEPLOY_ENV=test npx playwright test --grep "create and delete a race" --config tests/playwright.config.js
```

---

## Architecture

- **Backend**: Procedural PHP (no framework). Each page is a standalone `.php` file in `public/`.
- **Frontend**: Vanilla JS (`public/assets/js/app.js`), Bootstrap CSS. No build step.
- **Database**: MySQL. Schema at `database/schema.sql`.
- **Email**: `public/includes/smtp.php` — Proton Mail primary, Resend API fallback.
- **Deploy/test**: Node.js scripts in `build-deploy/` and `tests/`.
- **Config**: no single `config.php` — each page requires a per-env file (`config.test.php` / `config.live.php`). `config.shared.php` holds shared constants and is in git. `APP_ENV` is `'test'` or `'live'`.

See `docs/architecture.md` for request lifecycle, scoring, admin panel, cron jobs, and test seeding details. See `docs/github-actions.md` for CI workflows. See `docs/testing.md` for Mailsac and E2E test architecture.

---

## PHP conventions

See `docs/patterns.md` for the full reference.

**Standard page opening sequence:**
```php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();          // or requireAdmin()
requireCsrf();           // on POST handlers
$db       = getDB();
$user     = getCurrentUser();
$settings = $db->query("SELECT * FROM settings LIMIT 1")->fetch();
$lang     = getLang();
```

**Never replicate these — use the helpers:**
- `getBettingStatus($race, $now)` — betting window open/closed logic; never inline
- `fetchDrivers($db)`, `getRaces($db)`, `getBetsByRace($db, $raceId)` — shared queries
- `generateUUID()` — all exposed primary keys (users, bets, invites, etc.)
- `hashPassword()` / `verifyPassword()` — password handling
- `t('key')` — all user-facing strings; never hardcode Danish/English inline
- `setLang($db, $userId, $lang)` — updates both session and DB
- `logToFile($msg, $file)` — use constants from `config.shared.php` for the path

**Output escaping:** escape at render time with `htmlspecialchars()`; never pre-escape on input. Prepared statements for all DB writes. Every POST form needs `<?= csrfField() ?>` and every handler needs `requireCsrf()`.

---

## Key gotchas

Full list in `docs/gotchas.md`. The ones most likely to affect code changes:

- **Always use `www` in URLs** — Apache redirects non-www → www with a 301, which drops POST bodies.
- **Admin has `in_competition = 0`** — intentional. Never appears on the leaderboard or in pool calculations.
- **`quali_p1/p2/p3` are driver IDs** — not names. Mismatches silently fail scoring.
- **`config.shared.php` must be deployed** — it's in git but must be present on the server alongside `config.php`.
- **Nightly report email deduplication** — if `SMTP_FROM` and `REPORT_TO` share the same Proton Mail account, the email appears twice in the inbox.




<!--
APPEND THIS to your existing CLAUDE.md in the f1betting repo root.
Do NOT replace your existing CLAUDE.md - just add this section at the end.
-->

## F1 Intelligence (RAG System)

**Location:** `f1-intelligence/` (Node.js/Vercel API) + `public/f1-intelligence/` (PHP client)

### Purpose

AI-powered F1 racing insights to help users make better podium predictions. Uses Retrieval-Augmented Generation (RAG) with historical F1 data.

### Architecture

**Hybrid deployment** (because simply.com only supports PHP/MySQL):
- **RAG API:** Node.js serverless on Vercel (free tier)
- **PHP Client:** In `public/f1-intelligence/F1Intelligence.php`
- **Communication:** PHP makes HTTPS requests to Vercel API via cURL

### File Locations

```
f1betting/
├── f1-intelligence/                # RAG system (NOT deployed to simply.com)
│   ├── api/
│   │   ├── api/intelligence.js     # Vercel serverless function
│   │   ├── data/
│   │   │   ├── f1-knowledge-base.json   # Source F1 data
│   │   │   └── f1-vector-index.json     # Generated embeddings
│   │   ├── build-index.js          # Run locally to build index
│   │   ├── query.js                # CLI testing tool
│   │   ├── package.json
│   │   └── vercel.json
│   └── docs/
│       ├── DEPLOYMENT.md
│       ├── TESTING.md
│       └── ARCHITECTURE.md
│
└── public/f1-intelligence/         # PHP integration (deployed to simply.com)
    ├── F1Intelligence.php          # PHP client class
    └── test.php                    # Test page
```

### Deployment Workflow

**Servers:**
- Test: hpovslen.dk (PHP)
- Live: formula-1.dk (PHP)
- API: Vercel (Node.js)

**Steps:**
1. Build vector index locally: `cd f1-intelligence/api && npm run build-index`
2. Deploy API: `vercel deploy --prod`
3. Set Vercel env vars: `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`
4. Update `public/config.php` with Vercel URL
5. Upload `public/f1-intelligence/` to hpovslen.dk via FTP
6. Test at `https://hpovslen.dk/f1-intelligence/test.php`
7. Deploy to formula-1.dk when verified

### Configuration

In `public/config.php`:
```php
define('F1_INTELLIGENCE_API_URL', 'https://your-app.vercel.app');
define('F1_INTELLIGENCE_TIMEOUT', 30);
define('F1_INTELLIGENCE_DEBUG', false); // true only on hpovslen.dk
```

### Usage in Paddock Picks

```php
require_once __DIR__ . '/f1-intelligence/F1Intelligence.php';

$intel = new F1Intelligence(
    F1_INTELLIGENCE_API_URL,
    F1_INTELLIGENCE_TIMEOUT,
    F1_INTELLIGENCE_DEBUG
);

$result = $intel->query("How has {$driver} performed at {$circuit}?");

if ($result) {
    echo $result['answer'];
    // $result['sources'] = array of source documents
}
```

### Cost

~$0.01 per query (mostly Claude API).
Monthly: ~$10 for 1000 queries.

### Updating F1 Knowledge Base

1. Edit `f1-intelligence/api/data/f1-knowledge-base.json`
2. Run locally: `cd f1-intelligence/api && npm run build-index`
3. Commit: `git add f1-intelligence/api/data/`
4. Deploy: `vercel deploy --prod`

### Important Rules

- **The `f1-intelligence/` folder (Node.js stuff) is NOT uploaded to simply.com.** Only `public/f1-intelligence/` (PHP) goes to the servers.
- **`f1-vector-index.json` MUST be committed to git** - Vercel needs it during deployment.
- **`node_modules/` and `.vercel` should be gitignored** (see .gitignore).
- **API keys (OpenAI, Anthropic) live ONLY in Vercel environment variables** - never commit them.

### Documentation

- `f1-intelligence/README.md` - Component overview
- `f1-intelligence/docs/DEPLOYMENT.md` - Step-by-step deployment
- `f1-intelligence/docs/TESTING.md` - Testing strategy
- `f1-intelligence/docs/ARCHITECTURE.md` - System design
