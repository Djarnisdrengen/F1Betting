# DR Drill Plan — TEST environment, "Test server wiped" scope

## Contents

- [Context](#context)
- [Key facts](#key-facts)
- [Prerequisites](#prerequisites)
- [Phase 1 — Pre-test: establish known-good state](#phase-1--pre-test-establish-known-good-state)
  - [Step 1.1 — Smoke-test the test site](#step-11--smoke-test-the-test-site)
  - [Step 1.2 — Take a named snapshot](#step-12--take-a-named-snapshot)
  - [Step 1.3 — Verify admin login](#step-13--verify-admin-login)
- [Phase 2 — Simulate "test server wiped"](#phase-2--simulate-test-server-wiped)
  - [Step 2.1 — Wipe the test database](#step-21--wipe-the-test-database)
  - [Step 2.2 — Corrupt the test server files](#step-22--corrupt-the-test-server-files)
  - [Step 2.3 — Confirm destruction](#step-23--confirm-destruction)
- [Phase 3 — Recovery](#phase-3--recovery)
  - [Step 3.1 — Verify local prerequisites](#step-31--verify-local-prerequisites)
  - [Step 3.2 — Restore files](#step-32--restore-files)
  - [Step 3.3 — Restore schema](#step-33--restore-schema)
  - [Step 3.4 — Make snapshot visible to restore:db](#step-34--make-snapshot-visible-to-restoredb)
  - [Step 3.5 — Restore data](#step-35--restore-data)
- [Phase 4 — Verification](#phase-4--verification)
- [Phase 5 — Clean up](#phase-5--clean-up)

---

## Context

Drills the "Test server wiped" recovery path against `www.hpovlsen.dk`. Both files and DB are
destroyed and recovered. No risk to live. Run once per season or after any significant
infrastructure change.

---

## Key facts

| | |
|---|---|
| Site | www.hpovlsen.dk |
| Scope | Files + DB (full wipe) |
| Restore path | Option B — programmatic (`npm run restore:db -- --env test`) |
| Verification | 74 E2E tests (full suite) |
| Risk | Low |

---

## Prerequisites

- `INTEGRATION_SEED_TOKEN` provisioned as a GitHub Actions Secret
- `config.test.php` and `build-deploy/.env` present locally
- Test environment up to date: `npm run deploy:test`
- At least one local backup in `build-deploy/backups/live/`

---

## Phase 1 — Pre-test: establish known-good state

### Step 1.1 — Smoke-test the test site
```bash
node tests/smoke.js https://www.hpovlsen.dk
```
**Expected:** `✅ 8/8 checks passed`. Fix any failures before proceeding.

### Step 1.2 — Take a named snapshot

Simply.com's WAF blocks curl with long hex tokens — use Node.js fetch:

```bash
node -e "
const path = require('path');
require('dotenv').config({ path: path.join('build-deploy', '.env') });
const { readPhpConfig } = require('./build-deploy/php-config');
const cfg = readPhpConfig('test');
fetch(cfg.siteUrl + '/tools/db-backup.php', { headers: { Authorization: 'Bearer ' + cfg.integrationSeedToken } })
  .then(r => r.json())
  .then(d => {
    require('fs').mkdirSync('build-deploy/backups/dr-drill', { recursive: true });
    require('fs').writeFileSync('build-deploy/backups/dr-drill/dr-test-snapshot.json', JSON.stringify(d, null, 2));
    console.log('ok:', d.ok);
    Object.entries(d.tables).forEach(([t,r]) => console.log(t+':', r?.length ?? 'null'));
  })
  .catch(e => { console.error(e); process.exit(1); });
"
```

**Record the counts** — compare against these in step 4.5.

### Step 1.3 — Verify admin login
Open `https://www.hpovlsen.dk`, log in as `f1_admin@helvegpovlsen.dk`.
**Expected:** Admin panel loads with all tabs.

---

## Phase 2 — Simulate "test server wiped"

### Step 2.1 — Wipe the test database

phpMyAdmin → test database → SQL tab:

```sql
SET foreign_key_checks = 0;
DROP TABLE IF EXISTS `invites`;
DROP TABLE IF EXISTS `bets`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `leaderboard_snapshots`;
DROP TABLE IF EXISTS `races`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `drivers`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `login_attempts`;
SET foreign_key_checks = 1;
```

**Expected:** No tables visible in the structure tab.

Note: `login_attempts` is excluded from backup/restore — ephemeral rate-limit data with no
recovery value. Recreated empty by `schema.sql`.

### Step 2.2 — Corrupt the test server files

FTP/file manager → rename `public/` → `public.bak`.

**Expected:** `https://www.hpovlsen.dk` returns 404/500 for all URLs.

### Step 2.3 — Confirm destruction
```bash
node tests/smoke.js https://www.hpovlsen.dk
```
**Expected:** `❌ 8/8 checks failed`

---

## Phase 3 — Recovery

### Step 3.1 — Verify local prerequisites
```bash
ls build-deploy/.env config.test.php
```

### Step 3.2 — Restore files
```bash
npm run deploy:test
```
**Expected:** Upload completes. Smoke tests will fail — DB is still empty. That's fine.

### Step 3.3 — Restore schema

phpMyAdmin → test database → SQL tab → paste and run full contents of `database/schema.sql`.

**Expected:** 9 tables in structure tab (`settings`, `drivers`, `users`, `races`,
`leaderboard_snapshots`, `bets`, `password_resets`, `invites`, `login_attempts`).

Do not attempt to log in yet — stub admin has a placeholder password until data is restored in 3.5.

### Step 3.4 — Make snapshot visible to restore:db

`restore-db.js` only scans `build-deploy/backups/live/`:

```bash
mkdir -p build-deploy/backups/live/dr-drill-snapshot
cp build-deploy/backups/dr-drill/dr-test-snapshot.json \
   build-deploy/backups/live/dr-drill-snapshot/db-backup.json
```

### Step 3.5 — Restore data
```bash
npm run restore:db -- --env test
```
Select `[1] dr-drill-snapshot`, type `YES`.

**Expected:**
```
✅ Restore complete: 1 settings, 22 drivers, 9 users, 24 races, 0 leaderboard_snapshots, 20 bets, 0 password_resets, 0 invites
```
(counts will vary — should match step 1.2)

---

## Phase 4 — Verification

| Step | Command / Action | Expected |
|---|---|---|
| 4.1 Smoke tests | `node tests/smoke.js https://www.hpovlsen.dk` | `✅ 8/8` |
| 4.2 E2E tests | `npm run test:e2e:test` | 74/74 pass |
| 4.3 Admin login | Browser: `https://www.hpovlsen.dk` → log in | Admin panel loads |
| 4.4 Cron qualifying | Node.js fetch with `Authorization: Bearer <CRON_SECRET>` to `import_qualifying.php` | `Cron token validation: VALID` |
| 4.5 Cron notifications | Node.js fetch with `Authorization: Bearer <CRON_SECRET>` to `notifications.php` | `Notification check complete.` |
| 4.6 Row counts | Node.js fetch backup endpoint, compare to step 1.2 | All tables match |

Cron check (Authorization header, not `?token=` — see `security-findings-remaining.md` F6; both
scripts still accept the old `?token=` as a temporary shim, but don't rely on it):
```bash
node -e "
const path = require('path');
require('dotenv').config({ path: path.join('build-deploy', '.env') });
const { readPhpConfig } = require('./build-deploy/php-config');
const cfg = readPhpConfig('test');
const authHeader = { headers: { Authorization: 'Bearer ' + cfg.cronSecret } };
Promise.all([
  fetch('https://www.hpovlsen.dk/cron/import_qualifying.php', authHeader).then(r => r.text()),
  fetch('https://www.hpovlsen.dk/cron/notifications.php', authHeader).then(r => r.text()),
]).then(([q, n]) => {
  console.log('qualifying:', q.includes('VALID') ? '✅ VALID' : '❌ ' + q.slice(0,100));
  console.log('notifications:', n.trim() === 'Notification check complete.' ? '✅ ' + n.trim() : '❌ ' + n.slice(0,100));
});
"
```

---

## Phase 5 — Clean up

```bash
npm run sync:live
rm -rf build-deploy/backups/live/dr-drill-snapshot
rm -rf build-deploy/backups/dr-drill
```

Record the drill in `docs/disaster-recovery/dr-drills.md`.
