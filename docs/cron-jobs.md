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
- [Triggering via GitHub Actions (in migration)](#triggering-via-github-actions-in-migration)
- [Triggering manually (HTTP)](#triggering-manually-http)
- [Log file locations](#log-file-locations)

---

Two cron scripts live in `public/cron/`. Both can be triggered via HTTP or from the CLI.

**Trigger mechanism is mid-migration (F6, `security-findings-remaining.md`).** Simply.com's
control-panel cron feature only sends a plain GET with no custom headers, which is incompatible
with the header-based auth below — so the trigger is moving to GitHub Actions. Until that
migration completes, Simply's control-panel entries are still the live trigger, kept working by a
temporary shim (see [Triggering via GitHub Actions](#triggering-via-github-actions-in-migration)).

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

Once the F6 trigger migration lands, this schedule moves into
`.github/workflows/cron-qualifying-import.yml` instead — see
[Triggering via GitHub Actions](#triggering-via-github-actions-in-migration).

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

Once the F6 trigger migration lands, this schedule moves into
`.github/workflows/cron-notifications.yml` instead — see
[Triggering via GitHub Actions](#triggering-via-github-actions-in-migration).

---

## Triggering via GitHub Actions (in migration)

Simply.com's control-panel cron feature only does a plain GET to a URL, "as if opened through a
browser" — no custom headers, no POST, no body. That's incompatible with the header-based auth
above, so both scripts' trigger is moving from Simply's control panel to GitHub Actions scheduled
workflows: `.github/workflows/cron-qualifying-import.yml` and `cron-notifications.yml`, matching
the pattern `nightly-backup.yml` already uses (`vars.BASE_URL_LIVE`, a `CRON_SECRET` repo secret,
an inline `node -e` fetch with the `Authorization` header).

**Current status:** both workflows exist but are `workflow_dispatch:`-only (no `schedule:` yet).
Simply.com's control-panel entries are still the live trigger for now, kept working by a temporary
`?token=` shim in both cron scripts. Full cutover sequence, including the manual steps (creating
the `CRON_SECRET` GitHub secret, deleting the Simply.com entries) is tracked in
`security-findings-remaining.md` under F6 — check there for current status before assuming either
trigger is authoritative.

Once cut over, GitHub's `schedule:` is UTC-only with no DST awareness, so the qualifying-import
workflow spreads across the plausible local-time range (`0 13,14,15,16,17 * * 6`) rather than
pinning one offset that would silently drift an hour each spring/autumn — the extra firings are
harmless no-ops (the script only writes when a race's `quali_p1` is still `NULL`, and self-gates
to 06:00–23:59 local time).

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
