# Post-Deploy Testing & Rollback Design

**Date:** 2026-05-07
**Project:** F1Betting (formula-1.dk / hpovlsen.dk)

## Overview

Automated post-deploy testing with backup and rollback for the live environment. After every deploy, HTTP smoke tests and Playwright E2E tests run against the deployed site. On the live environment, a backup is taken before deploy and automatically restored if tests fail.

---

## File Structure

```
build-deploy/
  deploy.js              ← modified orchestrator
  backup.js              ← new: FTP download to local folder
  rollback.js            ← new: re-upload backup to FTP
  setup-deployment.js    ← unchanged
  .env                   ← add BASE_URL_TEST, BASE_URL_LIVE, test credentials
  .deployignore          ← unchanged
  backups/               ← git-ignored, auto-created at runtime
    live/
      2026-05-07T10-30-00/   ← timestamped snapshots (keep last 2)

tests/
  smoke.js               ← new: HTTP fetch smoke tests
  playwright.config.js   ← new: Playwright config
  e2e/
    smoke.spec.js        ← new: Playwright E2E tests incl. auth

package.json             ← add @playwright/test dev dep + test scripts
```

The `build-deploy/backups/` folder is local only and git-ignored.

---

## Deploy Flows

### Test deploy (`npm run deploy:test`)

1. Upload `public/` to FTP → hpovlsen.dk
2. Run HTTP smoke tests against `BASE_URL_TEST`
3. Run Playwright E2E tests against `BASE_URL_TEST`
4. Pass → exit 0
5. Fail → print failures, exit 1 (no rollback)

### Live deploy (`npm run deploy:live`)

1. "YES" confirmation prompt
2. Download current FTP → `build-deploy/backups/live/<timestamp>/`
3. Upload `public/` to FTP → formula-1.dk
4. Run HTTP smoke tests against `BASE_URL_LIVE`
5. Run Playwright E2E tests against `BASE_URL_LIVE`
6. Pass → prune backups (keep last 2), exit 0
7. Fail → re-upload backup to FTP, exit 1

---

## HTTP Smoke Tests (`tests/smoke.js`)

Fast Node.js fetch checks, no browser. Runs in ~2–3 seconds.

| Page | Expected status | Expected content |
|---|---|---|
| `/` | 200 | HTML body present |
| `/login.php` | 200 | Login form element |
| `/leaderboard.php` | 200 | Leaderboard content |
| `/races.php` | 200 | Races content |

Target URL comes from `BASE_URL` env var, set by deploy.js before running.

---

## Playwright E2E Tests (`tests/e2e/smoke.spec.js`)

Real Chromium browser, headless. Screenshot saved on failure.

| Test | Description |
|---|---|
| Public pages load | `/`, `/login.php`, `/leaderboard.php`, `/races.php` return 200, no JS errors |
| Login form renders | `/login.php` shows email + password fields |
| Login succeeds | POST credentials, assert redirect to index, user menu visible |
| Authenticated index | Logged-in state visible after login |
| Leaderboard has rows | Table renders with at least one row |
| Races page loads | Content visible |
| Rules page accessible | `/rules.php` accessible when logged in |
| Bet page accessible | `/bet.php` accessible when logged in |

### Playwright config

- Browser: Chromium only, headless
- Timeout: 10s per test
- Screenshot: on failure only
- baseURL: from `BASE_URL` env var

---

## Environment Variables (additions to `.env`)

```
BASE_URL_TEST=https://hpovlsen.dk
BASE_URL_LIVE=https://formula-1.dk

TEST_USER_EMAIL_TEST=...
TEST_USER_PASSWORD_TEST=...
TEST_USER_EMAIL_LIVE=...
TEST_USER_PASSWORD_LIVE=...
```

---

## Backup & Rollback

- **backup.js**: Downloads all files from `<FTP_ROOT_LIVE>/public` to `build-deploy/backups/live/<ISO-timestamp>/` using basic-ftp (same library already used).
- **rollback.js**: Re-uploads the specified backup folder to `<FTP_ROOT_LIVE>/public` using the same `uploadDir` logic as deploy.js.
- **Retention**: After a successful deploy, backups older than the 2 most recent are deleted.
- **Scope**: Backup/rollback only applies to the live environment.

---

## Terminal Output

Every deploy prints a clear summary of each test stage and a final result.

### Smoke tests (pass)
```
🧪 Running smoke tests against https://hpovlsen.dk...
  ✅ GET /            → 200
  ✅ GET /login.php   → 200
  ✅ GET /leaderboard.php → 200
  ✅ GET /races.php   → 200
✅ Smoke tests passed (4/4)
```

### Smoke tests (fail)
```
🧪 Running smoke tests against https://hpovlsen.dk...
  ✅ GET /            → 200
  ❌ GET /login.php   → 500 (expected 200)
  ✅ GET /leaderboard.php → 200
  ✅ GET /races.php   → 200
❌ Smoke tests failed (1/4 failed)
```

### Playwright E2E (pass)
```
🎭 Running Playwright E2E tests against https://hpovlsen.dk...
  ✅ Public pages load
  ✅ Login form renders
  ✅ Login succeeds
  ✅ Authenticated index visible
  ✅ Leaderboard has rows
  ✅ Races page loads
  ✅ Rules page accessible
  ✅ Bet page accessible
✅ E2E tests passed (8/8)
```

### Playwright E2E (fail)
```
🎭 Running Playwright E2E tests against https://hpovlsen.dk...
  ✅ Public pages load
  ❌ Login succeeds → Expected user menu, got login page
  ...
❌ E2E tests failed (1/8 failed) — screenshot saved to build-deploy/screenshots/
```

### Deploy summary (test, fail)
```
❌ Deploy to TEST failed — fix and redeploy.
```

### Deploy summary (live, fail + rollback)
```
❌ Tests failed — rolling back to backup 2026-05-07T10-30-00...
  ↑ Restoring files...
✅ Rollback complete.
❌ Deploy to LIVE failed — fix and redeploy.
```

### Deploy summary (pass)
```
✅ Deploy to TEST complete. All tests passed.
```

---

## Package.json Changes

Add `@playwright/test` as a dev dependency.

Add scripts:
```json
"test:smoke": "node tests/smoke.js",
"test:e2e": "npx playwright test",
"test:all": "npm run test:smoke && npm run test:e2e"
```
