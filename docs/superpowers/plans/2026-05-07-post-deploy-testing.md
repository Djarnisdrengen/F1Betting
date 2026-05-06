# Post-Deploy Testing & Rollback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add automatic post-deploy smoke tests and Playwright E2E tests with FTP backup and auto-rollback on live deploys.

**Architecture:** deploy.js orchestrates: (1) backup live FTP before upload, (2) FTP upload, (3) HTTP smoke tests via Node fetch, (4) Playwright E2E via headless Chromium. On failure the live site is restored from backup. Test and live environments each have their own BASE_URL and credentials passed as env vars.

**Tech Stack:** Node.js 18+ (native fetch), basic-ftp (already installed), @playwright/test (new dev dep)

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `build-deploy/backup.js` | Create | Download live FTP → timestamped local folder; prune old backups |
| `build-deploy/rollback.js` | Create | Re-upload a local backup folder → live FTP |
| `tests/smoke.js` | Create | HTTP fetch checks on 4 public pages |
| `tests/playwright.config.js` | Create | Playwright config: Chromium, baseURL from env, custom reporter |
| `tests/reporter.js` | Create | Custom Playwright reporter: per-test ✅/❌ lines + summary |
| `tests/e2e/smoke.spec.js` | Create | 8 E2E tests covering public + authenticated pages |
| `build-deploy/deploy.js` | Modify | Wire up backup → upload → tests → rollback/prune |
| `package.json` | Modify | Add @playwright/test dev dep + test scripts |
| `.gitignore` | Modify | Ignore backups/ and screenshots/ folders |
| `build-deploy/.env` | Manual | Add BASE_URL_TEST, BASE_URL_LIVE, test credentials |

---

## Task 1: Install @playwright/test and update .gitignore / package.json

**Files:**
- Modify: `package.json`
- Modify: `.gitignore`

- [ ] **Step 1: Install @playwright/test**

```bash
cd /home/thomas-helveg-povlsen/Documents/Websites/github/F1Betting
npm install --save-dev @playwright/test
```

Expected: `@playwright/test` appears in `package.json` devDependencies.

- [ ] **Step 2: Install Chromium browser**

```bash
npx playwright install chromium
```

Expected: Chromium browser downloaded (~130MB).

- [ ] **Step 3: Add test scripts to package.json**

Open `package.json` and replace the `scripts` block with:

```json
{
  "scripts": {
    "deploy:test": "node build-deploy/deploy.js test",
    "deploy:live": "node build-deploy/deploy.js live",
    "setup:deploy": "node build-deploy/setup-deployment.js",
    "test:smoke": "node tests/smoke.js",
    "test:e2e": "npx playwright test --config tests/playwright.config.js",
    "test:all": "npm run test:smoke && npm run test:e2e"
  }
}
```

- [ ] **Step 4: Add backups and screenshots to .gitignore**

Append to the end of `.gitignore`:

```
# Deploy backups and test screenshots
build-deploy/backups/
build-deploy/screenshots/
```

- [ ] **Step 5: Commit**

```bash
git add package.json package-lock.json .gitignore
git commit -m "feat: add @playwright/test, test scripts, gitignore entries"
```

---

## Task 2: Create build-deploy/backup.js

**Files:**
- Create: `build-deploy/backup.js`

- [ ] **Step 1: Create the file**

```javascript
const ftp = require("basic-ftp");
const fs = require("fs");
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, ".env") });

async function backup() {
    const timestamp = new Date().toISOString().replace(/[:.]/g, "-");
    const backupDir = path.join(__dirname, "backups", "live", timestamp);
    fs.mkdirSync(backupDir, { recursive: true });

    const client = new ftp.Client();
    try {
        await client.access({
            host: process.env.FTP_HOST,
            user: process.env.FTP_USER,
            password: process.env.FTP_PASS,
        });
        console.log(`\n📦 Backing up live site → build-deploy/backups/live/${timestamp}/`);
        await client.downloadToDir(backupDir, `${process.env.FTP_ROOT_LIVE}/public`);
        console.log(`✅ Backup complete\n`);
    } finally {
        client.close();
    }

    return { timestamp, backupDir };
}

function pruneBackups() {
    const dir = path.join(__dirname, "backups", "live");
    if (!fs.existsSync(dir)) return;
    const entries = fs.readdirSync(dir)
        .filter(f => fs.statSync(path.join(dir, f)).isDirectory())
        .sort()
        .reverse();
    entries.slice(2).forEach(e => {
        fs.rmSync(path.join(dir, e), { recursive: true, force: true });
    });
}

module.exports = { backup, pruneBackups };
```

