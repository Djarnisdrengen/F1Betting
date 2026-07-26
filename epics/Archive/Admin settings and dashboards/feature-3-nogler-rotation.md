# Feature 3: Dashboards — Nøgler & Rotation (Keys & Rotation)

> **Highest-risk feature in this epic.** This is the only screen in the whole "Admin settings and dashboards"
> epic with a real privileged side effect: "Roter nu" generates a new secret value and writes it into a live
> environment's config. Every other dashboard in this epic (Oversigt, PaddockKB, Challenges) is strictly
> read-only. Treat auth-gating, confirmation, and audit logging here as first-class requirements, not
> afterthoughts.

> **Revised 2026-07-23 per `/test-manager` review** (verdict: APPROVE WITH CONDITIONS, folded in below and
> in `plan.md`): REQ-301 and several downstream references to an environment toggle are now stale against
> `plan.md` architecture decision #4 (no cross-host toggle exists — each deployed instance manages only its
> own host's environment, implicitly). Corrected throughout this doc.

> **Revised 2026-07-23 during implementation — the single most important change in this feature.**
> Auditing the actual secrets in `config.example.php` against what REQ-308 ("Roter nu" generates a new value
> and writes it) would do to each one found that **almost none of them are safe to blindly regenerate**:
> - `MFA_KEY` seals every user's TOTP secret at rest (sodium secretbox) — rotating it makes every already-
>   enrolled TOTP secret **permanently undecryptable**, locking out 2FA for the whole user base.
> - `PASSWORD_PEPPER` is mixed into every stored password hash — rotating it makes **every existing password
>   hash fail to verify**, a mass lockout, unless paired with a rehash-on-login migration this feature does
>   not implement.
> - `DB_PASS` / `SMTP_PASS` are credentials for an **external system** (the MySQL server / Proton Mail) —
>   writing a fresh random value to `config.php` without also changing it at that external system breaks the
>   DB connection / mail sending immediately.
> - `INTEGRATION_SEED_TOKEN` / `CRON_SECRET` are each **paired with a matching GitHub Actions repo secret**
>   (`INTEGRATION_SEED_TOKEN_TEST`, `CRON_SECRET`/`CRON_SECRET_TEST` — see `docs/github-actions.md`) —
>   rotating only the local copy breaks E2E/cron workflows until a human updates the GitHub secret too.
> - Only `CHALLENGE_INVITE_SECRET` (a stateless HMAC key with no persisted state and no external pairing —
>   worst case, a handful of already-emailed opt-out links stop verifying) is genuinely safe to regenerate
>   with zero coordination.
>
> **Decision: REQ-308's auto-generate-and-write behavior is opt-in per secret, not the default.** Every
> secret has a `mode`: `'record'` (the human rotates it through the correct channel — MySQL, Proton, the
> paired GitHub secret, or a proper pepper/key-rotation migration entirely out of scope here — then clicks
> essentially the same "record it happened" action tokens already have) or `'auto'` (genuinely side-effect-
> free, so "Roter nu" may actually generate+write). This is not a smaller feature — age tracking, the health
> score, and the audit log (this epic's actual success metric) all still apply identically to `'record'`-mode
> secrets; what's cut is a button that would have been an active lockout/outage bug if applied blindly, not a
> hypothetical risk.
>
> **Revised again 2026-07-23, Djarnis's explicit call after reviewing the risk breakdown above:**
> `INTEGRATION_SEED_TOKEN` and `CRON_SECRET` are also `'auto'` — their risk (breaks CI/cron until the paired
> GitHub secret is manually updated to match) is judged acceptable, since it's a same-day fix with no
> user-facing impact. `MFA_KEY` and `PASSWORD_PEPPER` stay `'record'` — their risk (immediate mass password-
> reset / 2FA-re-enrollment for every member) is categorically different and was NOT approved for auto-write.
> `DB_PASS`/`SMTP_PASS` stay `'record'` too, per Djarnis's own framing ("all except DB and SMTP passwords").
> **v1 final: `'auto'` = `CHALLENGE_INVITE_SECRET`, `INTEGRATION_SEED_TOKEN`, `CRON_SECRET` (3 of 7);
> `'record'` = `DB_PASSWORD`, `SMTP_PASSWORD`, `PASSWORD_PEPPER`, `MFA_KEY` (4 of 7).**

## Requirements

### Functional Requirements
- [REQ-301] Every figure on the page — tokens, secrets, health score, KPIs, audit history — is scoped to
  **the environment the running instance belongs to** (`APP_ENV`, i.e. whichever host — test or live — is
  serving the page). There is no in-page environment toggle in v1 — see architecture decision 4 in `plan.md`
  for why a live cross-host switch isn't safely buildable with this codebase's deploy model. Page copy states
  the current environment plainly (e.g. "Nøgler & Rotation — Test").
- [REQ-302] A "Handling påkrævet" queue lists every expired token and every overdue/soon-due secret in the
  current environment, each with an icon + description.
- [REQ-303] A health score (0–100) renders as a ring, colored green ≥80, orange ≥55, else red, using the
  formula `100 − expired×16 − overdue×11 − soon×4` (clamped to [0, 100]) applied to the current environment's
  data.
- [REQ-304] A KPI grid shows: expired-token count, tokens expiring <14 days, overdue-secret count, last-
  rotation date, secrets-in-config count, access-token count — all scoped to the current environment.
- [REQ-305] Access tokens are listed with provider icon, name, last-rotated date+by, expiry countdown, and a
  status badge (Healthy / Udløber snart / Udløbet). **Revised during implementation:** only `GITHUB_TOKEN` is
  a real access token this app holds — "Anthropic" / "OpenAI" in the handoff don't correspond to anything in
  `config.php`; Anthropic's key is a **GitHub Actions repo secret** used only inside CI runners
  (`bin/generate-*.js`), and there is no OpenAI key anywhere in this codebase (it belongs to the separate,
  do-not-touch `f1-intelligence` Vercel deployment — see `CLAUDE.md`). One real token row, not three.
- [REQ-306] Each access token has a **record-only** action: "Roteret — indtast ny dato" reveals a date input;
  saving records the new expiry, recomputes the countdown/badge, and sets last-rotated = today, rotated-by =
  current admin. **This action never talks to the provider** — the human rotates the token at GitHub
  themselves first; this UI only records that it happened.
- [REQ-307] Each config secret is listed with icon, name, an age/policy progress bar (green <80% of policy,
  orange ≥80%, red ≥100%), a status badge (OK / Snart / Forfalden), and an action button whose behavior
  depends on that secret's `mode` (see the implementation note above): `'auto'` secrets show **"Roter nu"**
  (REQ-308); `'record'` secrets show the same **"Roteret — indtast dato"** flow as access tokens (REQ-306) —
  the human changes it at the real source (MySQL, Proton Mail, the paired GitHub secret, or a proper
  key-rotation migration) first, then records that it happened.
- [REQ-308] For `'auto'`-mode secrets only, "Roter nu" **actually rotates**: generates a new secret value,
  writes it to the target environment's config file, resets that secret's age to 0, and records who/when.
  v1: `CHALLENGE_INVITE_SECRET`, `INTEGRATION_SEED_TOKEN`, `CRON_SECRET` are `'auto'`; `DB_PASSWORD`,
  `SMTP_PASSWORD`, `PASSWORD_PEPPER`, `MFA_KEY` are `'record'` (see the implementation notes above for why).
- [REQ-309] "Roter nu" requires (a) admin authentication (already required for this whole area) and (b) an
  explicit confirmation step before executing — no single click writes to a config file.
- [REQ-310] Every token-record (REQ-306), every `'record'`-mode secret record, and every `'auto'`-mode
  secret-rotation (REQ-308) appends one row to a Rotations-historik (audit log): when, who, action, scope
  (environment). The log is append-only from this UI — no edit/delete action exists for audit rows.
- [REQ-311] The audit log is visible on the page (most recent N entries) scoped to the current environment.

### Non-Functional Requirements
- [NFR-301] "Roter nu" must be idempotent-safe against double-submission (e.g. double-click, browser back)
  — it must not silently rotate the same secret twice from one user action.
- [NFR-302] The generated secret value is never displayed back to the admin in plaintext in the UI after
  rotation (matches how the value is used — written to config, not read back for display) unless there is
  a deliberate, explicit "reveal once" affordance; default is to not show it.
- [NFR-303] Config file writes must preserve the rest of the target config file's contents — a rotation
  writes exactly one value, not a full-file regeneration that could clobber unrelated settings.
- [NFR-304] This is the one dashboard where a bug has real blast radius (a bad write could corrupt
  `config.live.php` and take the live site down) — the write path needs a backup-before-write or equivalent
  safety net, not just "it should work."
- [NFR-305] All rotation and token-record actions are logged even if the write itself partially fails, so a
  failed rotation is diagnosable from the audit trail rather than silently vanishing.

## User Story

### Primary User Goal
As the admin, I want to see which access tokens and config secrets are expiring or overdue, and to rotate an
overdue one safely from the UI instead of SSHing in and hand-editing a config file, so that rotation actually
happens on schedule and leaves a record.

### User Story Format
**As an** admin
**I want to** see token/secret health per environment and trigger a safe, audited rotation
**So that** secrets don't go stale for months because rotating them by hand is annoying enough to postpone

### User Personas
- **Primary admin (Djarnis):** the only person who currently rotates secrets, entirely by hand today, with
  no record of when. This feature's entire value proposition is aimed at this person.

## Functionality

### User Flow (token recording)
1. Admin rotates a token at the provider's site (GitHub/Anthropic/OpenAI) themselves — outside this app.
2. Admin returns to Nøgler & Rotation, finds that token's row, clicks "Roteret — indtast ny dato."
3. A date input appears; admin enters the new expiry date and clicks Gem (save).
4. The row updates: new countdown, badge recalculated, last-rotated = today, rotated-by = admin's name.
5. A new audit-log row appears.

### User Flow (secret rotation)
1. Admin sees a secret's badge is "Forfalden" (overdue) or "Snart" (due soon) in the current environment.
2. Admin clicks "Roter nu" for that secret.
3. A confirmation step appears (REQ-309) — admin confirms.
4. The system generates a new value, writes it to that environment's config file, resets the secret's age to
   0, and updates its badge to OK.
5. A new audit-log row appears recording who/when/scope.

### Detailed Specifications
- Health score and its color thresholds, KPI grid contents, and badge taxonomies (Healthy/Snart/Forfalden,
  Healthy/Udløber snart/Udløbet) match the handoff's exact formulas and copy (README §"Nøgler & Rotation").
- **There is no environment toggle in v1** (see architecture decision 4) — the page always shows the host's
  own environment; there is no "show both environments at once" mode and no per-page switch.
- Test↔Prod drift detection (a dashed "Idé" panel in the handoff) is **out of v1 scope** — see epic decision
  D6. If included at all, it is read-only (diff of secret presence/age between environments) and carries no
  write action of its own.

### Mobile Considerations
Secondary priority (this is a desk-admin task in practice), but the token/secret rows and KPI grid should
reflow to single-column on narrow viewports rather than clipping the "Roter nu"/date-input controls off
screen.

### Technical Implementation
- **Auth:** admin-only, same gate as the rest of the admin area; REQ-309's confirm step is a second explicit
  action within an already-authenticated admin session — not a replacement for that gate, an addition to it.
- **Storage:** per-token `expires_at` / `last_rotated_at` / `rotated_by`, per-secret `rotated_at` (age
  derives from now − rotated_at) / `rotated_by`, and an append-only audit log table — two new small tables
  (`admin_secret_state`, `admin_audit_log`, see `plan.md` decision 6). **No environment column** — per
  decision 4, each environment's own database only ever holds that environment's own rows, so there's
  nothing to disambiguate.
- **Config writes:** resolved in `plan.md` decisions 4–5 — targeted single-line replace + backup + atomic
  rename + `opcache_invalidate()`, executed only against the config file on the same host the request is
  already running on (no cross-host write path exists or is being built).
- **Bootstrapping:** on first deploy, no `admin_secret_state` row exists for any real secret/token yet — a
  migration step must seed one row per configured item with a conservative `rotated_at` (e.g. the config
  file's own last-modified time, or `NULL` treated as "unknown age → immediately flagged for review" rather
  than silently read as age-zero) so the health score isn't computed against undefined data on day one. See
  Test Cases below.
- **E2E write-path safety:** the config-writer's target path must be resolved through the same
  `INTEGRATION_SEED_TOKEN`-gated `e2e_token` convention `admin-actions.php` already uses, redirecting writes
  to a fixture file when active — **and this redirect must be hard-blocked whenever `APP_ENV === 'live'`,
  independent of whether the token happens to validate**, so a live-side environment variable/token mixup
  can never cause a real live config write from what was meant to be a test run. This closes the integration
  seam the naive "test the writer against a throwaway path in isolation" approach would leave open (see Test
  Scenarios below) and mirrors the "structurally unreachable on live" guarantee `docs/github-actions.md`
  already documents for the GitHub Actions dashboard's own fixture mode.
- **Provider tokens (GitHub/Anthropic/OpenAI) are recorded, not rotated by this feature** — no API calls to
  those providers are made; REQ-306 is pure bookkeeping.

## Test Scenarios

```gherkin
Feature: Nøgler & Rotation

  Scenario: Token expiry recording
    Given a GitHub token is 3 days from expiry
    When the admin rotates it at github.com and records the new expiry date here
    Then the row's countdown, badge, last-rotated, and rotated-by all update
    And a new audit-log row is created

  Scenario: Secret rotation happens and is logged
    Given a secret is overdue (age ≥ policy) on the Test host's instance
    When the admin clicks "Roter nu", confirms, and the write succeeds
    Then the secret's age resets to 0 and its badge becomes OK
    And config.test.php reflects the new value
    And a new audit-log row records who/when

  Scenario: Rotation requires confirmation
    Given the admin clicks "Roter nu" for a secret
    When no confirmation has yet been given
    Then no write has occurred to any config file

  Scenario: Environment is implicit, not switchable
    Given the admin is on the Test host's Nøgler & Rotation page
    Then every token, secret, KPI, health score, and audit row shown is Test's data only
    And there is no control on the page to view or affect Production's data
```

## Test Cases

```gherkin
Feature: Nøgler & Rotation

  Scenario: Health score formula — no issues
    Given 0 expired tokens, 0 overdue secrets, 0 soon-due items
    Then the health score is 100 and the ring is green

  Scenario: Health score formula — mixed issues
    Given 1 expired token, 1 overdue secret, 2 soon-due items
    Then the health score is 100 − 16 − 11 − 8 = 65 and the ring is orange (≥55, <80)

  Scenario: Health score clamps at 0
    Given enough expired/overdue items that the raw formula goes negative
    Then the displayed health score is 0, not a negative number

  Scenario: Badge thresholds — secrets
    Given a secret's age is exactly 80% of its policy
    Then its badge is "Snart" (due soon), not "OK"
    Given a secret's age is exactly 100% of its policy
    Then its badge is "Forfalden" (overdue)

  Scenario: Badge thresholds — tokens
    Given a token expires in exactly 14 days
    Then its badge is "Udløber snart"
    Given a token's expiry date is in the past
    Then its badge is "Udløbet"

  Scenario: Double-submit protection
    Given the admin double-clicks "Roter nu" (confirmed) for the same secret
    Then only one rotation executes and only one audit-log row is created

  Scenario: Config write scoping
    Given the admin rotates DB_PASSWORD on Test
    Then only config.test.php is modified — config.live.php is untouched
    And every other key in config.test.php is unchanged

  Scenario: Rotation failure is logged, not silent
    Given the config write fails partway (e.g. file permissions)
    Then the admin sees an error state
    And the audit log records the attempted rotation as failed, not as a silent no-op

  Scenario: Plaintext value not echoed by default
    Given a secret rotation just succeeded
    Then the new value is not rendered in the page response in plaintext

  Scenario: Non-admin access rejected
    Given a non-admin logged-in user requests this dashboard or its rotate/record endpoints directly
    Then the request is rejected the same way as any other admin-only action

  Scenario: Audit log is append-only
    Given an existing audit-log row
    Then no UI control exists to edit or delete it

  Scenario: Fresh deploy has no rotation history yet
    Given this feature has just been deployed and admin_secret_state has no row for a real secret
    When the admin opens Nøgler & Rotation
    Then that secret renders with a defined, sane state (e.g. "unknown age — review recommended"), not a
      fatal error, a blank crash, or a silently-assumed age of zero

  Scenario: Policy of zero does not divide by zero
    Given a secret's policy is misconfigured as 0 days
    Then its progress bar and badge render as immediately "Forfalden" without a division-by-zero error

  Scenario: Negative age is clamped, not displayed as negative
    Given a secret's rotated_at is somehow in the future (clock skew)
    Then its displayed age is treated as 0 / OK, not rendered as a negative number

  Scenario: Duplicate define() lines are rejected, not silently resolved
    Given a config file accidentally contains the target constant defined twice
    When a rotation is attempted against it
    Then the write aborts with an error (ambiguous target), and neither occurrence is modified

  Scenario: Backup failure aborts before any config mutation
    Given the pre-write backup copy cannot be created (e.g. permissions)
    When "Roter nu" is confirmed
    Then the config file is not modified at all, and the audit log distinguishes "backup failed" from a
      failed replace/rename

  Scenario: Concurrent rotation requests do not double-rotate
    Given two near-simultaneous confirmed "Roter nu" requests for the same secret arrive as separate HTTP
      requests (not a single browser double-click)
    Then exactly one rotation succeeds and exactly one audit-log row is created for it

  Scenario: The real rotation code path is exercised end-to-end, not just its parts in isolation
    Given E2E mode is active for a designated fixture item (a test-only entry in admin_secret_state, never a
      real secret name)
    When the admin clicks "Roter nu" for the fixture item through the actual UI
    Then the same function used for real secrets runs the full path — DB update, the config-writer
      (redirected to a fixture file, not config.test.php), and the audit-log write — in one pass
    And this redirect is verified to be hard-blocked when APP_ENV is "live", independent of token validity

  Scenario: Generated value matches the target secret's format
    Given a secret whose documented generation is `bin2hex(random_bytes(32))` (64 hex chars)
    When it is rotated
    Then the newly written value matches that exact format/length, not a generic or under-length value
```
