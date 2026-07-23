# GitHub Actions

## Contents

- [Nightly Workflow](#nightly-workflow)
  - [What it does](#what-it-does)
- [Nightly DB Backup Workflow](#nightly-db-backup-workflow)
- [Cron Trigger Workflows](#cron-trigger-workflows)
- [Content Top-up Workflow](#content-top-up-workflow)
- [Monthly Security Review Workflow](#monthly-security-review-workflow)
- [E2E Orchestrator Workflow (test env)](#e2e-orchestrator-workflow-test-env)
- [Actions Dashboard (Dashboards → GitHub Actions)](#actions-dashboard-dashboards--github-actions)
- [Required Configuration](#required-configuration)
  - [Variables tab](#variables-tab)
  - [Secrets tab](#secrets-tab)
- [Variables vs Secrets — migration](#variables-vs-secrets--migration)
- [Artifacts](#artifacts)
- [Debugging a failed run](#debugging-a-failed-run)

---

## Nightly Workflow

**File:** `.github/workflows/nightly-tests.yml`  
**Schedule:** 01:00 UTC every night  
**Can also be triggered:** manually via the Actions tab → "Run workflow"

### What it does

1. Checks out the repo
2. Installs Node.js 24 and npm dependencies (`npm ci`)
3. Caches and installs Playwright (Chromium only)
4. Runs `node build-deploy/nightly-report.js`, which:
   - Runs Playwright E2E tests against live (`smoke.spec.js`)
   - Runs the security scanner against live (`--ssllabs --ratelimit`: full checks including SSL Labs and the rate-limit probe)
   - Sends a summary email to `REPORT_TO`
5. Uploads test artifacts (reports, screenshots, security reports) — retained 30 days

**Timeout:** 30 minutes per run.

---

## Nightly DB Backup Workflow

**File:** `.github/workflows/nightly-backup.yml`  
**Schedule:** 01:00 UTC every night  
**Can also be triggered:** manually via the Actions tab → "Run workflow"

Fetches a full DB snapshot from the live site (`db-backup.php`) and uploads it as a GitHub Actions artifact retained for 90 days. Runs independently of the nightly test workflow — a test failure does not block the backup.

**Required secrets/variables:** `BASE_URL_LIVE`, `INTEGRATION_SEED_TOKEN`

Find artifacts under **Actions → Nightly DB Backup → (select run) → Artifacts**.

---

## Cron Trigger Workflows

**Files:** `.github/workflows/cron-qualifying-import.yml`, `.github/workflows/cron-notifications.yml`
**Schedule:** qualifying import `*/5 6-23 * * 6` (every 5 min, Saturdays 06:00–23:55 UTC, no DST awareness); notifications `1 * * * *` (hourly)
**Can also be triggered:** manually via the Actions tab → "Run workflow" (optional `dry_run` input, applies to both jobs)

Part of the F6 fix (`security-findings-remaining.md`): `public/cron/import_qualifying.php` and
`public/cron/notifications.php` used to be triggered by Simply.com's control-panel cron feature,
which only sends a plain GET with no custom headers — incompatible with the `Authorization:
Bearer` auth those scripts now use. These two workflows replaced that trigger as of 2026-07-09,
following the same shape as the Nightly DB Backup Workflow above (inline `node -e` fetch with the
header); the Simply.com control-panel entries have been deleted.

Each workflow runs **two jobs on the same schedule**, one per environment: `trigger-live`
(`vars.BASE_URL_LIVE`, `secrets.CRON_SECRET`) and `trigger-test` (`vars.BASE_URL_TEST`,
`secrets.CRON_SECRET_TEST`) — full parity, chosen knowingly despite the side effects: test's
`notifications.php` sends real, non-intercepted email by default outside an E2E run (gotcha #17),
and test's `import_qualifying.php` writes real API results into a `races` table that
`test-seed.php` periodically wipes for E2E runs, so an unattended import can land mid-reseed.

Both cron scripts still accept the legacy `?token=` query string as a temporary shim — see
`security-findings-remaining.md` under F6 for when that's due to be removed (after one full clean
cycle on this schedule). `dry_run` works for both notifications jobs (safe — just skips the SMTP
send) and for qualifying import's **test** job (its stub data file ships there); it does **not**
work for qualifying import's **live** job (that file is excluded from the live deploy, so it dies
partway through) — trigger a real run instead to verify that one by hand.

**Required secrets/variables:** `BASE_URL_LIVE`, `BASE_URL_TEST`, `CRON_SECRET`, `CRON_SECRET_TEST`

---

## Content Top-up Workflow

**File:** `.github/workflows/cron-content-topup.yml`
**Schedule:** Friday 06:00 UTC, targets **both test and live** — a few days ahead of the Monday
Perfect-Week cron (`cron-challenges.yml`). The batch is **auto-published** (not left as drafts):
each item is stamped with the *upcoming Monday* as its `publish_date`, so the fresh content goes
live Monday 00:00, trivia is playable that whole ISO week, and the Monday-after Perfect-Week cron
scores it. **This is a fully unattended pipeline — no admin review or publish step.** It
deliberately reverses the older "drafts on test only, human publishes" posture; the tradeoff is
that content quality goes out ungated (a single malformed Claude response is still skipped, but a
wrong trivia answer or an off rumor reaches players with no human check).
**Can also be triggered:** manually via the Actions tab → "Run workflow", with `environment`
(test/live, default test — the *schedule* always does both), `count`, `target`
(`both`/`rumors`/`trivia`, default `both`), and `publish` (`true`/`false`, default `true`) inputs.
Set `publish=false` for a drafts-only preview run you review on `admin-challenges.php`; use
`target` to re-run just one generator (e.g. after the other already succeeded but this one hit
the job timeout).

Runs `bin/generate-rumor-items.js` and `bin/generate-trivia-questions.js`, which call the
Anthropic API to draft Rumor or Not items and Trivia questions from
`paddock-rumors/data/knowledge-base.json`, then POST them to
`public/tools/import-rumor-drafts.php` / `import-trivia-drafts.php`. With `--publish` the import
inserts `status='published'` (the request body carries `"status":"published"`); without it the
endpoints default to `status='draft'`, preserving the old reviewable behavior.

These scripts are local/CI-only by design (NFR-101) — they hold the Anthropic API key and must
never run on shared hosting. Unlike the Cron Trigger Workflows above (which fetch a PHP
endpoint on the target site), this workflow runs the generators directly in the Actions runner,
so `SITE_URL`/`INTEGRATION_SEED_TOKEN` are passed as env vars from repo Variables/Secrets rather
than read from a local `config.*.php` (both scripts prefer the env vars when set, falling back
to the config file for local manual runs).

Rumors and Trivia run as **separate parallel jobs** (`rumors`, `trivia`, both fed by a shared
`resolve` job), and each **fans out over an environment matrix** (`fail-fast: false`), so a
scheduled run is up to four jobs — test/live × rumors/trivia. Each has its own 25-minute timeout
and commits its own KB-usage state immediately after its generator succeeds. A large batch (~95
items, e.g. a one-off full-KB top-up) takes roughly 12-15 minutes of sequential Claude calls —
too long for both generators to fit sequentially in one job, and a single shared "commit state at
the very end" step meant one generator timing out discarded the other's already-successful
progress too.

Each generator tracks which knowledge-base docs it has already drawn from in a
**per-environment** state file — `bin/state/{rumor,trivia}-generator-state.<env>.json` — committed
back to the repo right after its own job succeeds (same convention as `paddock-rumors.yml`) so a
doc isn't reused across runs *on that environment*. Per-env (not shared) so test and live stay
independent: a shared file would burn the shared KB twice as fast and let a doc consumed on one
env never reach the other. The knowledge base currently has under 100 docs — expect this to need
attention (grow the KB, or allow reuse after a cooldown) after a few months of sustained weekly
runs, per environment.

That state commit is the **only** guard against a doc being redrawn — the import endpoints do a
plain `INSERT` with no `source_ref` dedup — so the jobs' pushes must not clobber each other. They
push the same branch in parallel, and every pusher but the first gets a non-fast-forward
rejection. Each commit step therefore **rebase-and-retries** (`git pull --rebase --autostash &&
git push`, up to 8 attempts with a short random backoff): the state files never conflict
(different paths), so the rebase is always clean, and every job's progress lands regardless of
ordering. Because the content is now published, a *dropped* push is sharper than before — the
next run would redraw the same docs and produce **duplicate player-visible content**, not just
duplicate drafts. **Job-level** `concurrency` groups (`content-topup-<env>-<generator>`,
`cancel-in-progress: false`) keep a manual `workflow_dispatch` from overlapping the Friday
schedule on the same env+generator — that would double the Claude spend, widen the push race, and
risk two runs drawing the same unused docs into near-duplicate live content.

**Required secrets/variables:** `BASE_URL_LIVE`, `BASE_URL_TEST`, `INTEGRATION_SEED_TOKEN`,
`INTEGRATION_SEED_TOKEN_TEST`, `ANTHROPIC_API_KEY` (all already used by other workflows above —
no new secrets to add).

**Timeout:** 25 minutes per job.

---

## Monthly Security Review Workflow

**File:** `.github/workflows/monthly-security-review.yml`  
**Schedule:** 1st of each month at 08:17 UTC  
**Can also be triggered:** manually via the Actions tab → "Run workflow"

Runs `node build-deploy/security-review.js`, which checks OWASP and CWE coverage against `tests/security/security.js` and emails an HTML report to `REPORT_TO`. Findings and actions are logged in `docs/security-review-log.md`.

**Required secrets/variables:** `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `REPORT_TO`, `RESEND_API_KEY` (same set as the nightly workflow)

Find artifacts under **Actions → Monthly Security Review → (select run) → Artifacts**. The report is also retained as `security-review-<run_id>` for 90 days.

**Timeout:** 10 minutes per run.

---

## E2E Orchestrator Workflow (test env)

**File:** `.github/workflows/e2e-test-orchestrator.yml`
**Trigger:** manual only (`workflow_dispatch`) — no schedule, since it mutates the shared test DB the same way a local `npm run test:e2e:test` does.

Runs the full E2E orchestrator (`tests/run-e2e-suites.js`, all 11 suites, 175 tests) against
the test env. Added as part of the E2E suite restructuring
(`epics/Optimize test suite structure/plan.md`, SHOULD-3) to give the orchestrator — the
riskiest new component in that epic, since it repoints `npm run test:e2e:test` — at least one
automated run before it's trusted, given CI previously only ever exercised live-smoke.

**Required secrets/variables:** `BASE_URL_TEST` (already exists), `CRON_SECRET_TEST` (already
exists), plus three new secrets — `TEST_USER_EMAIL_TEST`, `TEST_USER_PASSWORD_TEST`,
`INTEGRATION_SEED_TOKEN_TEST` — see [Required Configuration](#required-configuration) below.
**This workflow will fail until those three secrets are added** — it isn't wired to anything
that creates them automatically.

Uploads `build-deploy/screenshots/` as an artifact on failure, same as the nightly workflow.

**Timeout:** 15 minutes per run.

---

## Actions Dashboard (Dashboards → GitHub Actions)

**File:** `public/admin-dashboards.php?tab=actions` (page controller) +
`public/includes/admin-dashboards/actions.php` (rendering) + `public/includes/actions-dashboard.php`
(GitHub API client, cron evaluator, schedule/collision math). **Moved** from the standalone
`public/admin-actions.php` as part of the "Admin settings and dashboards" epic's two-tier nav
restructure (see `epics/Admin settings and dashboards/`, and `docs/admin-dashboards.md` for the
other four Dashboards tabs) — `admin-actions.php` is now a thin 302 redirect to
`admin-dashboards.php?tab=actions` that preserves the query string, so old bookmarks and the
`?ajax=run_jobs` endpoint both keep working.

An admin-only, read-only ops dashboard summarizing every workflow above: what it's for, its
last 10 runs (with per-step status pulled from the Jobs API), what ran across all workflows in
the last 12 hours, and a month-at-a-glance run-schedule heat matrix with same-UTC-minute
collision detection. Reached via the Dashboards area's five-tab section nav (`Oversigt · Nøgler &
Rotation · PaddockKB · Challenges · GitHub Actions`).

Its per-workflow run summary (success rate, failing-now count) is computed by
`ghGetHealthSnapshot()`/`ghSummarizeRuns()`, the same functions Dashboards → Oversigt's tile for
this dashboard calls — the two views can never disagree on the arithmetic. PaddockKB's own
"Sidste opdatering" card reuses this file's `ghListWorkflowRuns()` for the `kb-update` workflow
(`paddock-rumors.yml`) rather than a second run-history mechanism, and its "Kør opdatering nu"
button uses this file's `ghTriggerWorkflowDispatch()` — which needs `GITHUB_TOKEN` to also have
`actions:write`, not just the `actions:read` this dashboard itself needs (see
`config.example.php`).

**Data source:** the GitHub REST API (`GET .../actions/workflows/{file}/runs`, `GET
.../actions/runs/{run_id}/jobs`), called server-side only — the browser never talks to
`api.github.com` directly. Workflow purpose/expected-result copy and each workflow's cron
string(s) are a static config in `ghWorkflowConfig()`, **not** read from the API — kept in sync
by hand against the actual `.github/workflows/*.yml` files (see
`epics/github_actions_dashboard/plan.md` decision #5 for why the schedule/collision math is a
generic cron evaluator over the real cron strings rather than a hand-summarized schedule table:
an earlier illustrative draft of that table didn't match what's actually configured for several
of these workflows).

**Caching:** a 60-second file cache at `public/cache/github-actions/*.json` for the per-workflow
run lists (`GET .../runs`), keeping page-load API usage to 9 calls/60s (one per known workflow
file — no separate "list workflows" call is needed). Per-run job/step data is fetched lazily
(only when a run row is expanded in the UI) and cached far longer once a run has completed
(immutable), 15s while still in progress.

**`GITHUB_TOKEN` (optional but recommended):** see `config.example.php`. Without it the
dashboard falls back to unauthenticated GitHub API calls — 60 requests/hour, shared with
whatever else is on the same Simply.com hosting IP. A fine-grained PAT with read-only
`Actions` permission (or a classic PAT with `repo` scope) on `Djarnisdrengen/F1Betting` removes
that ceiling (5000/hr, authenticated).

**E2E test fixture mode:** gated the same way `admin.php`'s own E2E test-mode is
(`INTEGRATION_SEED_TOKEN`-matched `e2e_token`) plus an `e2e_gh_fixture` flag — when both are
present, `ghApiGet()`'s callers read `public/includes/actions-dashboard-mock.json` instead of
calling `curl`, so `tests/e2e/admin/14-actions-dashboard.spec.js` gets deterministic run
data without hitting the live GitHub API or its rate limit. `e2e_gh_fixture=error` simulates a
failed fetch (tests the error banner). The lazy run-expand AJAX call
(`?ajax=run_jobs&run_id=…`) forwards the same `e2e_token`/`e2e_gh_fixture` params from the
page's own URL, since the fixture gate is checked per-request, not just on the initial page
load. The pure cron/schedule/collision math has its own fast CLI harness independent of any of
this — `php tests/unit/actions-schedule-harness.php` (wired into `npm run test:unit`).

---

## Required Configuration

The workflow runs against the live site and cannot read `config.live.php` (that file is local only). Required values must be configured in the GitHub repository settings.

Go to: **Settings → Secrets and variables → Actions**

### Variables tab

Variables are plain text and visible in the UI. Use them for non-sensitive configuration.

| Variable | Example | Notes |
|---|---|---|
| `BASE_URL_LIVE` | `https://www.formula-1.dk` | Must use `www`. No trailing slash. |
| `BASE_URL_TEST` | `https://www.hpovlsen.dk` | Must use `www`. No trailing slash. Used by the cron trigger workflows' `trigger-test` jobs. |
| `SMTP_HOST` | `smtp.protonmail.com` | Mail server hostname |
| `SMTP_PORT` | `587` | SMTP port |
| `SMTP_FROM` | `noreply@formula-1.dk` | Sender address for the nightly report |

### Secrets tab

Secrets are encrypted and hidden in logs.

| Secret | Description |
|---|---|
| `SMTP_USER` | SMTP login username (Proton Mail — primary transport) |
| `SMTP_PASS` | SMTP password (Proton Mail) |
| `RESEND_API_KEY` | Resend API key — fallback transport if Proton SMTP fails. Get one at resend.com (free tier is sufficient). If unset, a warning is logged and there is no fallback. |
| `REPORT_TO` | Recipient address for the nightly report email |
| `TEST_USER_PASSWORD_LIVE` | Admin account password on the live site (used for E2E login) |
| `CRON_SECRET` | Value of `CRON_SECRET` in `config.live.php` — used by the cron trigger workflows' `trigger-live` jobs |
| `CRON_SECRET_TEST` | Value of `CRON_SECRET` in `config.test.php` (a different value from the live one) — used by the cron trigger workflows' `trigger-test` jobs, and by the E2E orchestrator workflow |
| `TEST_USER_EMAIL_TEST` | Admin account email on the test site (E2E orchestrator workflow) — the equivalent of the hardcoded `f1_admin@helvegpovlsen.dk` below, but for whatever admin address `config.test.php` actually uses |
| `TEST_USER_PASSWORD_TEST` | Admin account password on the test site (E2E orchestrator workflow) |
| `INTEGRATION_SEED_TOKEN_TEST` | Value of `INTEGRATION_SEED_TOKEN` in `config.test.php` — every `helpers/seed.js` call needs this; without it every seed-dependent suite fails at `beforeAll` (E2E orchestrator workflow) |

`TEST_USER_EMAIL_LIVE` is hardcoded in the workflow (`f1_admin@helvegpovlsen.dk`) and does not need to be a secret. The test env's admin address isn't assumed to be the same, so `TEST_USER_EMAIL_TEST` is a secret instead of being hardcoded the same way.

---

## Variables vs Secrets — migration

GitHub Actions has two separate storage areas under **Settings → Secrets and variables → Actions**:

| Storage | Syntax in workflow | Encrypted | Visible in UI |
|---|---|---|---|
| **Variables** | `${{ vars.NAME }}` | No | Yes |
| **Secrets** | `${{ secrets.NAME }}` | Yes | No |

The workflow uses `${{ vars.BASE_URL_LIVE }}`. If you stored it as a secret instead (using `secrets.NAME` syntax), that expression evaluates to an empty string — the workflow only appears to work because `nightly-report.js` has a hardcoded fallback URL. Any future URL change would be silently ignored.

**Migrate `BASE_URL_LIVE` from secret to variable:**

1. Go to **Settings → Secrets and variables → Actions → Variables tab**
2. Click **New repository variable**
3. Name: `BASE_URL_LIVE` / Value: `https://www.formula-1.dk`
4. Go to the **Secrets tab** and delete the existing `BASE_URL_LIVE` secret

**Everything else stays as secrets** — `SMTP_*`, `REPORT_TO`, and `TEST_USER_PASSWORD_LIVE` are credentials and belong in the secrets tab.

The distinction: secrets for passwords/tokens, variables for config values that are not sensitive (URLs, non-secret names).

---

## Artifacts

| Workflow | Artifact | Retention | Contents |
|---|---|---|---|
| Nightly Tests & Security Scan | `nightly-report-<run_id>` | 30 days | OWASP scan results, Playwright failure screenshots |
| Nightly DB Backup | `db-backup-<run_id>-<run_number>` | 90 days | Full live DB snapshot as JSON |
| Monthly Security Review | `security-review-<run_id>` | 90 days | HTML security review report |

Find them under **Actions → (select workflow) → (select run) → Artifacts** at the bottom of the summary page.

---

## Debugging a failed run

1. Open the failed run in the Actions tab
2. Expand the "Run nightly report" step for the full log
3. Download the artifacts for screenshots and the security report
4. If E2E login fails, check that `TEST_USER_PASSWORD_LIVE` matches the live admin password

The nightly-report.js script always sends the email even when tests fail — the email contains pass/fail counts and failure details.