- [ ] **Step 2: Verify it loads without errors**

```bash
node -e "require('./build-deploy/backup.js'); console.log('OK')"
```

Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add build-deploy/backup.js
git commit -m "feat: add FTP backup module for live environment"
```

---

## Task 3: Create build-deploy/rollback.js

**Files:**
- Create: `build-deploy/rollback.js`

- [ ] **Step 1: Create the file**

```javascript
const ftp = require("basic-ftp");
const fs = require("fs");
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, ".env") });

async function uploadDir(client, localDir, remoteDir) {
    await client.ensureDir(remoteDir);
    const entries = fs.readdirSync(localDir, { withFileTypes: true });
    for (const entry of entries) {
        const localPath = path.join(localDir, entry.name);
        const remotePath = `${remoteDir}/${entry.name}`;
        if (entry.isDirectory()) {
            await uploadDir(client, localPath, remotePath);
        } else {
            process.stdout.write(`  ↑ ${entry.name}\n`);
            await client.uploadFrom(localPath, remotePath);
        }
    }
}

async function rollback(backupDir) {
    const client = new ftp.Client();
    try {
        await client.access({
            host: process.env.FTP_HOST,
            user: process.env.FTP_USER,
            password: process.env.FTP_PASS,
        });
        console.log("  ↑ Restoring files...");
        await uploadDir(client, backupDir, `${process.env.FTP_ROOT_LIVE}/public`);
        console.log("✅ Rollback complete.");
    } catch (err) {
        console.error("❌ Rollback error:", err.message);
    } finally {
        client.close();
    }
}

module.exports = { rollback };
```

- [ ] **Step 2: Verify it loads without errors**

```bash
node -e "require('./build-deploy/rollback.js'); console.log('OK')"
```

Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add build-deploy/rollback.js
git commit -m "feat: add FTP rollback module for live environment"
```

---

## Task 4: Create tests/smoke.js

**Files:**
- Create: `tests/smoke.js`

- [ ] **Step 1: Create tests/smoke.js**

```javascript
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, "../build-deploy/.env") });

const CHECKS = [
    { path: "/",                 contains: "<html" },
    { path: "/login.php",        contains: 'name="email"' },
    { path: "/leaderboard.php",  contains: "leaderboard" },
    { path: "/races.php",        contains: "<html" },
];

async function runSmoke(baseUrl) {
    console.log(`\n🧪 Running smoke tests against ${baseUrl}...`);
    let failed = 0;
    for (const check of CHECKS) {
        const label = `GET ${check.path}`.padEnd(28);
        try {
            const res = await fetch(`${baseUrl}${check.path}`);
            const body = await res.text();
            const ok = res.status === 200 && body.toLowerCase().includes(check.contains.toLowerCase());
            if (ok) {
                console.log(`  ✅ ${label} → 200`);
            } else {
                console.log(`  ❌ ${label} → ${res.status} (expected 200 with content)`);
                failed++;
            }
        } catch (err) {
            console.log(`  ❌ ${label} → ERROR: ${err.message}`);
            failed++;
        }
    }
    const total = CHECKS.length;
    if (failed > 0) {
        console.log(`❌ Smoke tests failed (${failed}/${total} failed)\n`);
        return false;
    }
    console.log(`✅ Smoke tests passed (${total}/${total})\n`);
    return true;
}

module.exports = { runSmoke };

if (require.main === module) {
    const baseUrl = process.env.BASE_URL || process.argv[2];
    if (!baseUrl) {
        console.error("Usage: BASE_URL=https://hpovlsen.dk node tests/smoke.js");
        process.exit(1);
    }
    runSmoke(baseUrl).then(ok => process.exit(ok ? 0 : 1));
}
```

