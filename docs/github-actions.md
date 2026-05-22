# GitHub Actions

## Contents

- [Nightly Workflow](#nightly-workflow)
  - [What it does](#what-it-does)
- [Nightly DB Backup Workflow](#nightly-db-backup-workflow)
- [Monthly Security Review Workflow](#monthly-security-review-workflow)
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
   - Runs the security scanner against live (basic checks, no rate-limit or SSL Labs)
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

## Monthly Security Review Workflow

**File:** `.github/workflows/monthly-security-review.yml`  
**Schedule:** 1st of each month at 08:17 UTC  
**Can also be triggered:** manually via the Actions tab → "Run workflow"

Runs `node build-deploy/security-review.js`, which checks OWASP and CWE coverage against `tests/security/security.js` and emails an HTML report to `REPORT_TO`. Findings and actions are logged in `docs/security-review-log.md`.

**Required secrets/variables:** `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `REPORT_TO`, `RESEND_API_KEY` (same set as the nightly workflow)

Find artifacts under **Actions → Monthly Security Review → (select run) → Artifacts**. The report is also retained as `security-review-<run_id>` for 90 days.

**Timeout:** 10 minutes per run.

---

## Required Configuration

The workflow runs against the live site and cannot read `config.live.php` (that file is local only). Required values must be configured in the GitHub repository settings.

Go to: **Settings → Secrets and variables → Actions**

### Variables tab

Variables are plain text and visible in the UI. Use them for non-sensitive configuration.

| Variable | Example | Notes |
|---|---|---|
| `BASE_URL_LIVE` | `https://www.formula-1.dk` | Must use `www`. No trailing slash. |
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

`TEST_USER_EMAIL_LIVE` is hardcoded in the workflow (`f1_admin@helvegpovlsen.dk`) and does not need to be a secret.

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
