# Feature 2: Reference Sweep, CI Wiring & Test-Subdomain Convention

The cleanup half of the epic, and the part that actually delivers on "make this the future
pattern" — updating the one CI variable that isn't sourced from `config.test.php`, sweeping every
currently-active doc/script that still says `hpovlsen.dk` **as a hostname**, and writing the
`<project>.helvegpovlsen.dk` convention down somewhere it will still be found from a brand-new
project's repo (this repo's `CLAUDE.md` and memory are scoped to formula-1.dk and won't be visible
there).

**Revision note:** the original draft targeted `formula-1.hpovlsen.dk` as both the new hostname and
the future convention's parent domain. Corrected to `formula-1.helvegpovlsen.dk` /
`<project>.helvegpovlsen.dk` — Simply.com only allows creating subdomains under `helvegpovlsen.dk`.
`hpovlsen.dk` is unaffected as a domain and keeps its existing role as Djarnis's real email domain
(gotcha #15) — nothing in this feature touches that.

## Requirements

### Functional Requirements

- [REQ-900] **Read this before doing REQ-903.** Added during `/test-strategy-manager` review after
  a wider grep (`grep -rl "hpovlsen"`, no `.dk` anchor) turned up files the original file list
  missed — and, more importantly, revealed that most `hpovlsen.dk` occurrences in this repo are
  **not** the website's hostname at all. This is already documented as `docs/gotchas.md`
  **gotcha #15** ("`sync:live` rewrites all user emails to `@hpovlsen.dk`"), which this epic's first
  draft hadn't cross-referenced: `hpovlsen.dk` is Djarnis's real, checkable personal email domain
  with catch-all forwarding, deliberately reused by `sync-from-live.php`'s live→test email rewrite
  and by the large family of `e2e_*@hpovlsen.dk` fixture addresses in `public/tools/test-seed.php`
  (30+ hardcoded addresses, per gotcha #15's own text) and `docs/test-strategy.md`'s fixture-email
  table — none of these denote where the site is *served*, and **must not be changed to
  `formula-1.helvegpovlsen.dk`** (nor to anything under `helvegpovlsen.dk` at all — that domain is a
  *different* one from the email domain, used here only for the new subdomain's hostname). Doing so
  would point real-run notification tests and sync-rewritten live emails at a mailbox that doesn't
  exist, breaking exactly the catch-all-inbox mechanism gotcha #15 describes. The rule going
  forward: **only change a `hpovlsen.dk` occurrence if it denotes the site's hostname
  (`SITE_URL`/`PASSKEY_RPID`/a URL you'd type into a browser) — never one that denotes an email
  address or a mail-routing check.** Every file in REQ-903's list must be read, not blanket
  find-replaced. (The project memory this epic initially drew on,
  `project_sync_live_email_domain`, was itself stale on this exact point — written before
  `test-seed.php`'s fixtures moved from `@test.localhost` onto the shared `@hpovlsen.dk` domain;
  corrected as part of this review.)
- [REQ-901] Update the GitHub Actions repository **Variable** `BASE_URL_TEST`
  (`Settings → Secrets and variables → Actions → Variables`) from `https://www.hpovlsen.dk` to the
  confirmed new value from Feature 1 REQ-803 (expected `https://www.formula-1.helvegpovlsen.dk`).
  Per `docs/github-actions.md`'s own documented footgun, this must be a Variable, not a Secret —
  storing it as a Secret makes every `vars.BASE_URL_TEST` reference silently evaluate empty.
  **Sequencing:** only do this after Feature 1's cutover is confirmed live (NFR-802) — updating
  this first would point CI at a domain that isn't serving yet.
- [REQ-902] Re-verify (not re-rotate) that the `CRON_SECRET_TEST` secret still matches
  `config.test.php`'s `CRON_SECRET` value, and that every workflow combining
  `vars.BASE_URL_TEST` + `secrets.CRON_SECRET_TEST` (`cron-notifications.yml`,
  `cron-qualifying-import.yml`, and any other `trigger-test` job) completes successfully end-to-end
  against the new domain. The secret's *value* doesn't change — only confirm the pairing still
  resolves correctly once REQ-901 lands.
- [REQ-903] Sweep currently-active docs and scripts for `hpovlsen.dk` occurrences that denote the
  **site's hostname** (per REQ-900's rule) and update those to `formula-1.helvegpovlsen.dk`:
  - `docs/deployment.md` (Environments table)
  - `docs/testing.md`, `docs/github-actions.md`, `docs/admin-dashboards.md`, `docs/cron-jobs.md`,
    `docs/gotchas.md`, `docs/getting-started.md`, `docs/commands.md`
  - `docs/disaster-recovery/runbook.md` and `docs/disaster-recovery/drill-plan-test.md` — these are
    live runbooks with hardcoded `https://www.hpovlsen.dk` commands and URLs used *during an actual
    drill* (e.g. `node tests/smoke.js https://www.hpovlsen.dk`, admin-login steps, cron-endpoint
    fetches); leaving these stale means a real disaster-recovery drill targets a domain that may no
    longer serve anything
  - `build-deploy/DEPLOYMENT.md` — including its shorthand table language ("Uploads... to
    **hpovlsen.dk**"); when rewording, note the new domain/FTP-path split explicitly (uploads go to
    `/test.formula-1.dk`, serving `formula-1.helvegpovlsen.dk` — the two strings no longer match,
    unlike live) rather than silently keeping the old domain-as-shorthand-for-path habit
  - `build-deploy/restore-db.js`'s `"⚠️  TEST database (hpovlsen.dk)"` log label
  - `tests/smoke.js`'s usage string (`"Usage: BASE_URL=https://hpovlsen.dk node tests/smoke.js"`)
  - `CLAUDE.md`'s own "domains formula-1.dk (live) and hpovlsen.dk (test)" line
  - `.claude/settings.json`'s permission allowlist entry
    `Bash(curl -sv https://www.hpovlsen.dk/f1-intelligence/query.php)`
  - `.claude/settings.local.json`'s permission allowlist entry
    `Bash(BASE_URL=https://www.hpovlsen.dk npm run test:smoke)`
  - `docs/f1-intelligence-reference.md`, `paddock-rumors/README.md`, `paddock-rumors/ROADMAP.md`,
    `f1-intelligence/README.md` (its "Step-by-step deployment for hpovlsen.dk + formula-1.dk" line)
    — prose only, no functional dependency found (see REQ-905 for the approval gate on even these)

  **Explicitly excluded from this sweep — email-domain occurrences, per REQ-900 (added during
  `/test-strategy-manager` review):**
  - `public/tools/test-seed.php` — 30+ `e2e_*@hpovlsen.dk` fixture addresses; leave every one as-is
  - `public/tools/sync-from-live.php` — the live→test email-rewrite target domain and its
    `e2e_testing_*@hpovlsen.dk` allowlist; leave as-is
  - `public/mfa_challenge.php:157` — a masked-email example (`th•••@hpovlsen.dk`) illustrating the
    masking format, not a config value; leave as-is
  - `docs/test-strategy.md`'s fixture-email table (lines documenting `e2e_*@hpovlsen.dk` addresses)
    — leave as-is; if this doc has *other*, hostname-denoting mentions elsewhere, those alone may be
    in scope, but read each hit individually rather than assuming the whole file is one category
  - Anything about `f1_admin@helvegpovlsen.dk` (`F1_ADMIN_EMAIL`, used on **both** environments
    already, unrelated to this migration) — added during this revision: `helvegpovlsen.dk` is now
    *also* the new test hostname's parent domain, which makes it doubly important not to confuse
    "the admin's email address happens to live on this domain" with "this domain is the site's
    host." The admin email address does not change.
- [REQ-904] Audit `hpovlsen.dk` mentions in e2e spec files
  (`tests/e2e/02-auth.spec.js`, `04-betting.spec.js`, `05-profile.spec.js`, `07-cron.spec.js`,
  `tests/e2e/admin/11-invites.spec.js`, `12-users.spec.js`, `13-scoring.spec.js`) to confirm each
  one is a comment/description string, not a hardcoded assertion or literal URL bypassing the
  `BASE_URL` injection Feature 1 relies on — update the comments, and treat any hit that turns out
  to be a live literal (not expected, but unverified until read) as a Feature 1 blocker, not a
  Feature 2 doc fix.
- [REQ-905] `f1-intelligence/` and `public/f1-intelligence/` are the system `CLAUDE.md` flags as
  live and requiring explicit approval before any modification. This feature's sweep only touches
  prose in their *reference docs* (`docs/f1-intelligence-reference.md`, `f1-intelligence/README.md`,
  `paddock-rumors/README.md`, `paddock-rumors/ROADMAP.md`) — no code, no Vercel config, no CORS
  change (already `Access-Control-Allow-Origin: *`; `F1_INTELLIGENCE_DEBUG` keys off `APP_ENV`, not
  the domain). Even so, get Djarnis's explicit go-ahead before editing these specific files, per the
  standing project rule — don't fold them into a generic doc-sweep commit without calling them out.
  Separately, Feature 1 REQ-809 needs the same sign-off before *deleting* the deployed
  `f1-intelligence/` client at `/hpovlsen.dk` — that's a bigger action than these doc edits and is
  gated on its own.
- [REQ-908] `tests/security/security.js:762,795` gate their **suggested remediation text and DKIM
  selector probe order** — not a pass/fail assertion; both call sites feed a `warn(...)`, and the
  actual SPF/DMARC/DKIM checks pass or warn based on real DNS lookups regardless — on
  `hostname.includes('hpovlsen')`. **Corrected during `/test-strategy-manager` review after the
  domain change to `helvegpovlsen.dk`:** the original review (against the `hpovlsen.dk`-subdomain
  draft) found this substring check "still holds" — that's no longer true. `helvegpovlsen.dk` does
  **not** contain the substring `"hpovlsen"` (h-e-l-v-e-g-p-o-v-l-s-e-n vs. h-p-o-v-l-s-e-n), so
  scanning `formula-1.helvegpovlsen.dk`'s apex will now take the `else` branch — Proton-style SPF
  suggestion text and DKIM selector order (`protonmail*` first) instead of the SimpleLogin-style one
  (`dkim*` first) `hpovlsen.dk` gets today. Because the DKIM probe tries every selector in its list
  before giving up, this only matters if the real domain's DKIM selector is *not* one of the shared
  ones (`default`, `mail`, `k1`, `s1`, `s2`) both lists already include — **action:** before running
  `npm run test:security` against the new domain, confirm whether `helvegpovlsen.dk`'s actual mail
  setup is SimpleLogin-style or Proton-style (unknown as of this writing — it hosts
  `f1_admin@helvegpovlsen.dk` today, but that doesn't by itself say which provider). If it turns out
  to be SimpleLogin-style like `hpovlsen.dk`, either accept the informational-only warning as noise,
  or extend the `hostname.includes(...)` check to also match `helvegpovlsen` — a one-line code
  change, and the only place in this whole epic where `security.js` might actually need editing
  (contradicting the original review's "no code change to security.js" conclusion, which was
  correct only for the domain this epic no longer targets).
- [REQ-906] Write down the reusable convention: `<project-slug>.helvegpovlsen.dk` is the standard
  test-site subdomain pattern for every future project (replacing "register/borrow a new bare
  domain per project's test environment"), and specifically **not** `hpovlsen.dk` — Simply.com
  doesn't allow subdomains there. At minimum, record it in this epic's `plan.md` (already done —
  this repo's git history preserves it). Ask Djarnis where else it should live for cross-project
  visibility, since a brand-new project's own fresh repo won't have this repo's `CLAUDE.md` or
  memory available — candidates to raise with him: a personal ops/notes location he already keeps
  outside any single project repo, or a line added to whatever bootstrapping checklist/template he
  uses when starting a new project.
- [REQ-907] Record Feature 1 REQ-808/REQ-809's decision in the same place as REQ-906's convention
  note: `hpovlsen.dk` the domain is **not** decommissioned (it keeps its email role, gotcha #15) —
  only its old FTP directory (`/hpovlsen.dk`) and its role as this project's test-site host are
  retired, once Feature 1 confirms the new site and deletes that directory. Write this down
  precisely — "what happened to hpovlsen.dk" should have one clear, findable answer, not three
  slightly different half-memories across this epic, `CLAUDE.md`, and conversation history.

### Non-Functional Requirements

- [NFR-901] Final verification is **not** a bare "zero hits" grep — corrected during
  `/test-strategy-manager` review, since REQ-900 means a large, legitimate class of `hpovlsen.dk`
  hits (every `e2e_*@hpovlsen.dk` fixture and the `sync-from-live.php` rewrite target) is supposed
  to survive the sweep unchanged. Run `grep -rn "hpovlsen\.dk" .` (excluding `node_modules`, `.git`,
  `build-deploy/backups`), then classify every remaining hit into exactly one of: (a) email
  domain — expected to remain, per REQ-900/903's exclusion list; (b) intentionally-excluded
  historical record (`epics/Archive/**`, `paddock-rumors/SESSION_HANDOVER.md`, a dated/checkmarked
  line in `security-findings-remaining.md` describing already-completed past work); (c) a hostname
  reference that should have been updated by REQ-903 and was missed. The sweep is only complete when
  category (c) is empty — not when the raw hit count is zero. Separately, a `grep -rn
  "helvegpovlsen\.dk"` pass should turn up only the intended new-hostname references plus the
  pre-existing, unrelated `f1_admin@helvegpovlsen.dk` mentions (REQ-903's exclusion) — nothing else.
- [NFR-902] No GitHub Actions workflow **YAML** file needs a literal domain edit — they reference
  `vars.BASE_URL_TEST`/`secrets.CRON_SECRET_TEST` indirectly. Confirm this holds for
  `cron-notifications.yml` and `cron-qualifying-import.yml` specifically (this epic's research found
  their `hpovlsen.dk` mentions were in comments only) before treating REQ-901 as sufficient on its
  own — if either workflow turns out to hardcode the URL somewhere this research missed, that's a
  REQ-903-equivalent fix, not assumed away.
- [NFR-903] The doc/script sweep must not touch any file under `epics/Archive/**` — those are
  point-in-time records of past work, not living instructions, and editing them would misrepresent
  history.

## User Story

**As** Djarnis, about to start more projects that will each need their own test environment
**I want** this migration to leave behind a written naming convention, not just a one-off domain
change
**So that** the next project's test site is named consistently without re-deciding or
re-discovering the pattern from scratch

## Functionality

### User Flow

1. Once Feature 1 confirms the new domain is live, the `BASE_URL_TEST` GitHub Variable is updated
   (REQ-901) and the cron-trigger workflows are watched through one real run to confirm they still
   succeed (REQ-902).
2. The doc/script sweep (REQ-903/904) runs as a single reviewable change, file list checked off
   against the list above.
3. `docs/f1-intelligence-reference.md` and the `paddock-rumors/` doc mentions are called out
   separately for Djarnis's explicit sign-off (REQ-905) rather than bundled silently into the sweep
   commit.
4. Before the first `npm run test:security` run against the new domain, `helvegpovlsen.dk`'s actual
   mail-provider style is confirmed and `security.js` is updated if needed (REQ-908).
5. The convention (REQ-906) and the old-domain decision (REQ-907) are written down together in a
   location Djarnis confirms he'll actually check next time.
6. A final `grep` pass (NFR-901) confirms the sweep is complete.

### Technical Implementation

- Almost entirely docs, one GitHub Actions Variable, two `.claude/settings*.json`
  permission-allowlist strings, and a handful of comment/log-label strings in JS test tooling.
- `build-deploy/restore-db.js:72` and `tests/smoke.js`'s usage string are two *code* files in the
  sweep with string-literal-only changes (a warning label and a usage hint) — no logic branches on
  these strings.
- `tests/security/security.js:762,795` is the one place that might need an actual logic change
  (REQ-908), and only conditionally, depending on what `helvegpovlsen.dk`'s real mail setup turns
  out to be.

## Test Scenarios

```gherkin
Feature: Reference sweep, CI wiring & convention doc

  Scenario: CI cron-trigger workflows succeed against the new domain
    Given BASE_URL_TEST has been updated to the new subdomain as a repository Variable
    When cron-notifications.yml and cron-qualifying-import.yml next run their trigger-test job
    Then both complete successfully against formula-1.helvegpovlsen.dk

  Scenario: The repo-wide sweep is complete, correctly classified
    Given every listed hostname-denoting doc/script has been updated
    When grep -rn "hpovlsen\.dk" is run across the repo and every hit is classified
    Then every remaining hit is either an intentionally-preserved email address/mail-routing check
      or an intentionally-excluded historical path — none is a missed hostname reference

  Scenario: E2E fixture and sync-rewrite email addresses are untouched
    Given test-seed.php, sync-from-live.php, and docs/test-strategy.md's fixture table all use
      @hpovlsen.dk as an email domain, not a hostname
    When the reference sweep runs
    Then none of these email addresses are changed to anything under helvegpovlsen.dk
    And sync:live and the e2e notification "real-run" specs still deliver to a real, checkable inbox
      afterwards

  Scenario: The admin email address is not mistaken for a hostname reference
    Given F1_ADMIN_EMAIL (f1_admin@helvegpovlsen.dk) already lives on the same parent domain the new
      test subdomain now uses
    When the reference sweep runs
    Then F1_ADMIN_EMAIL is untouched in both config.test.php and config.live.php

  Scenario: Disaster-recovery runbooks target the live test domain
    Given docs/disaster-recovery/runbook.md and drill-plan-test.md have been updated
    When a drill is next run following those docs
    Then every command and URL in them targets formula-1.helvegpovlsen.dk, not a decommissioned
      domain

  Scenario: f1-intelligence-adjacent docs are updated only with explicit sign-off
    Given docs/f1-intelligence-reference.md and paddock-rumors/{README,ROADMAP}.md mention
      hpovlsen.dk
    When those specific files are edited as part of this sweep
    Then Djarnis has explicitly approved that edit, separately from the general sweep

  Scenario: security.js's SPF/DKIM heuristic is deliberately checked, not assumed unchanged
    Given helvegpovlsen.dk does not contain the substring "hpovlsen"
    When npm run test:security is run against the new domain for the first time
    Then whether helvegpovlsen.dk needs the SimpleLogin-style branch has been confirmed (not
      assumed), and security.js is updated if it does

  Scenario: The convention is discoverable outside this repo's own memory
    Given this epic is complete
    When Djarnis starts a hypothetical next project and needs to name its test site
    Then he can point to a written convention for <project>.helvegpovlsen.dk without needing this
      repo's CLAUDE.md or Claude Code memory to be in context
```

## Test Cases

```gherkin
Feature: Reference sweep, CI wiring & convention doc — detailed cases

  Scenario: BASE_URL_TEST is stored as a Variable, not a Secret
    Given the GitHub Actions repo settings are inspected after REQ-901
    When BASE_URL_TEST's storage location is checked
    Then it is found under the Variables tab, and no leftover BASE_URL_TEST Secret with a stale
      value still exists to be silently shadowed or to shadow it

  Scenario: CRON_SECRET_TEST pairing survives the domain change untouched
    Given config.test.php's CRON_SECRET value is compared against the CRON_SECRET_TEST GitHub secret
    When compared after this feature's changes land
    Then the two values still match exactly as they did before the migration (no rotation implied
      by a hostname change)

  Scenario: restore-db.js's warning label reflects the new domain
    Given a test-target restore is run interactively
    When the confirmation prompt is shown
    Then it reads "TEST database (formula-1.helvegpovlsen.dk)", not the old domain — so an operator
      choosing between test/live restore targets isn't misled by a stale label

  Scenario: tests/smoke.js usage string matches a working example
    Given a developer runs tests/smoke.js with no BASE_URL set
    When the usage error is printed
    Then the example URL shown is one that actually resolves to the current test site

  Scenario: e2e spec comments are prose-only, confirmed not literal test dependencies
    Given each hpovlsen.dk mention in tests/e2e/**/*.spec.js is inspected
    When categorized as comment/description vs. functional literal
    Then all are comments, and any exception found is escalated as a Feature 1 blocker before this
      feature's sweep is considered complete

  Scenario: security-findings-remaining.md's mention is correctly classified before action
    Given its hpovlsen.dk reference is a checkmarked, dated, already-completed item
    When deciding whether to edit it as part of the sweep
    Then it is left as a historical record (excluded, like Archive/) rather than rewritten to imply
      the described past setup used the new domain at the time

  Scenario: The convention note names both the pattern and the old domain's disposition
    Given REQ-906 and REQ-907 are both written up
    When the resulting note is read on its own, out of conversation context
    Then it states the <project>.helvegpovlsen.dk pattern AND what happened to hpovlsen.dk's Paddock
      Picks test deployment (files removed, domain/email role kept), without requiring the reader to
      already know this epic's history

  Scenario: test-seed.php's fixture emails are byte-for-byte unchanged
    Given public/tools/test-seed.php has 30+ hardcoded e2e_*@hpovlsen.dk addresses
    When a diff of this file is taken before and after the reference-sweep commit
    Then it shows no changes at all — this file is entirely out of scope for REQ-903

  Scenario: sync-from-live.php still rewrites synced users to a real, deliverable inbox
    Given npm run sync:live is run after this feature ships
    When live users are copied into the test database
    Then their emails are still rewritten to @hpovlsen.dk (Djarnis's real catch-all), not anything
      under helvegpovlsen.dk or any other address that would silently swallow manual MFA test emails

  Scenario: F1_ADMIN_EMAIL is unaffected by helvegpovlsen.dk becoming the new test hostname's parent
    Given F1_ADMIN_EMAIL is f1_admin@helvegpovlsen.dk in both config.test.php and config.live.php
    When this feature's sweep runs
    Then that value is byte-for-byte unchanged in both files

  Scenario: security.js's SPF/DKIM heuristic is corrected if helvegpovlsen.dk turns out to need it
    Given helvegpovlsen.dk's actual DKIM selector style has been confirmed (SimpleLogin-style or
      Proton-style)
    When it turns out to be SimpleLogin-style, matching hpovlsen.dk's setup
    Then the hostname.includes('hpovlsen') check in security.js:762,795 is extended to also match
      helvegpovlsen, and npm run test:security's DKIM probe finds the real selector instead of
      exhausting only the Proton-style guesses first
```
