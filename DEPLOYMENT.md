# Deployment Strategy

## npm Commands

| Command | What it does |
|---|---|
| `npm run deploy:test` | Deploy `public/` to hpovlsen.dk, then run smoke tests. |
| `npm run deploy:live` | Deploy `public/` to formula-1.dk (prompts "YES" confirmation), backs up first, runs smoke + E2E tests. Rolls back on failure. |
| `npm run sync:live` | Overwrite all test-site data (except settings) with a live copy from formula-1.dk. |
| `npm run test:smoke` | HTTP smoke tests against the deployed site. |
| `npm run test:e2e` | Playwright E2E tests against the deployed site. |
| `npm run test:integration` | Playwright integration tests — seeds deterministic data on hpovlsen.dk and asserts points, leaderboard order, and pool sizes. |
| `npm run test:all` | `test:smoke` + `test:e2e`. |
| `npm run setup:deploy` | One-time setup for FTP deploy credentials. |

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
