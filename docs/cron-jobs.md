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
- [Setting up cron on Simply.com](#setting-up-cron-on-simplycom)
- [Triggering manually (HTTP)](#triggering-manually-http)
- [Log file locations](#log-file-locations)

---

Two cron scripts live in `public/cron/`. Both can be triggered via HTTP or from the CLI.

---

## 1. Qualifying Results Import

**File:** `public/cron/import_qualifying.php`  
**Purpose:** Fetches qualifying results from the Jolpica/Ergast F1 API and stores P1/P2/P3 driver IDs in the `races` table.

### Authentication

The script requires `CRON_SECRET` to run:

- **HTTP:** `GET /cron/import_qualifying.php?token=<CRON_SECRET>`
- **CLI:** `php public/cron/import_qualifying.php <CRON_SECRET>`

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

- **HTTP:** `GET /cron/notifications.php?token=<CRON_SECRET>`
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

---

## Setting up cron on Simply.com

1. Log in to Simply.com control panel
2. Go to **Hosting → Cron jobs**
3. Add each job with the full PHP path and absolute script path
4. Use the **test** button in the control panel to verify the script runs and produces output

The PHP binary path on Simply.com is typically `/usr/bin/php`. Confirm it with: `which php` in their SSH terminal.

---

## Triggering manually (HTTP)

You can trigger either cron script from a browser or `curl` for testing:

```bash
curl "https://www.hpovlsen.dk/cron/import_qualifying.php?token=YOUR_CRON_SECRET&test=true"
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
