# Integration Test Suite — Design Spec
Date: 2026-05-07

## Overview

A standalone integration test (`npm run test:integration`) that resets the test-site database, seeds deterministic data, triggers all scoring logic, and asserts correct points, leaderboard order, and betting pool values via Playwright.

Runs against `hpovlsen.dk` only. Never part of the automated deploy pipeline.

---

## Components

### 1. Bug fix — pool accumulation in `calculateRacePoints()`

**File:** `public/admin.php` (and later `public/includes/scoring.php` after extraction)

**Current code (bug):**
```php
if (!$isPerfect) {
    if ($previousRace !== false) {
        $newPoolSize += $previousRace['bettingpool_size']; // ← one race too far back
    } else {
        $newPoolSize += $currentRace['bettingpool_size'];
    }
}
```

**Fixed code:**
```php
if (!$isPerfect) {
    $newPoolSize += $currentRace['bettingpool_size']; // always carry current race's pool
}
```

The first-race special case is no longer needed — using `$currentRace` works for all races uniformly.

---

### 2. Extract `calculateRacePoints()` to shared file

**New file:** `public/includes/scoring.php`

Move `calculateRacePoints($raceId, $p1, $p2, $p3)` out of `admin.php` into this file. Both `admin.php` and `public/test-seed.php` will `require_once` it.

No behavior changes beyond the bug fix above.

---

### 3. Seed endpoint

**New file:** `public/test-seed.php`

Token-gated HTTP endpoint. Called by the test runner before assertions.

**Security:**
- Requires `?token=<INTEGRATION_SEED_TOKEN>` — returns HTTP 403 otherwise
- Excluded from live FTP deploy (added to exclusion list in `build-deploy/deploy.js`)
- Only exists on `hpovlsen.dk`

**Actions (in order):**
1. Validate token
2. `UPDATE settings SET bet_size = 10` — ensures known value; leaves all other settings intact
3. Truncate in safe order: `bets`, `users`, `drivers`, `races` (settings table untouched)
4. Insert 3 users with UUID ids, known bcrypt-hashed password, `in_competition = 1`
5. Insert 10 drivers across 5 teams
6. Insert 5 races with sequential dates and `bettingpool_size` set correctly for race 1
7. Insert bets per the data table below (race 3 bets inserted in order: Bob, Charlie, Alice)
8. Call `calculateRacePoints()` for races 1 → 5 in date order
9. Return `{"ok": true}`

**User passwords** come from `INTEGRATION_USER_PASSWORD` in `.env`. All 3 users share the same password for simplicity.

---

### 4. Integration Playwright spec

**New file:** `tests/e2e/integration.spec.js`

**New config:** `tests/playwright.integration.config.js`
- `testDir`: `./e2e`
- `testMatch`: `**/integration.spec.js`
- `use.baseURL`: `process.env.INTEGRATION_BASE_URL`
- Same reporter and screenshot settings as existing config

**Structure:**
```
test.beforeAll → GET /test-seed.php?token=...  (assert 200 + {ok:true})

test("Leaderboard row order")   → rows: Alice, Bob, Charlie
test("Alice points")            → 220
test("Bob points")              → 140
test("Charlie points")          → 65
test("Alice stars")             → 1
test("Bob stars")               → 0
test("Race 2 pool size")        → 60
test("Race 3 pool size")        → 90
test("Race 4 pool size — reset after perfect bet") → 30
test("Race 5 pool size")        → 60
```

Leaderboard selector: `table.leaderboard-table tbody tr`
Pool selector: `span.bettingpool_size` (one per race card on `races.php`)

---

### 5. `package.json` script

```json
"test:integration": "npx playwright test --config tests/playwright.integration.config.js"
```

Run with: `npm run test:integration`  
Requires `INTEGRATION_BASE_URL` and `INTEGRATION_SEED_TOKEN` set in `build-deploy/.env`.

---

### 6. `.env` additions

```
INTEGRATION_BASE_URL=https://hpovlsen.dk
INTEGRATION_SEED_TOKEN=<random secret, generate once>
INTEGRATION_USER_PASSWORD=<password for all 3 seeded users>
```

---

### 7. `build-deploy/deploy.js` — exclude seed file from live

Add `public/test-seed.php` to the exclusion list so it is never uploaded to `formula-1.dk`.

---

## Data Design

### Settings (updated by seed)
| key | value |
|-----|-------|
| bet_size | 10 |

### Users
| display_name | email | in_competition |
|---|---|---|
| Alice | alice@test.local | 1 |
| Bob | bob@test.local | 1 |
| Charlie | charlie@test.local | 1 |

Password: value of `INTEGRATION_USER_PASSWORD` (same for all three).

### Drivers & Teams
| # | name | team |
|---|------|------|
| 44 | Lewis Hamilton | Mercedes |
| 63 | George Russell | Mercedes |
| 1 | Max Verstappen | Red Bull |
| 11 | Sergio Perez | Red Bull |
| 16 | Charles Leclerc | Ferrari |
| 55 | Carlos Sainz | Ferrari |
| 4 | Lando Norris | McLaren |
| 81 | Oscar Piastri | McLaren |
| 14 | Fernando Alonso | Aston Martin |
| 18 | Lance Stroll | Aston Martin |