- [ ] **Step 2: Run standalone against test site to verify it works**

```bash
BASE_URL=https://hpovlsen.dk node tests/smoke.js
```

Expected output:
```
🧪 Running smoke tests against https://hpovlsen.dk...
  ✅ GET /                      → 200
  ✅ GET /login.php              → 200
  ✅ GET /leaderboard.php        → 200
  ✅ GET /races.php              → 200
✅ Smoke tests passed (4/4)
```

- [ ] **Step 3: Commit**

```bash
git add tests/smoke.js
git commit -m "feat: add HTTP smoke tests"
```

---

## Task 5: Create tests/reporter.js and tests/playwright.config.js

**Files:**
- Create: `tests/reporter.js`
- Create: `tests/playwright.config.js`

- [ ] **Step 1: Create tests/reporter.js**

```javascript
class CustomReporter {
    constructor() {
        this._passed = 0;
        this._failed = 0;
    }

    onTestEnd(test, result) {
        if (result.status === "passed") {
            this._passed++;
            console.log(`  ✅ ${test.title}`);
        } else {
            this._failed++;
            const msg = result.error?.message?.split("\n")[0] || "failed";
            console.log(`  ❌ ${test.title} → ${msg}`);
        }
    }

    onEnd() {
        const total = this._passed + this._failed;
        if (this._failed > 0) {
            console.log(`❌ E2E tests failed (${this._failed}/${total} failed) — screenshots saved to build-deploy/screenshots/\n`);
        } else {
            console.log(`✅ E2E tests passed (${total}/${total})\n`);
        }
    }
}

module.exports = CustomReporter;
```

- [ ] **Step 2: Create tests/playwright.config.js**

```javascript
const { defineConfig, devices } = require("@playwright/test");

module.exports = defineConfig({
    testDir: "./e2e",
    timeout: 10000,
    outputDir: "../build-deploy/screenshots",
    reporter: [["./reporter.js"]],
    use: {
        baseURL: process.env.BASE_URL,
        screenshot: "only-on-failure",
    },
    projects: [
        { name: "chromium", use: { ...devices["Desktop Chrome"] } },
    ],
});
```

- [ ] **Step 3: Verify config loads without errors**

```bash
BASE_URL=https://hpovlsen.dk npx playwright test --config tests/playwright.config.js --list
```

Expected: lists test files (or "No tests found" — fine at this stage).

- [ ] **Step 4: Commit**

```bash
git add tests/reporter.js tests/playwright.config.js
git commit -m "feat: add Playwright config and custom reporter"
```

---

## Task 6: Create tests/e2e/smoke.spec.js

**Files:**
- Create: `tests/e2e/smoke.spec.js`

- [ ] **Step 1: Add credentials to build-deploy/.env**

Open `build-deploy/.env` and add these six lines (replace placeholder values with real credentials):

```
BASE_URL_TEST=https://hpovlsen.dk
BASE_URL_LIVE=https://formula-1.dk

TEST_USER_EMAIL_TEST=your-test-user@example.com
TEST_USER_PASSWORD_TEST=your-test-password
TEST_USER_EMAIL_LIVE=your-live-user@example.com
TEST_USER_PASSWORD_LIVE=your-live-password
```

- [ ] **Step 2: Create tests/e2e/smoke.spec.js**

