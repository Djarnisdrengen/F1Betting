# Sync Live → Test Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `npm run sync:live` that overwrites all non-settings data on hpovlsen.dk with a live copy from formula-1.dk.

**Architecture:** A token-gated PHP endpoint on the test server (`sync-from-live.php`) opens a second PDO connection to the live DB (same MySQL host/credentials, different DB name defined by `LIVE_DB_NAME` in `config.php`). It drops any `old_` prefixed tables, deletes the four data tables in FK-safe order, then inserts rows from live in FK-safe order. A Node.js runner script (`sync.js`) calls the endpoint and prints the result.

**Tech Stack:** PHP/PDO, Node.js (built-in `fetch`, requires Node 18+), dotenv

---

## Pre-requisite (manual — do once on hpovlsen.dk server)

On the **test server only**, add to `/config.php` (the server-side file, never committed):

```php
define('LIVE_DB_NAME', '<actual_live_db_name>');
```

The live server's `config.php` does not need any changes.

---

## File Structure

```
public/
  sync-from-live.php        ← CREATE — PHP endpoint (test server only)
build-deploy/
  sync.js                   ← CREATE — Node.js runner
  .deployignore             ← MODIFY — exclude sync-from-live.php
package.json                ← MODIFY — add sync:live script
```

---

## Task 1: PHP sync endpoint

**Files:**
- Create: `public/sync-from-live.php`

No automated test is possible before writing this file — it requires two real DB connections (test + live). Verification is done in Task 3 via `npm run sync:live`.

- [ ] **Step 1: Create `public/sync-from-live.php`**

```php
<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if (!defined('INTEGRATION_SEED_TOKEN') || $token !== INTEGRATION_SEED_TOKEN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if (!defined('LIVE_DB_NAME')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'LIVE_DB_NAME not defined in config.php']);
    exit;
}

$db = getDB();

try {
    $live = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . LIVE_DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Live DB connection failed: ' . $e->getMessage()]);
    exit;
}

try {
    // Drop any old_ prefixed tables (legacy leftovers on test site)
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $droppedCount = 0;
    foreach ($tables as $table) {
        if (strpos($table, 'old_') === 0) {
            $db->query("DROP TABLE IF EXISTS `$table`");
            $droppedCount++;
        }
    }

    // Delete in FK-safe order (dependents first)
    $db->query("DELETE FROM bets");
    $db->query("DELETE FROM users");
    $db->query("DELETE FROM races");
    $db->query("DELETE FROM drivers");

    // Copy in FK-safe order (parents first)
    $copied = [];
    foreach (['drivers', 'users', 'races', 'bets'] as $table) {
        $rows = $live->query("SELECT * FROM `$table`")->fetchAll();
        $copied[$table] = count($rows);
        if (empty($rows)) {
            continue;
        }
        $cols = array_keys($rows[0]);
        $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $db->prepare("INSERT INTO `$table` ($colList) VALUES ($placeholders)");
        foreach ($rows as $row) {
            $stmt->execute(array_values($row));
        }
    }

    echo json_encode([
        'ok' => true,
        'dropped_old_tables' => $droppedCount,
        'copied' => $copied,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
```

- [ ] **Step 2: Commit**

```bash
git add public/sync-from-live.php
git commit -m "feat: add sync-from-live.php — token-gated live→test DB sync endpoint"
```

---

## Task 2: Node.js runner + package.json + .deployignore

**Files:**
- Create: `build-deploy/sync.js`
- Modify: `package.json`
- Modify: `build-deploy/.deployignore`

- [ ] **Step 1: Create `build-deploy/sync.js`**

