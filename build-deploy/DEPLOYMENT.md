# Deployment Strategy

## npm Commands

### Deploy

| Command | What it does |
|---|---|
| `npm run deploy:test` | Uploads all files from `public/` to **hpovlsen.dk** via FTP, respecting `.deployignore`. After upload, runs HTTP smoke tests. Test-only files (`test-seed.php`, `sync-from-live.php`) **are** uploaded here — they live only on the test server. |
| `npm run deploy:live` | Uploads all files from `public/` to **formula-1.dk** via FTP. Requires typing `YES` at the confirmation prompt. Before uploading, creates a timestamped backup of the current live site. After upload, runs smoke tests + Playwright E2E tests. If either fails, automatically rolls back to the backup. Test-only files are excluded via `.deployignore.live`. |
| `npm run setup:deploy` | One-time interactive setup that writes FTP credentials and URLs into `build-deploy/.env`. Run this when setting up the project on a new machine. |

### Sync & Restore

| Command | What it does |
|---|---|
| `npm run sync:live` | Copies all data from the live database (formula-1.dk) into the test database (hpovlsen.dk), overwriting everything except the `settings` table. Drops any `old_` prefixed legacy tables. Useful for testing against real data. Requires `LIVE_DB_NAME` to be defined in the test server's `config.php`. |
| `npm run restore:db` | Lists all available DB backups (from `build-deploy/backups/live/`). Run with a timestamp and target to restore: `npm run restore:db -- <timestamp> [test\|live]`. Reads `db-backup.json` from the chosen backup folder and re-imports all tables into the target database. Restoring to **live** has a 5-second abort window before it proceeds. `db-restore.php` must be present on the target server — it is deployed to test automatically but **excluded from live** by default (remove it from `.deployignore.live` temporarily if you need a live restore). |

### Test

| Command | What it does |
|---|---|
| `npm run test:smoke` | Fires HTTP requests against the deployed site and checks that key pages return 200. Fast, no browser. Runs automatically as part of every deploy. Requires `BASE_URL` to be set. |
| `npm run test:e2e` | Runs the Playwright E2E browser tests (`smoke.spec.js`) against whichever `BASE_URL` is set. Reads credentials from `TEST_USER_EMAIL` / `TEST_USER_PASSWORD`. Used internally by `deploy:live`. |
| `npm run test:e2e:live` | Manually runs E2E tests against **formula-1.dk**. Reads `BASE_URL_LIVE`, `TEST_USER_EMAIL_LIVE`, and `TEST_USER_PASSWORD_LIVE` from `build-deploy/.env` automatically. |
| `npm run test:e2e:test` | Manually runs E2E tests against **hpovlsen.dk**. Reads `BASE_URL_TEST`, `TEST_USER_EMAIL_TEST`, and `TEST_USER_PASSWORD_TEST` from `build-deploy/.env` automatically. |
| `npm run test:integration` | Runs the Playwright integration test suite against **hpovlsen.dk** only. Before asserting, calls `test-seed.php` to reset the test database and seed 5 races of deterministic data (3 users, 10 drivers, 15 bets). Asserts correct points totals, leaderboard order, star counts, and betting pool sizes. **Never run this against the live site — it seeds fake data.** Run manually after deploying to test. |
| `npm run test:all` | Runs `test:smoke` then `test:e2e`. Equivalent to what `deploy:live` runs automatically after upload. |

---

## Overview

| Environment | Site | Branch |
|-------------|------|--------|
| Local dev   | Direct file editing | `main` |
| Test        | hpovlsen.dk | `main` |
| Live        | formula-1.dk | `main` (only after test verified) |

---

## Local Folder Setup

You only need **one local folder** — the GitHub repo:

```
~/Documents/Websites/github/F1Betting/
```

Do all development here. No need for a separate live copy locally.

---

## Workflow

### 1. Develop locally
Edit files in the repo.

### 2. Commit to GitHub
```bash
git add .
git commit -m "describe change"
git push
```

### 3. Deploy to test
```bash
node build-deploy/deploy.js test
```
Verify everything works on **hpovlsen.dk**.

### 4. Deploy to live — only when test is confirmed working
```bash
node build-deploy/deploy.js live
```

---

## Preventing Accidental Live Deploys

The deploy script has a confirmation prompt for live deploys.
When you run `node build-deploy/deploy.js live`, you must type `YES` exactly to proceed — pressing enter or any other input cancels it.

To add this guard to the deploy script, update `build-deploy/deploy.js`:

1. Add `const readline = require("readline");` at the top.
2. Replace the final `deploy();` line with:

```js
async function main() {
    const env = process.argv[2] || "test";
    if (env === "live") {
        const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
        await new Promise(resolve => rl.question("⚠️  Deploy to LIVE (formula-1.dk)? Type YES to confirm: ", answer => {
            rl.close();
            if (answer !== "YES") {
                console.log("Aborted.");
                process.exit(0);
            }
            resolve();
        }));
    }
    deploy();
}
main();
```

---

## What Gets Deployed

- Everything in the `public/` folder
- Respects exclusions listed in `build-deploy/.deployignore`

## Environment Variables

Stored in `build-deploy/.env` (never committed to git):

```env
FTP_HOST=your-ftp-server.com
FTP_USER=your-ftp-username
FTP_PASS=your-ftp-password
FTP_ROOT_TEST=/path/to/test/root
FTP_ROOT_LIVE=/path/to/live/root
DRY_RUN=false
```

---

## Summary

```
edit code → git commit → deploy test → verify on hpovlsen.dk → deploy live
```