```javascript
const { test, expect } = require("@playwright/test");

async function login(page) {
    await page.goto("/login.php");
    await page.fill('input[name="email"]', process.env.TEST_USER_EMAIL);
    await page.fill('input[name="password"]', process.env.TEST_USER_PASSWORD);
    await page.click('button[type="submit"]');
    await expect(page.locator('a[href="logout.php"]').first()).toBeVisible({ timeout: 5000 });
}

test("Public pages load", async ({ page }) => {
    for (const url of ["/", "/login.php", "/leaderboard.php", "/races.php"]) {
        const res = await page.goto(url);
        expect(res.status()).toBe(200);
    }
});

test("Login form renders", async ({ page }) => {
    await page.goto("/login.php");
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
});

test("Login succeeds", async ({ page }) => {
    await login(page);
});

test("Authenticated index visible", async ({ page }) => {
    await login(page);
    await page.goto("/");
    await expect(page.locator('a[href="logout.php"]').first()).toBeVisible();
});

test("Leaderboard has rows", async ({ page }) => {
    await page.goto("/leaderboard.php");
    await expect(page.locator("table.leaderboard-table tbody tr").first()).toBeVisible();
});

test("Races page loads", async ({ page }) => {
    await page.goto("/races.php");
    await expect(page.locator("body")).toBeVisible();
});

test("Rules page accessible", async ({ page }) => {
    await login(page);
    const res = await page.goto("/rules.php");
    expect(res.status()).toBe(200);
});

test("Bet page accessible", async ({ page }) => {
    await login(page);
    const res = await page.goto("/bet.php");
    expect(res.status()).toBe(200);
});
```

- [ ] **Step 3: Run E2E tests standalone against test site**

```bash
BASE_URL=https://hpovlsen.dk \
TEST_USER_EMAIL=your-test-user@example.com \
TEST_USER_PASSWORD=your-test-password \
npx playwright test --config tests/playwright.config.js
```

Expected output:
```
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

If any test fails, check the screenshot in `build-deploy/screenshots/` and adjust the selector.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/smoke.spec.js
git commit -m "feat: add Playwright E2E smoke tests"
```

---

## Task 7: Update build-deploy/deploy.js

**Files:**
- Modify: `build-deploy/deploy.js`

- [ ] **Step 1: Replace the full contents of build-deploy/deploy.js**

```javascript
const ftp = require("basic-ftp");
const fs = require("fs");
const path = require("path");
const readline = require("readline");
const { execFileSync } = require("child_process");
require("dotenv").config({ path: path.join(__dirname, ".env") });

const { backup, pruneBackups } = require("./backup");
const { rollback } = require("./rollback");
const { runSmoke } = require("../tests/smoke");

function loadIgnores() {
    const ignorePath = path.join(__dirname, ".deployignore");
    if (!fs.existsSync(ignorePath)) return [];
    return fs.readFileSync(ignorePath, "utf8")
        .split("\n")
        .map(line => line.trim())
        .filter(line => line && !line.startsWith("#"));
}

function isIgnored(relPath, ignores) {
    return ignores.some(pattern => {
        const normalized = relPath.replace(/\\/g, "/");
        return normalized === pattern || normalized.startsWith(pattern + "/");
    });
}

async function uploadDir(client, localDir, remoteDir, ignores) {
    await client.ensureDir(remoteDir);
    const entries = fs.readdirSync(localDir, { withFileTypes: true });
    for (const entry of entries) {
        const localPath = path.join(localDir, entry.name);
        const remotePath = `${remoteDir}/${entry.name}`;
        const relPath = path.relative(path.join(__dirname, "../public"), localPath).replace(/\\/g, "/");
        if (isIgnored(relPath, ignores)) continue;
        if (entry.isDirectory()) {
            await uploadDir(client, localPath, remotePath, ignores);
        } else {
            process.stdout.write(`  ↑ ${relPath}\n`);
            await client.uploadFrom(localPath, remotePath);
        }
    }
}

async function runTests(baseUrl, env) {
    const testEnv = {
        ...process.env,
        BASE_URL: baseUrl,
        TEST_USER_EMAIL: process.env[`TEST_USER_EMAIL_${env.toUpperCase()}`],
        TEST_USER_PASSWORD: process.env[`TEST_USER_PASSWORD_${env.toUpperCase()}`],
    };

    const smokeOk = await runSmoke(baseUrl);

    console.log(`🎭 Running Playwright E2E tests against ${baseUrl}...`);
    let e2eOk = true;
    try {
        execFileSync("npx", ["playwright", "test", "--config", "tests/playwright.config.js"], {
            stdio: "inherit",
            cwd: path.join(__dirname, ".."),
            env: testEnv,
        });
    } catch {
        e2eOk = false;
    }

    return smokeOk && e2eOk;
}

async function deploy() {
    const env = process.argv[2] || "test";
    const isLive = env === "live";
    const remoteDir = isLive ? process.env.FTP_ROOT_LIVE : process.env.FTP_ROOT_TEST;
    const baseUrl = isLive ? process.env.BASE_URL_LIVE : process.env.BASE_URL_TEST;
    const publicDir = path.join(__dirname, "../public");
    const ignores = loadIgnores();

    console.log(`🚀 Deploying to ${env.toUpperCase()}...`);

    if (process.env.DRY_RUN === "true") {
        console.log("⚠️ DRY_RUN: Skipping upload.");
        return;
    }

    let backupInfo = null;
    if (isLive) {
        backupInfo = await backup();
    }

    const client = new ftp.Client();
    try {
        await client.access({
            host: process.env.FTP_HOST,
            user: process.env.FTP_USER,
            password: process.env.FTP_PASS,
        });
        await uploadDir(client, publicDir, `${remoteDir}/public`, ignores);
        console.log(`✅ Done! Uploaded to ${remoteDir}`);
    } catch (err) {
        console.error("❌ FTP Error:", err.message);
        process.exit(1);
    } finally {
        client.close();
    }

    const testsOk = await runTests(baseUrl, env);

    if (!testsOk) {
        if (isLive && backupInfo) {
            console.log(`\n❌ Tests failed — rolling back to backup ${backupInfo.timestamp}...`);
            await rollback(backupInfo.backupDir);
        }
        console.log(`\n❌ Deploy to ${env.toUpperCase()} failed — fix and redeploy.`);
        process.exit(1);
    }

    if (isLive) {
        pruneBackups();
    }

    console.log(`\n✅ Deploy to ${env.toUpperCase()} complete. All tests passed.`);
}

async function main() {
    const env = process.argv[2] || "test";
    if (env === "live") {
        const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
        await new Promise(resolve =>
            rl.question("⚠️  Deploy to LIVE (formula-1.dk)? Type YES to confirm: ", answer => {
                rl.close();
                if (answer !== "YES") {
                    console.log("Aborted.");
                    process.exit(0);
                }
                resolve();
            })
        );
    }
    deploy();
}
main();
```