```js
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, ".env") });

async function sync() {
    const baseUrl = process.env.INTEGRATION_BASE_URL;
    const token = process.env.INTEGRATION_SEED_TOKEN;

    if (!baseUrl || !token) {
        console.error("❌ INTEGRATION_BASE_URL and INTEGRATION_SEED_TOKEN must be set in build-deploy/.env");
        process.exit(1);
    }

    const url = `${baseUrl}/sync-from-live.php?token=${token}`;
    console.log("🔄 Syncing live data to test site...");

    let res;
    try {
        res = await fetch(url);
    } catch (err) {
        console.error("❌ Request failed:", err.message);
        process.exit(1);
    }

    let body;
    try {
        body = await res.json();
    } catch {
        console.error(`❌ Invalid JSON response (HTTP ${res.status})`);
        process.exit(1);
    }

    if (!res.ok || !body.ok) {
        console.error("❌ Sync failed:", body.error ?? `HTTP ${res.status}`);
        process.exit(1);
    }

    const { dropped_old_tables: dropped, copied } = body;
    const parts = [];
    if (dropped > 0) parts.push(`${dropped} old_ tables dropped`);
    parts.push(`${copied.drivers} drivers`);
    parts.push(`${copied.users} users`);
    parts.push(`${copied.races} races`);
    parts.push(`${copied.bets} bets copied`);
    console.log("✅ Sync complete:", parts.join(", "));
}

sync();
```

- [ ] **Step 2: Add `sync:live` to `package.json`**

Open `package.json`. Add to the `"scripts"` object:

```json
"sync:live": "node build-deploy/sync.js"
```

Full scripts block after edit:

```json
"scripts": {
    "deploy:test": "node build-deploy/deploy.js test",
    "deploy:live": "node build-deploy/deploy.js live",
    "setup:deploy": "node build-deploy/setup-deployment.js",
    "test:smoke": "node tests/smoke.js",
    "test:e2e": "npx playwright test --config tests/playwright.config.js",
    "test:integration": "npx playwright test --config tests/playwright.integration.config.js",
    "test:all": "npm run test:smoke && npm run test:e2e",
    "sync:live": "node build-deploy/sync.js"
}
```

- [ ] **Step 3: Add `sync-from-live.php` to `build-deploy/.deployignore`**

Open `build-deploy/.deployignore`. Add a new line:

```
sync-from-live.php
```

Full file after edit:

```
config.php
cron/cron_import_log.txt
.git
node_modules
build-deploy/
.env
test-seed.php
sync-from-live.php
```

- [ ] **Step 4: Commit**

```bash
git add build-deploy/sync.js package.json build-deploy/.deployignore
git commit -m "feat: add sync:live command — Node runner + deployignore"
```

---

## Task 3: Smoke test

No automated test can verify this feature end-to-end without a deployed test server. Follow these steps to verify manually.

**Pre-conditions:**
- `LIVE_DB_NAME` is defined in the test server's `config.php` (see pre-requisite above)
- `INTEGRATION_BASE_URL` and `INTEGRATION_SEED_TOKEN` are set in `build-deploy/.env`

- [ ] **Step 1: Deploy to test**

```bash
npm run deploy:test
```

Expected: upload completes, smoke tests pass. Confirm `sync-from-live.php` appears in the upload log (it should — it's not excluded from test deploys).

- [ ] **Step 2: Verify `.deployignore` excludes it from live**

```bash
node -e "
const fs = require('fs');
const path = require('path');
const ignores = fs.readFileSync('build-deploy/.deployignore', 'utf8').split('\n').map(l => l.trim()).filter(Boolean);
console.log(ignores.includes('sync-from-live.php') ? '✅ sync-from-live.php is excluded from live' : '❌ MISSING from .deployignore');
"
```

Expected output: `✅ sync-from-live.php is excluded from live`

- [ ] **Step 3: Run the sync**

```bash
npm run sync:live
```

Expected output (counts will vary based on live data):

```
🔄 Syncing live data to test site...
✅ Sync complete: 20 drivers, 5 users, 10 races, 48 bets copied
```

If there were `old_` tables, the line will start with e.g. `3 old_ tables dropped, `.

- [ ] **Step 4: Verify token gate rejects bad tokens**

```bash
curl -s "$(grep INTEGRATION_BASE_URL build-deploy/.env | cut -d= -f2)/sync-from-live.php?token=badtoken"
```

Expected: `{"ok":false,"error":"Forbidden"}` (HTTP 403)

- [ ] **Step 5: Run integration tests to confirm test site data is still functional**

```bash
npm run test:integration
```

Expected: all tests pass (seed endpoint will overwrite the just-synced data with deterministic test data, then assertions run).
