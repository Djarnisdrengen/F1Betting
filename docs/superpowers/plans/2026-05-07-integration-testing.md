# Integration Test Suite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone `npm run test:integration` command that resets the test-site DB, seeds deterministic data, triggers the scoring engine, and asserts correct points, leaderboard order, and betting pool values via Playwright.

**Architecture:** A token-gated PHP seed endpoint (`public/test-seed.php`) is deployed only to hpovlsen.dk; the Playwright spec calls it in `beforeAll`, then asserts the leaderboard and race pages. `calculateRacePoints()` is extracted from `admin.php` into a shared `public/includes/scoring.php` with a pool-accumulation bug fix, so both admin UI and the seed script use identical logic.

**Tech Stack:** PHP/PDO (seed endpoint), @playwright/test (assertions), dotenv (env loading in config), basic-ftp (.deployignore exclusion)

---

## File Map

| Action | File | Purpose |
|--------|------|---------|
| Create | `public/includes/scoring.php` | Shared scoring logic (bug-fixed) |
| Modify | `public/admin.php:2–3, 391–497` | Add require_once, remove extracted function |
| Create | `public/test-seed.php` | Token-gated DB reset + seed endpoint |
| Create | `tests/playwright.integration.config.js` | Playwright config for integration tests |
| Create | `tests/e2e/integration.spec.js` | All integration assertions |
| Modify | `package.json` | Add `test:integration` script |
| Modify | `build-deploy/.deployignore` | Exclude test-seed.php from live |

---

## Task 1: Extract and fix `calculateRacePoints` into `scoring.php`

**Files:**
- Create: `public/includes/scoring.php`
- Modify: `public/admin.php` (lines 2–3 and 391–497)

- [ ] **Step 1: Create `public/includes/scoring.php`**

The key fix is replacing `$previousRace['bettingpool_size']` with `$currentRace['bettingpool_size']` so the pool carries forward correctly (the first-race special case is no longer needed and is removed).

```php
<?php
require_once __DIR__ . '/../../config.php';

function calculateRacePoints($raceId, $p1, $p2, $p3) {
    global $db;
    $results = [$p1, $p2, $p3];

    $settings       = getSettings();
    $pointsP1       = $settings['points_p1']       ?? 25;
    $pointsP2       = $settings['points_p2']       ?? 18;
    $pointsP3       = $settings['points_p3']       ?? 15;
    $pointsWrongPos = $settings['points_wrong_pos'] ?? 5;

    $stmt = $db->prepare("SELECT * FROM bets WHERE race_id = ?");
    $stmt->execute([$raceId]);
    $bets = $stmt->fetchAll();

    $db->prepare("UPDATE races SET bettingpool_won = 0 WHERE id = ?")->execute([$raceId]);

    foreach ($bets as $bet) {
        $oldPoints    = $bet['points'];
        $oldIsPerfect = $bet['is_perfect'];
        $predictions  = [$bet['p1'], $bet['p2'], $bet['p3']];

        $points = 0;
        if ($bet['p1'] === $p1) $points += $pointsP1;
        if ($bet['p2'] === $p2) $points += $pointsP2;
        if ($bet['p3'] === $p3) $points += $pointsP3;

        foreach ($predictions as $i => $pred) {
            $ri = array_search($pred, $results);
            if ($ri !== false && $ri !== $i) $points += $pointsWrongPos;
        }

        $isPerfect = ($bet['p1'] === $p1 && $bet['p2'] === $p2 && $bet['p3'] === $p3) ? 1 : 0;

        if ($isPerfect) {
            $db->prepare("UPDATE races SET bettingpool_won = 1 WHERE id = ?")->execute([$raceId]);
        }

        $stmtRace = $db->prepare("SELECT * FROM races WHERE id = ?");
        $stmtRace->execute([$raceId]);
        $currentRace = $stmtRace->fetch(PDO::FETCH_ASSOC);

        $stmtNext = $db->prepare("SELECT * FROM races WHERE race_date > ? ORDER BY race_date ASC LIMIT 1");
        $stmtNext->execute([$currentRace['race_date']]);
        $upcomingRace = $stmtNext->fetch(PDO::FETCH_ASSOC);

        if ($upcomingRace !== false) {
            $stmtCount = $db->prepare("SELECT COUNT(*) as count FROM users WHERE in_competition = 1");
            $stmtCount->execute();
            $numberOfBetters = $stmtCount->fetch()['count'] ?? 0;
            $betSize     = $settings['bet_size'] ?? 0;
            $newPoolSize = $numberOfBetters * $betSize;

            if (!$isPerfect) {
                $newPoolSize += $currentRace['bettingpool_size']; // fixed: was previousRace
            }

            $db->prepare("UPDATE races SET bettingpool_size = ? WHERE id = ?")
               ->execute([$newPoolSize, $upcomingRace['id']]);
        }

        $db->prepare("UPDATE bets SET points = ?, is_perfect = ? WHERE id = ?")
           ->execute([$points, $isPerfect, $bet['id']]);

        $stmtUser = $db->prepare("SELECT points, stars FROM users WHERE id = ?");
        $stmtUser->execute([$bet['user_id']]);
        $user = $stmtUser->fetch();

        $newPoints = $user['points'] - $oldPoints + $points;
        $newStars  = $user['stars']  - ($oldIsPerfect ? 1 : 0) + $isPerfect;

        $db->prepare("UPDATE users SET points = ?, stars = ? WHERE id = ?")
           ->execute([max(0, $newPoints), max(0, $newStars), $bet['user_id']]);
    }
}
```