Shorthand used below: D1=Hamilton, D2=Russell, D3=Verstappen, D4=Perez, D5=Leclerc, D6=Sainz, D7=Norris, D8=Piastri, D9=Alonso, D10=Stroll.

### Races, Bets & Expected Points

**Race 1** — 2026-01-01 — Results: P1=D1, P2=D2, P3=D3 — seed `bettingpool_size = 30` (3 users × 10)

| User | Bet P1 | Bet P2 | Bet P3 | Calc | pts |
|------|--------|--------|--------|------|-----|
| Alice | D1 ✓ | D2 ✓ | D4 ✗ | 25+18+0 | 43 |
| Bob | D1 ✓ | D3 ↔ | D2 ↔ | 25+5+5 | 35 |
| Charlie | D2 ↔ | D1 ↔ | D3 ✓ | 5+5+15 | 25 |

No perfect bet. Pool for R2 = base(30) + R1.pool(30) = **60**.

---

**Race 2** — 2026-02-01 — Results: P1=D4, P2=D5, P3=D6 — pool set to 60 by engine

| User | Bet P1 | Bet P2 | Bet P3 | Calc | pts |
|------|--------|--------|--------|------|-----|
| Alice | D4 ✓ | D5 ✓ | D7 ✗ | 25+18+0 | 43 |
| Bob | D5 ↔ | D4 ↔ | D6 ✓ | 5+5+15 | 25 |
| Charlie | D6 ↔ | D7 ✗ | D5 ↔ | 5+0+5 | 10 |

No perfect bet. Pool for R3 = base(30) + R2.pool(60) = **90**.

---

**Race 3** — 2026-03-01 — Results: P1=D7, P2=D8, P3=D9 — pool set to 90 by engine

Bets inserted in order: Bob, Charlie, **Alice last** (ensures her perfect bet is the final pool update written).

| User | Bet P1 | Bet P2 | Bet P3 | Calc | pts | perfect |
|------|--------|--------|--------|------|-----|---------|
| Bob | D7 ✓ | D9 ↔ | D8 ↔ | 25+5+5 | 35 | — |
| Charlie | D8 ↔ | D7 ↔ | D10 ✗ | 5+5+0 | 10 | — |
| Alice | D7 ✓ | D8 ✓ | D9 ✓ | 25+18+15 | 58 | ★ +1 star |

Perfect bet (Alice, processed last). Pool for R4 = base(30) only = **30**.

---

**Race 4** — 2026-04-01 — Results: P1=D1, P2=D3, P3=D5 — pool set to 30 by engine

| User | Bet P1 | Bet P2 | Bet P3 | Calc | pts |
|------|--------|--------|--------|------|-----|
| Alice | D2 ✗ | D3 ✓ | D5 ✓ | 0+18+15 | 33 |
| Bob | D1 ✓ | D5 ↔ | D3 ↔ | 25+5+5 | 35 |
| Charlie | D3 ↔ | D1 ↔ | D2 ✗ | 5+5+0 | 10 |

No perfect bet. Pool for R5 = base(30) + R4.pool(30) = **60**.

---

**Race 5** — 2026-05-01 — Results: P1=D4, P2=D6, P3=D8 — pool set to 60 by engine

| User | Bet P1 | Bet P2 | Bet P3 | Calc | pts |
|------|--------|--------|--------|------|-----|
| Alice | D4 ✓ | D6 ✓ | D10 ✗ | 25+18+0 | 43 |
| Bob | D5 ✗ | D4 ↔ | D6 ↔ | 0+5+5 | 10 |
| Charlie | D8 ↔ | D5 ✗ | D4 ↔ | 5+0+5 | 10 |

No perfect bet. No race 6, pool update is skipped.

---

### Final User Totals

| User | R1 | R2 | R3 | R4 | R5 | Total pts | Stars | Rank |
|------|----|----|----|----|-----|-----------|-------|------|
| Alice | 43 | 43 | 58 | 33 | 43 | **220** | **1** | 1st |
| Bob | 35 | 25 | 35 | 35 | 10 | **140** | 0 | 2nd |
| Charlie | 25 | 10 | 10 | 10 | 10 | **65** | 0 | 3rd |

Leaderboard order: stars DESC, points DESC → Alice, Bob, Charlie.

### Pool Summary

| Race | Pool value | Reason |
|------|-----------|--------|
| R1 | 30 | Seeded (3 × 10) |
| R2 | 60 | base + R1.pool |
| R3 | 90 | base + R2.pool |
| R4 | 30 | Reset — Alice perfect in R3 |
| R5 | 60 | base + R4.pool |

Legend: ✓ exact position · ↔ driver in top 3 but wrong position (+5) · ✗ driver not in top 3

---

## Key Notes

- The pool accumulation bug fix changes the formula from `previousRace.bettingpool_size` to `currentRace.bettingpool_size`. This is a real production fix, not test scaffolding.
- Race 3 bets are inserted Bob → Charlie → Alice so that Alice's perfect bet is last in the `SELECT * FROM bets WHERE race_id = ?` result set, giving deterministic pool write behaviour.
- `test-seed.php` must never be uploaded to `formula-1.dk`. The deploy exclusion is mandatory.
- All 3 seeded users share a single password (from `.env`) to keep seed logic simple.
