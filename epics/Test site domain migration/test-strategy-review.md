# Test Plan Review: Test Site Domain Migration (hpovlsen.dk → formula-1.helvegpovlsen.dk)

Reviewed via `/test-strategy-manager` against `plan.md`, `feature-1-hosting-dns-config-cutover.md`,
and `feature-2-reference-sweep-ci-convention.md`. Findings below were verified against this repo's
actual code/config (not assumed from the epic's prose) and have already been folded back into the
two feature files, annotated inline as "(added/refined during `/test-strategy-manager` review)" —
this document is the standalone summary of what changed and why, plus what's still open.

## Summary

The epic's structure is sound and its two features are well-scoped for what they claim to cover.
The review found one **significant scope error** in Feature 2 (would have caused real breakage if
executed as originally written), two **missing verification steps** in Feature 1 that the epic's
own evidence (a repo-wide `.htaccess` rule and a WebAuthn e2e fixture pattern) already answers but
the epic hadn't looked up, and several smaller completeness gaps in the file sweep list. All have
been corrected in place. Nothing found here should change the epic's overall shape or feature
boundaries — this is a "tighten before executing" review, not a "redesign" one.

## Strengths

- ✅ Correctly identified `public/includes/passkey.php`'s `PASSKEY_RPID`/`SITE_DOMAIN` runtime check
  as the one hard-blocking dependency, and correctly scoped it as "two values, one atomic change"
  rather than two separate tasks.
- ✅ Correctly identified `BASE_URL_TEST` must be a GitHub *Variable*, sourced from this repo's own
  documented footgun (`docs/github-actions.md`), rather than treating it as a generic secret.
- ✅ Correctly scoped `f1-intelligence/` as an approval-gated, docs-only touch after actually
  checking its CORS header and debug-flag logic, rather than either avoiding it entirely or silently
  bundling it into the sweep.
- ✅ Correctly excluded `epics/Archive/**` and other historical records from the sweep, recognizing
  the difference between a living reference and a point-in-time log.
- ✅ The live-site negative-control scenarios (formula-1.dk untouched, before/after smoke parity)
  are exactly the right shape for proving this migration has zero blast radius outside test.

## Gaps Identified

- ⚠️ **Scope error — email domain vs. website hostname (Feature 2, now REQ-900)**
  - **Impact:** High. `hpovlsen.dk` is not only this project's test hostname — it's also Djarnis's
    real, checkable personal email domain, deliberately reused by `sync-from-live.php` (rewrites
    synced live users' emails there "for manual MFA testing," per existing project memory) and by
    30+ hardcoded `e2e_*@hpovlsen.dk` fixture addresses in `public/tools/test-seed.php`, plus the
    fixture table in `docs/test-strategy.md`. The original Feature 2 draft's `REQ-903` said "sweep
    and update literal `hpovlsen.dk` references" with no distinction — executed literally, that
    would have rewritten all of these to `@formula-1.hpovlsen.dk`, a domain with no mailbox,
    silently breaking `sync:live`'s manual-MFA-testing purpose and every "real-run" (non-intercepted)
    notification e2e spec that depends on a deliverable inbox.
  - **Recommendation:** Applied. Added REQ-900 stating the rule explicitly (only change a hit that
    denotes the site's hostname; never one that denotes an email address), added an explicit
    exclusion list to REQ-903 naming the specific files, added three new Gherkin cases proving the
    fixture files are byte-for-byte unchanged, and rewrote NFR-901's "done" condition from "zero
    grep hits" to "every hit is classified, and none of the hostname-denoting ones are missed."

- ⚠️ **Missing verification — TLS certificate scope (Feature 1, REQ-802)**
  - **Impact:** Medium-high. The original REQ-802 said to "confirm" a cert exists but didn't say
    for which exact hostname. `public/.htaccess:10-13` forces a `www.` prefix via a host-agnostic
    rewrite rule (matches any host, not specifically `hpovlsen.dk`), so the site will almost
    certainly end up served from `www.formula-1.hpovlsen.dk` — a fourth-level hostname. A cert
    covering only the bare `formula-1.hpovlsen.dk` would look "confirmed" under a casual check and
    still fail the actual traffic pattern once the `.htaccess` rule redirects there.
  - **Recommendation:** Applied. REQ-802 now names the exact hostname to verify via
    `openssl s_client`, and REQ-803 now states the www-redirect outcome as *expected* (derived from
    reading the rule) rather than a fully open unknown — the POST-body probe still runs, but as
    confirmation, not discovery.

- ⚠️ **Missing verification — DNS propagation (Feature 1, new REQ-802a)**
  - **Impact:** Low-medium, cheap to fix. Nothing in the original Feature 1 checked that the DNS
    record had actually propagated (vs. only resolving from the machine that created it) before
    downstream TLS/config work began.
  - **Recommendation:** Applied. Added REQ-802a: `dig +short` from an external resolver for both the
    bare and `www.`-prefixed forms, before REQ-802's cert check.

- ⚠️ **Overstated verification burden — passkey credential breakage (Feature 1, REQ-806)**
  - **Impact:** Low, but would have caused wasted effort or a misleading test case. The original
    REQ-806 asked to "confirm an old credential fails and a new one succeeds" without noting that
    `tests/e2e/auth/{35-passkey,36-passkey-negative}.spec.js` attach a **fresh virtual authenticator
    per test** (per the spec's own comment: "credentials live and die with this page") — meaning the
    automated suite has no persisted "old" credential to test against in the first place, and will
    pass unmodified post-cutover with zero fixture changes.
  - **Recommendation:** Applied. REQ-806 now splits this into an automated half (the e2e specs need
    no changes and already prove "new credential works") and a manual half (only relevant if Djarnis
    has a real passkey registered against the old domain on his own device) — with an explicit note
    that the manual half cannot be automated, so it shouldn't be tracked as a missing automated test.

- ⚠️ **Incomplete file list — reference sweep (Feature 2, REQ-903)**
  - **Impact:** Low-medium. A broader grep (`hpovlsen` without the `.dk` anchor, across all text
    files) found `f1-intelligence/README.md`'s "Step-by-step deployment for hpovlsen.dk +
    formula-1.dk" line, which the original file list missed.
  - **Recommendation:** Applied — added to REQ-903 and to REQ-905's approval-gated group.

- ⚠️ **Unverified assumption — SPF/DKIM hostname check (Feature 2, new REQ-908)**
  - **Impact:** Low (this one turned out fine, but was unverified). `tests/security/security.js`
    gates SPF/DKIM expectations on `hostname.includes('hpovlsen')` — a substring check, not an exact
    match. It happens to keep working unchanged against `formula-1.hpovlsen.dk` (still contains
    `"hpovlsen"`), but the epic hadn't looked at this file at all before this review.
  - **Recommendation:** Applied — added REQ-908 documenting the check and requiring one real
    `npm run test:security` run against the new domain to confirm, rather than trusting the
    substring-match reasoning alone.

- ⚠️ **Rollback asymmetry (Feature 1, NFR-803)**
  - **Impact:** Medium. The original rollback NFR only covered reverting `config.test.php`. If
    `BASE_URL_TEST` had already been flipped (Feature 2, REQ-901) before a problem was found, a
    config-only revert leaves CI pointed at a domain the current config no longer serves — the same
    failure mode NFR-802's ordering rule exists to prevent, just triggered in reverse.
  - **Recommendation:** Applied — NFR-803 now requires both to revert together, and the matching
    test case was updated accordingly.

- ⚠️ **Unexamined assumption — a clean cutover window exists (Feature 1, new NFR-804)**
  - **Impact:** Low. `NFR-802`'s strict ordering is correct but implicitly assumed a quiet window
    could be found to execute it in. Checking `.github/workflows/*.yml` shows `cron-session-gc` and
    `cron-notifications` both fire *hourly* against test — there is no gap in the schedule fully free
    of a `trigger-test` job.
  - **Recommendation:** Applied — added NFR-804 stating this explicitly and pointing at the
    workflows' own "accepted tradeoff" language as the reason a single transient miss is fine and
    doesn't need retry engineering. This is a documentation fix, not a new blocking requirement.

## Enhanced Test Coverage

All of the following have already been added to the two feature files (not just proposed here):

**Feature 1** — new/changed Gherkin: DNS-resolves-externally check, TLS-SAN-includes-www check,
passkey e2e specs pass unmodified (automated), real-credential-rejected (manual, explicitly labeled
as such), and revert-both-config-and-CI-variable-together.

**Feature 2** — new/changed Gherkin: sweep-hits-correctly-classified (replacing zero-hits-only),
fixture-emails-untouched (test-seed.php diff is empty), sync-from-live still targets a real inbox,
and security.js's SPF/DKIM substring check still resolves correctly.

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A blind find-replace changes `e2e_*@hpovlsen.dk` fixture/sync emails | Was high before this review, now low | High (breaks manual MFA testing + "real-run" notification specs silently) | REQ-900 rule + explicit exclusion list + dedicated test cases now in Feature 2 |
| TLS cert doesn't cover the actual serving hostname (`www.` prefix) | Medium | Medium (test site down until re-issued) | REQ-802 now names the exact hostname; verified via `openssl s_client` before cutover |
| `BASE_URL_TEST` and `config.test.php` fall out of sync during a partial rollback | Low-medium | Medium (CI red until someone notices the asymmetry) | NFR-803 now requires both to revert together |
| A scheduled hourly cron-trigger job fires mid-cutover | High (given hourly cadence) | Low (workflows already self-describe as tolerant of transient misses) | NFR-804 documents this as accepted, not something to prevent |
| Old test-domain passkey credentials silently "just don't work" with no one having planned for it | Low (epic already anticipated this) | Low (test-only, one-time re-registration) | REQ-806 now correctly scopes it as one manual check, not a missing automated test |

## Open Items (not resolved by this review — need Djarnis's input)

- Exactly which host form (`www.formula-1.helvegpovlsen.dk` vs. bare) Simply.com will actually serve
  once DNS/TLS are provisioned — REQ-802/803 predict `www.` based on the existing `.htaccess` rule,
  but this is still a "verify, don't skip" step, not yet executed.
- Where the `<project>.helvegpovlsen.dk` convention note (REQ-906) and hpovlsen.dk's fate (REQ-907,
  REQ-808) should actually live for cross-project visibility — this repo's own files can't be the
  only copy if the goal is a future *different* repo finding it.
- Whether `helvegpovlsen.dk` uses SimpleLogin-style or Proton-style mail (REQ-908, Revision 2 below)
  — unknown as of this writing.

---

## Revision 2 (same review, after Djarnis's domain correction)

Djarnis corrected the target domain after reading Revision 1: Simply.com only allows creating
subdomains under `helvegpovlsen.dk`, not `hpovlsen.dk`, so the new hostname is
`formula-1.helvegpovlsen.dk`. He also specified two things Revision 1 hadn't covered at all: the FTP
document root the new subdomain serves from (`/test.formula-1.dk`, deliberately not matching the
hostname) and an explicit request to delete the old `/hpovlsen.dk` FTP directory afterward. All
three are now in the epic (Feature 1 REQ-801a, REQ-809; renamed hostname throughout). Two more
findings surfaced while making that pass, both now folded in:

- ⚠️ **`FTP_ROOT_TEST` was missing from the epic entirely.** Revision 1 only ever discussed
  `config.test.php`'s `SITE_URL`/`PASSKEY_RPID` as the thing that changes — it never looked at
  `build-deploy/.env`, which is what actually controls *where `deploy:test` uploads files*
  (`FTP_ROOT_TEST=/hpovlsen.dk` today, confirmed by reading the file and `deploy.js:81`). Had this
  gone unnoticed, executing the original Feature 1 exactly as written would have updated the site's
  claimed hostname without ever moving where its files are actually served from — the new subdomain
  would have had nothing to point at. Now REQ-801a, with an explicit note that it and REQ-804 must
  change together, and a dedicated test case proving the two are independent settings.

- ⚠️ **The `security.js` SPF/DKIM finding from Revision 1 flipped from "fine" to "needs checking."**
  Revision 1's REQ-908 concluded `hostname.includes('hpovlsen')` needed no code change because
  `formula-1.hpovlsen.dk` still contains that substring. `formula-1.helvegpovlsen.dk` does **not**
  (`helvegpovlsen` vs. `hpovlsen` — the substring isn't there). This is exactly the kind of thing a
  domain-name correction can silently invalidate in a document that cites specific strings as
  evidence — worth flagging generally: **when a domain/hostname changes after a review has already
  cited exact-string reasoning, re-check every citation that depended on the specific characters,
  not just the ones that were already flagged as uncertain.** Re-reading the actual `security.js`
  code (not just the one line originally quoted) also revealed the check is informational-only
  (feeds a `warn(...)`, never a hard pass/fail) — lower severity than Revision 1's framing implied,
  but still worth Djarnis confirming which mail-forwarding style `helvegpovlsen.dk` uses before
  trusting the suggested remediation text.

No other Revision 1 finding needed to change — the email-domain-vs-hostname distinction (REQ-900),
the passkey/gotcha-#20 findings, and the CSP/cron-cadence items are all independent of exactly which
parent domain the new subdomain lives under.
