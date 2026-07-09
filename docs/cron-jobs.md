# Cron Jobs

## Contents

- [1. Qualifying Results Import](#1-qualifying-results-import)
  - [Authentication](#authentication)
  - [Test mode](#test-mode)
  - [Logging](#logging)
  - [Suggested schedule](#suggested-schedule)
- [2. Email Notifications](#2-email-notifications)
  - [Authentication](#authentication-1)
  - [Test mode](#test-mode-1)
  - [Logging](#logging-1)
  - [Suggested schedule](#suggested-schedule-1)
- [Triggering via GitHub Actions](#triggering-via-github-actions)
- [Triggering manually (HTTP)](#triggering-manually-http)
- [Log file locations](#log-file-locations)

---

Two cron scripts live in `public/cron/`. Both can be triggered via HTTP or from the CLI.

**Trigger moved to GitHub Actions as of 2026-07-09 (F6, `security-findings-remaining.md`).**
Simply.com's control-panel cron feature only sends a plain GET with no custom headers, which is
incompatible with the header-based auth below, so both scripts are now scheduled via GitHub
Actions instead — the Simply.com control-panel entries have been deleted. Both scripts still
accept the legacy `?token=` as a temporary shim until one full clean cycle has run on the new
schedule (see [Triggering via GitHub Actions](#triggering-via-github-actions)).

---

## 1. Qualifying Results Import

**File:** `public/cron/import_qualifying.php`  
**Purpose:** Fetches qualifying results from the Jolpica/Ergast F1 API and stores P1/P2/P3 driver IDs in the `races` table.

### Authentication

The script requires `CRON_SECRET` to run:

- **HTTP:** `GET /cron/import_qualifying.php` with header `Authorization: Bearer <CRON_SECRET>`
  (`getBearerToken()` in `functions.php`). A plain browser visit or `curl` can't set a custom
  header — see [Triggering manually](#triggering-manually-http). The old `?token=<CRON_SECRET>`
  query string still works too, but only as a **temporary** shim during the F6 trigger migration
  — don't build new tooling against it.
- **CLI:** `php public/cron/import_qualifying.php <CRON_SECRET>` (unaffected by F6 — an argv isn't
  URL/log-exposed)

Without a valid token the script exits immediately.

### Test mode

Pass `?test=true` (HTTP) or `--test` (CLI) to do a dry run — the API is called but nothing is written to the database.

### Logging

Output is written to `public/logs/cron_qualifying.log` (path defined by `CRON_QUALIFYING_LOG_FILE` in `config.shared.php`). Logs rotate automatically at 200 KB.

### Suggested schedule

Run twice during qualifying weekend: once around qualifying time and once a few hours later as a catch-up in case the API is delayed.

```cron
# Example: Saturdays at 16:00 and 18:00 server time (adjust for race timezone)
0 16 * * 6 php /home/USERNAME/public_html/cron/import_qualifying.php <CRON_SECRET>
0 18 * * 6 php /home/USERNAME/public_html/cron/import_qualifying.php <CRON_SECRET>
```

As of the F6 trigger migration, this schedule lives in
`.github/workflows/cron-qualifying-import.yml` instead — see
[Triggering via GitHub Actions](#triggering-via-github-actions). The `cron` block above is kept
for reference (e.g. if the trigger ever needs to move back to server-side crontab).

---

## 2. Email Notifications

**File:** `public/cron/notifications.php`  
**Purpose:** Sends email notifications at two moments per race:

| Moment | Recipients | Email |
|---|---|---|
| Betting window opens | Users with `in_competition = 1` who have not yet bet | Betting-opened notification with bet link |
| Betting window opens | Users with `in_competition = 0` (role = user) | Pool-reminder — current jackpot size + leaderboard link |
| Betting window opens | Pending invites (not yet registered) | Pool-reminder — current jackpot size + personal registration link |
| 2 hours before race | Users with `in_competition = 1` who have not yet bet | Closing-soon reminder with bet link |

Users who have already placed a bet for a race are skipped for that race's notifications.

### Authentication

Same pattern as the qualifying importer:

- **HTTP:** `GET /cron/notifications.php` with header `Authorization: Bearer <CRON_SECRET>`. The
  old `?token=<CRON_SECRET>` query string still works too, but only as a **temporary** shim
  during the F6 trigger migration.
- **CLI:** `php public/cron/notifications.php <CRON_SECRET>`

### Test mode

Pass `?test=true` (HTTP) or `--test` (CLI) together with a valid token to skip actual SMTP sending. The notification logic still runs in full — the same log output is produced, but no emails leave the server. Used by the E2E test suite.

### Logging

Output is written to `public/logs/cron_notifications.log` (`CRON_NOTIFICATIONS_LOG_FILE`).

### Suggested schedule

Run hourly. The script checks all upcoming races and sends only if the current time matches one of the notification windows. Running it more often than hourly wastes resources without benefit.

```cron
# Every hour
0 * * * * php /home/USERNAME/public_html/cron/notifications.php <CRON_SECRET>
```

As of the F6 trigger migration, this schedule lives in
`.github/workflows/cron-notifications.yml` instead — see
[Triggering via GitHub Actions](#triggering-via-github-actions). The `cron` block above is kept
for reference (e.g. if the trigger ever needs to move back to server-side crontab).

---

## Triggering via GitHub Actions

Simply.com's control-panel cron feature only does a plain GET to a URL, "as if opened through a
browser" — no custom headers, no POST, no body. That's incompatible with the header-based auth
above, so both scripts' trigger moved from Simply's control panel to GitHub Actions scheduled
workflows: `.github/workflows/cron-qualifying-import.yml` and `cron-notifications.yml`, matching
the pattern `nightly-backup.yml` already uses (`vars.BASE_URL_LIVE`, a `CRON_SECRET` repo secret,
an inline `node -e` fetch with the `Authorization` header).

**Status: cut over 2026-07-09.** Both workflows' `schedule:` triggers are live, and the Simply.com
control-panel entries have been deleted. Both cron scripts still accept the legacy `?token=` as a
temporary shim — see `security-findings-remaining.md` under F6 for when that's due to be removed
(after one full clean cycle on the new schedule).

GitHub's `schedule:` is UTC-only with no DST awareness, so the qualifying-import workflow spreads
across the plausible local-time range (`0 13,14,15,16,17 * * 6`) rather than pinning one offset
that would silently drift an hour each spring/autumn — the extra firings are harmless no-ops (the
script only writes when a race's `quali_p1` is still `NULL`, and self-gates to 06:00–23:59 local
time). Notifications run hourly (`5 * * * *`) — the script is itself time-window-gated.

`workflow_dispatch`'s `dry_run` input works for notifications against live (safe — it just skips
the SMTP send). For qualifying import, `dry_run` is **test-env only**: it loads
`tools/f1_testdata.php`, which is excluded from the live deploy, so it dies partway through
against live. Trigger a real (non-dry-run) run against live instead if you need to verify it by
hand — safe as long as there's no unprocessed qualifying data sitting in the API response.

---

## Triggering manually (HTTP)

Trigger either cron script with a `fetch()`/Node one-liner — not a browser or `curl`. A browser
visit can't set the `Authorization` header, and this host's WAF is documented to challenge
non-browser network stacks (see `docs/gotchas.md` / memory "no curl"):

```bash
node -e "fetch('https://www.hpovlsen.dk/cron/import_qualifying.php?test=true', {headers:{Authorization:'Bearer '+process.env.CRON_SECRET}}).then(r=>r.text()).then(console.log)"
```

The response is plain text / HTML with timestamped log lines.

---

## Log file locations

| Log | Path |
|---|---|
| Qualifying import | `public/logs/cron_qualifying.log` |
| Notifications | `public/logs/cron_notifications.log` |
| App errors | `public/logs/app.log` |
| Mail | `public/logs/mail.log` |

All log files are protected by `public/logs/.htaccess` (denies direct HTTP access). They rotate automatically when they exceed 200 KB.
