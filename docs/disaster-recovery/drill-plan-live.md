# DR Drill Plan — LIVE environment, "Bad deploy / data corruption" scope

## Context

Drills the "Bad deploy / data corruption — files OK" recovery path against `www.formula-1.dk`.
Files stay up throughout — only DB data is wiped and restored. This tests Option A (phpMyAdmin
import via `backup-to-sql.js`), the recommended restore path.

Run after a race weekend is fully processed. Target: Monday or Tuesday morning CET, low traffic.

---

## Key facts

| | |
|---|---|
| Site | www.formula-1.dk |
| Scope | DB data only (files stay up) |
| Restore path | Option A — phpMyAdmin SQL import (`backup-to-sql.js`) |
| Verification | 13 E2E tests (01-smoke.spec.js only) |
| Risk | Medium — real users, real data |

---

## Prerequisites — check before starting

- [ ] Race weekend is over and scoring has been applied (leaderboard shows updated points)
- [ ] No open betting window for the next race (`race_date` > 2 days away)
- [ ] `config.live.php` and `build-deploy/.env` present locally
- [ ] At least one recent nightly artifact or local backup exists

---

## Phase 1 — Pre-drill: establish known-good state

### Step 1.1 — Take a fresh backup

Do not rely on the nightly artifact alone — take an explicit pre-drill snapshot:

```bash
npm run backup:live
```

**Expected:** `✅ Backup complete: <timestamp>`. Note the timestamp for step 3.1.

### Step 1.2 — Record live row counts

```bash
node -e "
const path = require('path');
require('dotenv').config({ path: path.join('build-deploy', '.env') });
const { readPhpConfig } = require('./build-deploy/php-config');
const cfg = readPhpConfig('live');
fetch(cfg.siteUrl + '/tools/db-backup.php?token=' + cfg.integrationSeedToken)
  .then(r => r.json())
  .then(d => {
    console.log('ok:', d.ok);
    Object.entries(d.tables).forEach(([t,r]) => console.log(t+':', r?.length ?? 'null'));
  })
  .catch(e => { console.error(e); process.exit(1); });
"
```

**Record the counts** — compare against these in step 4.3.

### Step 1.3 — Smoke-test live
```bash
node tests/smoke.js https://www.formula-1.dk
```
**Expected:** `✅ 8/8 checks passed`. Do not proceed if any check fails.

### Step 1.4 — Verify admin login
Open `https://www.formula-1.dk`, log in as `f1_admin@helvegpovlsen.dk`.
**Expected:** Admin panel loads. Note current leaderboard state.

---

## Phase 2 — Simulate "bad deploy / data corruption"

### Step 2.1 — Wipe live DB data only (keep schema)

phpMyAdmin → live database → SQL tab:

```sql
SET foreign_key_checks = 0;
DELETE FROM leaderboard_snapshots;
DELETE FROM bets;
DELETE FROM password_resets;
DELETE FROM invites;
DELETE FROM races;
DELETE FROM users;
DELETE FROM drivers;
DELETE FROM settings;
SET foreign_key_checks = 1;
```

`DELETE` not `DROP TABLE` — schema stays intact. Tables still exist but are empty.

**Expected:** "Your SQL query has been executed successfully."

### Step 2.2 — Confirm live site is broken
```bash
node tests/smoke.js https://www.formula-1.dk
```
**Expected:** Multiple `❌` failures (500s — no `settings` row found by PHP).

---

## Phase 3 — Recovery (Option A — phpMyAdmin import)

### Step 3.1 — Generate SQL from the pre-drill backup

```bash
node build-deploy/backup-to-sql.js build-deploy/backups/live/<timestamp>/db-backup.json
```

Replace `<timestamp>` with the folder from step 1.1. Writes `db-restore.sql` to the same folder.

**Expected:**
```
✅ Written: build-deploy/backups/live/<timestamp>/db-restore.sql
   settings: 1 rows
   drivers: 22 rows
   ...
```

### Step 3.2 — Import via phpMyAdmin

phpMyAdmin → live database → Import tab:
- Choose File → select `db-restore.sql` from step 3.1
- Leave all settings at defaults
- Click Import

**Expected:** "Import has been successfully finished. X queries executed."

---

## Phase 4 — Verification

| Step | Command / Action | Expected |
|---|---|---|
| 4.1 Smoke tests | `node tests/smoke.js https://www.formula-1.dk` | `✅ 8/8` |
| 4.2 Admin login | Browser: `https://www.formula-1.dk` → log in | Admin panel loads; leaderboard matches pre-drill state |
| 4.3 Row counts | Node.js fetch backup endpoint, compare to step 1.2 | All tables match |
| 4.4 Live E2E | `npm run test:e2e:live` | 13/13 pass |

Row count check:
```bash
node -e "
const path = require('path');
require('dotenv').config({ path: path.join('build-deploy', '.env') });
const { readPhpConfig } = require('./build-deploy/php-config');
const cfg = readPhpConfig('live');
fetch(cfg.siteUrl + '/tools/db-backup.php?token=' + cfg.integrationSeedToken)
  .then(r => r.json())
  .then(after => {
    console.log('After restore:');
    Object.entries(after.tables).forEach(([t,r]) => console.log(t+':', r?.length ?? 'null'));
  })
  .catch(e => { console.error(e); process.exit(1); });
"
```

Compare manually against step 1.2 counts.

---

## Phase 5 — Clean up and record

```bash
npm run sync:live
```

Record the drill in `docs/disaster-recovery/dr-drills.md`:
```markdown
## YYYY-MM-DD HH:MM CET — LIVE "bad deploy / data corruption" drill
- Scope: DB data only (DELETE not DROP, files untouched)
- Pre-drill snapshot: build-deploy/backups/live/<timestamp>/
- Restore path: Option A (backup-to-sql.js → phpMyAdmin import)
- Snapshot row counts: (paste from step 1.2)
- Recovery time: ~__ minutes
- Verification: smoke 8/8, E2E live 13/13, admin login PASS, row counts PASS
- Runbook gaps found: none / (describe)
```

---

## Abort criteria

Stop immediately and restore from the nightly artifact if:
- phpMyAdmin import fails with errors
- Smoke tests still failing after import
- Any user reports missing data

Fallback: GitHub Actions → nightly workflow → most recent `db-backup-<run_id>` artifact →
repeat Option A restore with that file.
