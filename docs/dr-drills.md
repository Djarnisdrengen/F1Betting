# DR Drill Log

Record each disaster recovery drill here. Run the drill once per season or after any significant infrastructure change.

---

## 2026-05-22 — Test server wiped drill

- **Scope:** Test server wiped (files + DB)
- **Snapshot row counts:** 1 settings, 22 drivers, 9 users, 24 races, 0 leaderboard_snapshots, 20 bets, 0 password_resets, 0 invites
- **Destruction:** All DB tables dropped via phpMyAdmin SQL; `public/` renamed to `public.bak` via FTP
- **Recovery time:** ~45 minutes (including bug discovery and fixing)
- **Verification results:**
  - Smoke tests (4.1): PASS — 8/8
  - Integration tests (4.2): SKIP — `test:integration` script not implemented
  - E2E tests (4.3): PASS — 74/74
  - Admin login (4.4): PASS
  - Row count comparison (4.5): PASS — all tables match
  - Cron qualifying (4.6): PASS — token valid, DB connected
  - Cron notifications (4.7): PASS — `Notification check complete.`
- **Runbook gaps found and fixed during drill:**
  1. `database/schema.sql` contained `CREATE DATABASE` which Simply.com does not allow — removed
  2. `public/tools/db-backup.php` was missing `leaderboard_snapshots` — added
  3. `public/tools/db-restore.php` was missing `leaderboard_snapshots` in all three table lists — added
  4. `public/tools/db-restore.php` had a DDL implicit commit bug: `beginTransaction()` was called before `DROP TABLE`/`CREATE TABLE` DDL statements, which cause MySQL to auto-commit, making the subsequent `commit()` throw "There is no active transaction" — fixed by moving `beginTransaction()` to after the DDL phase
  5. Simply.com WAF blocks curl with long hex tokens in query strings — all runbook curl commands replaced with Node.js `fetch` via `php-config.js`
  6. Cron URLs in runbook used `?secret=` but the cron scripts check `$_GET['token']` — corrected to `?token=`
  7. `test:integration` npm script referenced in runbook does not exist — step removed from drill procedure
