# Deployment

## Contents

- [Environments](#environments)
- [npm deploy commands](#npm-deploy-commands)
  - [npm run deploy:test](#npm-run-deploytest)
  - [npm run deploy:live](#npm-run-deploylive)
  - [What gets excluded](#what-gets-excluded)
- [Backup](#backup)
- [Rollback](#rollback)
- [Database restore](#database-restore)
- [Sync live → test](#sync-live--test)
- [Dry run](#dry-run)

---

Day-to-day deploy workflow, backup, rollback, and database sync.

For a brand-new server setup see [Deploy from Scratch](deploy-from-scratch.md).

---

## Environments

| Environment | URL | Config file (local) | Config file (server) |
|---|---|---|---|
| Test | hpovlsen.dk | `config.test.php` | `config.php` |
| Live | formula-1.dk | `config.live.php` | `config.php` |

---

## npm deploy commands

### `npm run deploy:test`

1. Connects to the test FTP server
2. Uploads everything in `public/` (respects `.deployignore`)
3. Uploads `config.shared.php` and `config.test.php` (as `config.php`)
4. Runs HTTP smoke tests

### `npm run deploy:live`

1. Asks you to type `YES` — any other input cancels
2. Creates a timestamped backup of the live server (files + database)
3. Connects to the live FTP server
4. Uploads `public/`, `config.shared.php`, `config.live.php` (as `config.php`)
5. Files in `.deployignore.live` are additionally excluded (see below)
6. Runs smoke tests + Playwright E2E tests
7. If tests fail, automatically rolls back to the pre-deploy backup
8. Prunes old backups (keeps 2 most recent)

### What gets excluded

`.deployignore` (applied to both environments):
- `config.php` (the local copy; the script uploads the right one explicitly)
- `cron/cron_import_log.txt`
- `build-deploy/`, `.env`, `node_modules`, `.git`

`.deployignore.live` (live only, in addition):
- `tools/test-seed.php`
- `tools/sync-from-live.php`
- `tools/db-restore.php`

These three tools are dangerous on live: test-seed destroys data, the other two allow unauthenticated DB access if the token leaks.

---

## Backup

A timestamped backup is created automatically before every live deploy. You can also run it manually:

```bash
node build-deploy/backup.js
```

Backups are stored in `build-deploy/backups/live/<ISO-timestamp>/`:
- All files from the live `public/` directory
- `db-backup.json` — full database export (all tables as JSON)

Only the 2 most recent backups are kept automatically. Older ones are pruned after a successful live deploy.

---

## Rollback

If a live deploy fails its post-deploy tests, rollback happens automatically. To roll back manually:

```bash
node build-deploy/rollback.js
```

The script lists available backups and prompts you to choose one, then re-uploads those files over FTP.

---

## Database restore

```bash
npm run restore:db
```

Interactive: lists available backups, asks which one and whether to restore to test or live. Live restores have a 5-second abort window.

`tools/db-restore.php` must be present on the target server. It is deployed to test automatically but excluded from live by default. To restore to live:

1. Temporarily remove `tools/db-restore.php` from `.deployignore.live`
2. Deploy: `npm run deploy:live`
3. Run `npm run restore:db` and select live as the target
4. Put `tools/db-restore.php` back in `.deployignore.live`
5. Deploy again to remove the tool from the live server

---

## Sync live → test

To overwrite the test database with a copy of the live data (useful for testing against real data):

```bash
npm run sync:live
```

This calls `tools/sync-from-live.php` on the test server, which connects to the live database, copies all tables (except `settings`), and drops any `old_` prefixed legacy tables.

**This overwrites all test data.** Run integration tests only against the seeded test state, not after a sync.

---

## Dry run

To see what would be uploaded without actually uploading anything, set `DRY_RUN=true` in `build-deploy/.env`, then run any deploy command.
