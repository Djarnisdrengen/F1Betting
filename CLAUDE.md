# CLAUDE.md — Quick Reference for Claude Code

This file is injected into Claude Code conversations. Keep it **small** — detailed docs live in `docs/` and system folders.

---

## What this project is

Formula 1 prediction game. Players pick top-3 podium finishers before each race. Points awarded per position, with bonus pool payouts for perfect predictions.

**Two environments:**
- **Test:** `hpovlsen.dk` — development/testing
- **Live:** `formula-1.dk` — production

**Bilingual:** Danish (default) and English, stored per user in DB.

---

## Quick commands

| Task | Command |
|------|---------|
| Deploy to test + run tests | `npm run deploy:test` |
| Deploy to live (smoke only) | `npm run deploy:live` |
| Copy live DB → test DB | `npm run sync:live` |
| Check DB schema is migrated | `npm run schema:check` / `schema:check:live` |
| Run all tests | `npm run test:all` |
| Smoke tests only | `npm run test:smoke` |
| E2E tests on test env | `npm run test:e2e:test` |
| Security tests | `npm run test:security` |

**Single Playwright spec:**
```bash
DEPLOY_ENV=test npx playwright test tests/e2e/admin/10-content.spec.js --config tests/playwright.config.js
```

**Single test by title:**
```bash
DEPLOY_ENV=test npx playwright test --grep "create and delete a race" --config tests/playwright.config.js
```

---

## Architecture snapshot

- **Backend:** Procedural PHP (no framework). Each page is standalone `.php` file in `public/`.
- **Frontend:** Vanilla JS (`public/assets/js/app.js`), Bootstrap CSS. No build step.
- **Database:** MySQL. Schema at `database/schema.sql`.
- **Email:** `public/includes/smtp.php` — Proton Mail primary, Resend API fallback.
- **Deploy/test:** Node.js scripts in `build-deploy/` and `tests/`.
- **Config:** Per-environment (`config.test.php` / `config.live.php`). `config.shared.php` is shared and in git.

---

## PHP conventions (see docs/patterns.md for full list)

**Standard page opening:**
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
- `getBettingStatus($race, $now)` — betting window logic
- `fetchDrivers($db)`, `getRaces($db)`, `getBetsByRace($db, $raceId)` — shared queries
- `generateUUID()` — all exposed primary keys
- `hashPassword()` / `verifyPassword()` — password handling
- `t('key')` — all user-facing strings
- `setLang($db, $userId, $lang)` — language updates
- `logToFile($msg, $file)` — file logging with config constants

**Output escaping:** Always escape at render time with `htmlspecialchars()`. Prepared statements for all DB writes. Every POST form needs `<?= csrfField() ?>` and handler needs `requireCsrf()`.

---

## Critical gotchas (see docs/gotchas.md for full list)

- **Always use `www` in URLs** — Apache redirects non-www → www with 301, which drops POST bodies.
- **Admin has `in_competition = 0`** — intentional. Never on leaderboard or in pool calculations.
- **`quali_p1/p2/p3` are driver IDs** — not names. Mismatches silently fail scoring.
- **`config.shared.php` must be deployed** — in git but must be on server with `config.php`.
- **Nightly report email dedup** — if `SMTP_FROM` and `REPORT_TO` share Proton account, email appears twice.

---

## Full documentation

Read these when you need detail:

| Doc | Purpose |
|-----|---------|
| `docs/architecture.md` | Request lifecycle, scoring, admin panel, cron jobs, test seeding |
| `docs/patterns.md` | PHP conventions, helpers, naming, structure |
| `docs/gotchas.md` | Full gotcha list (deployment, edge cases, common mistakes) |
| `docs/testing.md` | Mailsac integration, E2E test architecture, test seeding |
| `docs/github-actions.md` | CI workflows |
| `docs/f1-intelligence-reference.md` | RAG system (Phase 1, live on Vercel) — do not modify without user OK |
| `docs/paddock-rumors-reference.md` | Content-gen pipeline (coexists, isolated by default) |

---

## Two critical systems (do NOT confuse)

### 1. f1-intelligence/ (Phase 1 RAG — LIVE on Vercel)

**Paths:** `f1-intelligence/` (Node.js) + `public/f1-intelligence/` (PHP client)

**Rule:** Do NOT modify without explicit user approval of a specific change. This serves live traffic.

See `docs/f1-intelligence-reference.md` for full details.

### 2. paddock-rumors/ (Content-gen pipeline — coexists, isolated by default)

**Path:** `paddock-rumors/` (separate, parallel system)

**Default mode:** Writes to `paddock-rumors/data/knowledge-base.json` (fully isolated, does NOT touch Phase 1).

**Rule:** Integration is the user's decision. See `docs/paddock-rumors-reference.md` for full details.

---

## Config files (not in git, required for commands)

You need these locally before running deploy/test commands:
- `config.test.php` — test environment secrets
- `config.live.php` — live environment secrets

Ask Djarnis if you need to regenerate them.

---

## When starting a Claude Code conversation

1. **Check what you're working on** — f1-intelligence (Phase 1)? paddock-rumors? Core Paddock Picks?
2. **Read the relevant reference doc** if you need detail beyond this index.
3. **Plan before implementing** — Djarnis prefers to review the plan and give OK before code changes.
4. **Reference the full docs** — `See docs/patterns.md for the full list of PHP helpers` etc.

---

*Last updated: June 2026. For the latest on Paddock Rumors and F1 Intelligence integration, see the reference docs.*