- [ ] **Step 2: Do a test deploy and verify the full output**

```bash
npm run deploy:test
```

Expected terminal output:
```
🚀 Deploying to TEST...
  ↑ .htaccess
  ↑ index.php
  ... (all files)
✅ Done! Uploaded to /hpovlsen.dk

🧪 Running smoke tests against https://hpovlsen.dk...
  ✅ GET /                      → 200
  ✅ GET /login.php              → 200
  ✅ GET /leaderboard.php        → 200
  ✅ GET /races.php              → 200
✅ Smoke tests passed (4/4)

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

✅ Deploy to TEST complete. All tests passed.
```

- [ ] **Step 3: Commit**

```bash
git add build-deploy/deploy.js
git commit -m "feat: wire backup, smoke tests, and Playwright E2E into deploy pipeline"
```

---

## Self-Review

**Spec coverage:**
- ✅ HTTP smoke tests: 4 public pages, status + content check
- ✅ Playwright E2E: 8 tests covering public + authenticated pages
- ✅ Backup before live deploy (`backup.js`)
- ✅ Rollback on test failure (`rollback.js`)
- ✅ No backup/rollback for test environment
- ✅ Tests run for both test and live environments
- ✅ Terminal output matches spec format
- ✅ Keep last 2 backups (`pruneBackups`)
- ✅ Credentials per environment (`TEST_USER_EMAIL_TEST` / `_LIVE`)

**Placeholder scan:** None — all steps contain real code and real commands.

**Type consistency:**
- `backup()` returns `{ timestamp, backupDir }` — used as `backupInfo.timestamp` and `backupInfo.backupDir` in deploy.js ✅
- `rollback(backupDir)` takes a string path — called with `backupInfo.backupDir` ✅
- `runSmoke(baseUrl)` returns `Promise<boolean>` — result used in `smokeOk` ✅
- `pruneBackups()` takes no args ✅
