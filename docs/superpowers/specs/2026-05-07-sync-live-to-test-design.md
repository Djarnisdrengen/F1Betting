# Sync Live → Test Design Spec
Date: 2026-05-07

## Overview

A command (`npm run sync:live`) that overwrites all non-settings data on hpovlsen.dk with a live copy from formula-1.dk. A token-gated PHP endpoint on the test server opens a direct second PDO connection to the live database (same MySQL server, same credentials, different DB name) and performs an in-place table copy.

---

## Architecture

```
npm run sync:live
  └── build-deploy/sync.js
        └── GET https://hpovlsen.dk/sync-from-live.php?token=...
              ├── PDO → test DB  (DB_HOST / DB_NAME / DB_USER / DB_PASS)
              ├── PDO → live DB  (DB_HOST / LIVE_DB_NAME / DB_USER / DB_PASS)
              ├── DELETE test tables (FK-safe order)
              ├── SELECT from live → INSERT into test (FK-safe order)
              └── {"ok": true, "copied": {"drivers": N, "users": N, "races": N, "bets": N}}
```

Both sites are hosted on the same MySQL server (Simply.com / UnoEuro) with identical credentials. Only the database name differs.

---

## Components

### 1. Server setup (one-time manual)

On hpovlsen.dk, add to `config.php`:

```php
define('LIVE_DB_NAME', '<live_site_db_name>');
```

This constant is only needed on the test server. The live server's `config.php` does not need any changes.

---

### 2. `public/sync-from-live.php`

Token-gated PHP endpoint deployed only to hpovlsen.dk.

**Token gate:** `$_GET['token'] === INTEGRATION_SEED_TOKEN` — returns HTTP 403 on mismatch. Reuses the existing token (no new secret needed).

**Live DB connection:**

```php
$live = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . LIVE_DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
```

**Delete order** (FK-safe — removes dependents first):
1. `DELETE FROM bets`
2. `DELETE FROM users`
3. `DELETE FROM races`
4. `DELETE FROM drivers`

**Copy order** (FK-safe — inserts parents first):
1. `drivers` — no FK dependencies
2. `users` — no FK dependencies
3. `races` — `result_p1/p2/p3` reference `drivers.id`
4. `bets` — references `users.id` and `races.id`

**Per-table copy pattern:**

```php
$rows = $live->query("SELECT * FROM drivers")->fetchAll();
foreach ($rows as $row) {
    // build INSERT with named placeholders from row keys
}
```

**`settings` table:** never touched.

**Response:** `{"ok": true, "copied": {"drivers": N, "users": N, "races": N, "bets": N}}`

**Error handling:** any PDO exception returns HTTP 500 with `{"ok": false, "error": "<message>"}`.

---

### 3. `build-deploy/sync.js`

Node.js runner script. Loads `.env` from `build-deploy/.env`, calls the endpoint, prints the result.

```
🔄 Syncing live data to test site...
✅ Sync complete: 10 drivers, 3 users, 5 races, 15 bets copied
```

Exits with code 1 on HTTP error or `ok: false`.

---

### 4. `package.json`

```json
"sync:live": "node build-deploy/sync.js"
```

---

### 5. `build-deploy/.deployignore`

Add `sync-from-live.php` — prevents the endpoint from being uploaded to formula-1.dk.

---

## Security

- Endpoint is not deployed to live site (`.deployignore`)
- Token gate (403 on mismatch) prevents unauthenticated access
- Worst case if exploited on test: test data is overwritten with live data (same outcome as the command itself)
- `settings` table is never modified

---

## Tables Copied

| Table | Copied | Notes |
|-------|--------|-------|
| `drivers` | ✅ | |
| `users` | ✅ | Includes passwords, stars, points |
| `races` | ✅ | Includes pool sizes and results |
| `bets` | ✅ | |
| `settings` | ❌ | Test site settings preserved |
| `password_resets` | ❌ | Session-scoped, not meaningful to copy |
| `invites` | ❌ | Session-scoped, not meaningful to copy |