- [ ] **Step 2: Add `require_once` to `admin.php` and remove the old function**

In `public/admin.php`, after line 3 (`require_once __DIR__ . '/functions.php';`), add:

```php
require_once __DIR__ . '/includes/scoring.php';
```

Then delete lines 391–497 (the entire `calculateRacePoints` function body, from `function calculateRacePoints(...)  {` to its closing `}`). The function now comes from `scoring.php`.

- [ ] **Step 3: Verify no regression — run existing E2E tests**

```bash
npm run test:e2e
```

Expected: all 8 smoke tests pass. If any fail, check that the `require_once` path is correct relative to `admin.php` and that `global $db` still resolves inside the extracted function.

- [ ] **Step 4: Commit**

```bash
git add public/includes/scoring.php public/admin.php
git commit -m "refactor: extract calculateRacePoints to scoring.php, fix pool accumulation"
```

---

## Task 2: Create Playwright integration config and npm script

**Files:**
- Create: `tests/playwright.integration.config.js`
- Modify: `package.json`

- [ ] **Step 1: Create `tests/playwright.integration.config.js`**

```js
const { defineConfig, devices } = require("@playwright/test");
require("dotenv").config({ path: require("path").join(__dirname, "../build-deploy/.env") });

module.exports = defineConfig({
    testDir: "./e2e",
    testMatch: "**/integration.spec.js",
    timeout: 15000,
    outputDir: "../build-deploy/screenshots",
    reporter: [["./reporter.js"]],
    use: {
        baseURL: process.env.INTEGRATION_BASE_URL,
        screenshot: "only-on-failure",
    },
    projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
    workers: 1,
});
```

- [ ] **Step 2: Add `test:integration` script to `package.json`**

In `package.json`, add to the `scripts` object:

```json
"test:integration": "npx playwright test --config tests/playwright.integration.config.js"
```

- [ ] **Step 3: Commit**

```bash
git add tests/playwright.integration.config.js package.json
git commit -m "feat: add Playwright integration test config and npm script"
```

---

## Task 3: Write the failing integration spec

**Files:**
- Create: `tests/e2e/integration.spec.js`

- [ ] **Step 1: Create `tests/e2e/integration.spec.js`**

```js
const { test, expect } = require("@playwright/test");

const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;

test.beforeAll(async ({ request }) => {
    const res = await request.get(`/test-seed.php?token=${SEED_TOKEN}`);
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.ok).toBe(true);
});

test("Leaderboard row order", async ({ page }) => {
    await page.goto("/leaderboard.php");
    const rows = page.locator("table.leaderboard-table tbody tr");
    await expect(rows.nth(0)).toContainText("Alice");
    await expect(rows.nth(1)).toContainText("Bob");
    await expect(rows.nth(2)).toContainText("Charlie");
});

test("Alice — 220 points, 1 star", async ({ page }) => {
    await page.goto("/leaderboard.php");
    const row = page.locator("table.leaderboard-table tbody tr").nth(0);
    await expect(row.locator("span.text-accent")).toHaveText("220");
    await expect(row.locator("span.star")).toHaveText("★1");
});

test("Bob — 140 points, no star", async ({ page }) => {
    await page.goto("/leaderboard.php");
    const row = page.locator("table.leaderboard-table tbody tr").nth(1);
    await expect(row.locator("span.text-accent")).toHaveText("140");
    await expect(row.locator("span.star")).toHaveCount(0);
});

test("Charlie — 65 points, no star", async ({ page }) => {
    await page.goto("/leaderboard.php");
    const row = page.locator("table.leaderboard-table tbody tr").nth(2);
    await expect(row.locator("span.text-accent")).toHaveText("65");
    await expect(row.locator("span.star")).toHaveCount(0);
});

test("Race 2 pool size is 60", async ({ page }) => {
    await page.goto("/races.php");
    const card = page.locator(".race-card").filter({
        has: page.locator("h3.race-title", { hasText: "Race 2" }),
    });
    await expect(card.locator("span.bettingpool_size")).toHaveText("60");
});

test("Race 3 pool size is 90", async ({ page }) => {
    await page.goto("/races.php");
    const card = page.locator(".race-card").filter({
        has: page.locator("h3.race-title", { hasText: "Race 3" }),
    });
    await expect(card.locator("span.bettingpool_size")).toHaveText("90");
});

test("Race 4 pool size is 30 — reset after perfect bet", async ({ page }) => {
    await page.goto("/races.php");
    const card = page.locator(".race-card").filter({
        has: page.locator("h3.race-title", { hasText: "Race 4" }),
    });
    await expect(card.locator("span.bettingpool_size")).toHaveText("30");
});

test("Race 5 pool size is 60", async ({ page }) => {
    await page.goto("/races.php");
    const card = page.locator(".race-card").filter({
        has: page.locator("h3.race-title", { hasText: "Race 5" }),
    });
    await expect(card.locator("span.bettingpool_size")).toHaveText("60");
});
```

- [ ] **Step 2: Add `INTEGRATION_SEED_TOKEN` and `INTEGRATION_BASE_URL` to `build-deploy/.env`**

Generate a token and add to `build-deploy/.env`:

```
INTEGRATION_BASE_URL=https://hpovlsen.dk
INTEGRATION_SEED_TOKEN=<run: openssl rand -hex 24>
```

- [ ] **Step 3: Run to confirm it fails (seed endpoint does not exist yet)**

```bash
npm run test:integration
```

Expected: `beforeAll` hook fails — all 8 tests marked failed with 404 or connection error. This confirms the test is wired correctly and will only pass once the seed endpoint exists.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/integration.spec.js
git commit -m "test: add failing integration spec (seed endpoint not yet implemented)"
```

---

## Task 4: Create `public/test-seed.php`

**Files:**
- Create: `public/test-seed.php`

- [ ] **Step 1: Add `INTEGRATION_SEED_TOKEN` constant to the server's `config.php` on hpovlsen.dk**

This is a one-time manual step. FTP into hpovlsen.dk, open `config.php`, and add:

```php
define('INTEGRATION_SEED_TOKEN', '<same token as in build-deploy/.env>');
```

The server's `config.php` is preserved across deploys (it is in `.deployignore`), so this survives future uploads.

- [ ] **Step 2: Create `public/test-seed.php`**

```php
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/scoring.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if (!defined('INTEGRATION_SEED_TOKEN') || $token !== INTEGRATION_SEED_TOKEN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$db = getDB();

// Reset to known state — settings table is preserved
$db->query("UPDATE settings SET bet_size = 10");
$db->query("DELETE FROM bets");
$db->query("DELETE FROM users");
$db->query("DELETE FROM drivers");
$db->query("DELETE FROM races");

function seed_uuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Users — shared test password, all in competition
$hash = password_hash('Integration2026!', PASSWORD_BCRYPT);
$uids = [];
foreach ([
    ['Alice',   'alice@test.local'],
    ['Bob',     'bob@test.local'],
    ['Charlie', 'charlie@test.local'],
] as [$name, $email]) {
    $id = seed_uuid();
    $uids[$name] = $id;
    $db->prepare("INSERT INTO users (id, email, password, display_name, in_competition, points, stars) VALUES (?, ?, ?, ?, 1, 0, 0)")
       ->execute([$id, $email, $hash, $name]);
}

// Drivers — $d[number] = UUID
$d = [];
foreach ([
    [44, 'Lewis Hamilton',  'Mercedes'],
    [63, 'George Russell',  'Mercedes'],
    [1,  'Max Verstappen',  'Red Bull'],
    [11, 'Sergio Perez',    'Red Bull'],
    [16, 'Charles Leclerc', 'Ferrari'],
    [55, 'Carlos Sainz',    'Ferrari'],
    [4,  'Lando Norris',    'McLaren'],
    [81, 'Oscar Piastri',   'McLaren'],
    [14, 'Fernando Alonso', 'Aston Martin'],
    [18, 'Lance Stroll',    'Aston Martin'],
] as [$num, $name, $team]) {
    $id = seed_uuid();
    $d[$num] = $id;
    $db->prepare("INSERT INTO drivers (id, name, team, number) VALUES (?, ?, ?, ?)")
       ->execute([$id, $name, $team, $num]);
}

// Races — [name, date, initial_bettingpool_size, rp1, rp2, rp3]
// Race 1 pool seeded as 3 users x bet_size 10 = 30
$rids = []; // name => [id, rp1, rp2, rp3]
foreach ([
    ['Race 1', '2026-01-01', 30, $d[44], $d[63], $d[1]],
    ['Race 2', '2026-02-01', 0,  $d[11], $d[16], $d[55]],
    ['Race 3', '2026-03-01', 0,  $d[4],  $d[81], $d[14]],
    ['Race 4', '2026-04-01', 0,  $d[44], $d[1],  $d[16]],
    ['Race 5', '2026-05-01', 0,  $d[11], $d[55], $d[81]],
] as [$name, $date, $pool, $rp1, $rp2, $rp3]) {
    $id = seed_uuid();
    $rids[$name] = [$id, $rp1, $rp2, $rp3];
    $db->prepare("INSERT INTO races (id, name, race_date, bettingpool_size, result_p1, result_p2, result_p3) VALUES (?, ?, ?, ?, ?, ?, ?)")
       ->execute([$id, $name, $date, $pool, $rp1, $rp2, $rp3]);
}

// Bets — Race 3: Bob and Charlie inserted before Alice so her perfect bet
// is last in SELECT order, giving deterministic pool write for Race 4.
foreach ([
    // Race 1
    [$uids['Alice'],   $rids['Race 1'][0], $d[44], $d[63], $d[11]],
    [$uids['Bob'],     $rids['Race 1'][0], $d[44], $d[1],  $d[63]],
    [$uids['Charlie'], $rids['Race 1'][0], $d[63], $d[44], $d[1]],
    // Race 2
    [$uids['Alice'],   $rids['Race 2'][0], $d[11], $d[16], $d[4]],
    [$uids['Bob'],     $rids['Race 2'][0], $d[16], $d[11], $d[55]],
    [$uids['Charlie'], $rids['Race 2'][0], $d[55], $d[4],  $d[16]],
    // Race 3 — Alice LAST (her perfect bet must be the final pool update)
    [$uids['Bob'],     $rids['Race 3'][0], $d[4],  $d[14], $d[81]],
    [$uids['Charlie'], $rids['Race 3'][0], $d[81], $d[4],  $d[18]],
    [$uids['Alice'],   $rids['Race 3'][0], $d[4],  $d[81], $d[14]], // PERFECT
    // Race 4
    [$uids['Alice'],   $rids['Race 4'][0], $d[63], $d[1],  $d[16]],
    [$uids['Bob'],     $rids['Race 4'][0], $d[44], $d[16], $d[1]],
    [$uids['Charlie'], $rids['Race 4'][0], $d[1],  $d[44], $d[63]],
    // Race 5
    [$uids['Alice'],   $rids['Race 5'][0], $d[11], $d[55], $d[18]],
    [$uids['Bob'],     $rids['Race 5'][0], $d[16], $d[11], $d[55]],
    [$uids['Charlie'], $rids['Race 5'][0], $d[81], $d[16], $d[11]],
] as [$uid, $rid, $bp1, $bp2, $bp3]) {
    $db->prepare("INSERT INTO bets (id, user_id, race_id, p1, p2, p3, points, is_perfect) VALUES (?, ?, ?, ?, ?, ?, 0, 0)")
       ->execute([seed_uuid(), $uid, $rid, $bp1, $bp2, $bp3]);
}

// Run scoring engine for all races in chronological order
foreach (['Race 1', 'Race 2', 'Race 3', 'Race 4', 'Race 5'] as $raceName) {
    [$rid, $rp1, $rp2, $rp3] = $rids[$raceName];
    calculateRacePoints($rid, $rp1, $rp2, $rp3);
}

echo json_encode(['ok' => true]);
```

- [ ] **Step 3: Deploy to hpovlsen.dk**

```bash
npm run deploy:test
```

This uploads `public/test-seed.php` and the updated `public/includes/scoring.php` + `public/admin.php` to the test server.

- [ ] **Step 4: Run integration tests — all 8 should pass**

```bash
npm run test:integration
```

Expected output:
```
✅ Leaderboard row order
✅ Alice — 220 points, 1 star
✅ Bob — 140 points, no star
✅ Charlie — 65 points, no star
✅ Race 2 pool size is 60
✅ Race 3 pool size is 90
✅ Race 4 pool size is 30 — reset after perfect bet
✅ Race 5 pool size is 60
```

If a pool test fails with the wrong value, check that `calculateRacePoints` ran in date order and `bet_size = 10` was set. If a points test fails, verify the bet/result combinations in `test-seed.php` match the spec tables in `docs/superpowers/specs/2026-05-07-integration-testing-design.md`.

- [ ] **Step 5: Commit**

```bash
git add public/test-seed.php
git commit -m "feat: add test-seed.php — token-gated DB seed endpoint for integration tests"
```

---

## Task 5: Exclude `test-seed.php` from live deploys

**Files:**
- Modify: `build-deploy/.deployignore`

- [ ] **Step 1: Add entry to `.deployignore`**

In `build-deploy/.deployignore`, add:

```
test-seed.php
```

The `isIgnored()` function in `deploy.js` matches `relPath` (relative to `public/`) against these patterns, so `test-seed.php` (not `public/test-seed.php`) is the correct entry.

- [ ] **Step 2: Verify the entry**

```bash
grep "test-seed" build-deploy/.deployignore
```

Expected: `test-seed.php`

- [ ] **Step 3: Commit**

```bash
git add build-deploy/.deployignore
git commit -m "chore: exclude test-seed.php from live deploys"
```

---

## Expected final state

```
public/
  includes/
    scoring.php                          ← new — shared calculateRacePoints (bug-fixed)
  admin.php                              ← modified — require_once scoring.php, function removed
  test-seed.php                          ← new — excluded from live via .deployignore
tests/
  e2e/
    integration.spec.js                  ← new
  playwright.integration.config.js       ← new
package.json                             ← test:integration script added
build-deploy/
  .deployignore                          ← test-seed.php added
  .env                                   ← INTEGRATION_BASE_URL + INTEGRATION_SEED_TOKEN added
```

Run `npm run test:integration` at any time to reset hpovlsen.dk test data and verify scoring correctness.
