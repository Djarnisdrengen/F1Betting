# Test Plan: Passkey (WebAuthn) Authentication

Epic: `epic-passkey-authentication.md` · Plan: `plan.md`
Stack: **Playwright (Node.js) E2E + `node --test`** (no PHPUnit). Reviewed by the test-manager skill — verdict in §8.

---

## 1. Scope & objectives

- **Testing:** passkey registration, the two new session-promotion paths (`login_verify` passwordless, `challenge_verify` second factor), method ordering/override, revocation, and the WebAuthn challenge lifecycle.
- **Why it matters:** the MFA release established that the **session state machine is the thing to test hardest** — this epic adds *two more places* that set `$_SESSION['user_id']`. Any hole in either is a full auth bypass. The cryptography lives in the vendored lib; our risk is in the glue: challenge single-use, credential↔user binding, promotion contract, CSRF.
- **Success criteria:** all bypass negatives green, challenge single-use proven, password-only regression byte-identical, no existing MFA spec regresses.
- **Out of scope:** hardware-security-key UX (unsupported per epic), attestation validation (attestation `none` by design), SMS/social login.

## 2. Test types

| Type | Tool | Scope |
|---|---|---|
| Unit | `node --test` | `passkey.js` base64url codecs (round-trip, URL-unsafe chars, padding) |
| Unit | PHP CLI harness (`tests/unit/passkey-harness.php`) | `passkeyChallengeBegin/Take` lifecycle (single-use, expiry, purpose mismatch), sign-count policy table (0/0, 0/n, n/n, n/<n), `passkeyRpId()` fail-loud guard, vendored-lib check against published WebAuthn vectors (rerun on version bumps) |
| Integration/E2E | Playwright + **CDP virtual authenticator** (`WebAuthn.enable` + `WebAuthn.addVirtualAuthenticator`: ctap2, transport `internal`, residentKey + UV, `automaticPresenceSimulation`) | enroll, both login paths, ordering, revoke, **negatives** — config is Chromium-only (`tests/playwright.config.js:46`), which is exactly where the CDP API works |
| Security | `npm run test:security` + spec asserts | bypass, replay, cross-user, CSRF, rate-limit, enumeration parity |
| Manual | real devices on test env | iOS Safari (Face/Touch ID), Android Chrome, Windows Hello optional — **one-time launch gate** (decided 🔴-1 B); checklist in `plan.md` Verification |

New specs: `tests/e2e/auth/35-passkey.spec.js`, `36-passkey-negative.spec.js`. Keep them **out of smoke** — UI-driven enrollment makes them slow (§8 🟡-3).

## 3. Test data

WebAuthn credentials **cannot be seeded server-side** — the private key lives in the (virtual) authenticator. Each spec enrolls through the profile UI with a virtual authenticator in `beforeEach` (same philosophy as `30-totp-mfa.spec.js` enrolling TOTP through the UI). DB-side manipulation goes through `public/tools/test-seed.php` additions:

| Seed action | Purpose |
|---|---|
| `cleanup_passkeys` | delete `user_passkeys` rows for the test user (afterEach) |
| `set_passkey_sign_count` | force a stored `sign_count` above the authenticator's next value (SEC-01) |

| User | State | Purpose |
|---|---|---|
| U1 | password-only | regression: login path unchanged |
| U2 | passkey only (enrolled in-spec) | passwordless + challenge-gating + recovery-code issuance |
| U3 | passkey + confirmed TOTP | default ordering, override, fallback |
| U4 | passkey + TOTP + email OTP | full method matrix + "Other options" |
| U5 | passkey enrolled, then virtual authenticator **removed** | lost-device fallback |

Email-fallback cases reuse the SMTP-interception JSONL helpers (`tests/helpers/intercepted-mail.js`) unchanged.

## 4. Acceptance criteria

See the epic's Gherkin. Critical: passwordless promotion regenerates session · challenge leads with passkey per ordering · revoke requires re-auth · last-factor revoke returns to password-only · first-factor enrollment issues recovery codes · password-only member unaffected.

## 5. Test cases

| ID | Case | Expected | Pri | Type |
|---|---|---|---|---|
| REG-01 | Enroll via Security tab (virtual authenticator) | row listed with label; rename works | High | E2E |
| REG-02 | First-factor enrollment | recovery codes flashed once (existing pattern) | High | E2E |
| REG-03 | Re-register same authenticator | excluded (`excludeCredentials`) → graceful error, single row | Med | E2E |
| REG-04 | `register_*` actions logged-out | rejected, no row | Critical | Sec |
| PWL-01 | Passwordless login (U2) | `user_id` set, session id regenerated, `last_login` + `last_used_at` updated | Critical | E2E |
| PWL-02 | `login_verify` with no prior `login_options` challenge | rejected, no session | Critical | Sec |
| PWL-03 | Replay same assertion payload twice | second rejected (challenge single-use) | Critical | Sec |
| PWL-04 | Assertion for revoked/unknown/bad-signature credential | **byte-identical** generic JSON body across all failure modes (assert response equality), no session, `recordLoginAttempt` | High | Sec |
| PWL-05 | Repeated `login_verify` failures | `isRateLimited` kicks in, `Retry-After` (the one allowed divergence) | High | Sec |
| CHA-01 | Password login, passkey enrolled (U2) | held at challenge; passkey is lead block | Critical | E2E |
| CHA-02 | Passkey satisfies challenge | pending cleared, `user_id` set, session regenerated | Critical | E2E |
| CHA-03 | `challenge_verify` without `mfa_pending` | rejected (bypass guard, mirrors MFA-01/03) | Critical | Sec |
| CHA-04 | U3 default ordering | passkey lead; TOTP under "Other options"; TOTP still completes login | High | E2E |
| CHA-05 | Override preferred = TOTP (U3) | TOTP lead; passkey in others | Med | E2E |
| CHA-06 | Authenticator removed mid-session (U5) | passkey fails gracefully; recovery/TOTP path completes | High | E2E |
| REV-01 | Revoke without password | rejected; row intact (`mfaReauth`) | High | Sec |
| REV-02 | Revoke last passkey, no other factor | password-only login restored (`userHasActiveFactor` false) | High | E2E |
| SEC-01 | Sign-count regression (seeded high stored count) | assertion rejected + logged | Med | Sec |
| SEC-02 | Cross-user: U3's assertion against U2's pending/handle | rejected (credential↔user binding) | Critical | Sec |
| SEC-03 | Missing/invalid `csrf_token` on every `webauthn.php` action + profile passkey actions | blocked | High | Sec |
| SEC-04 | Origin/rpId mismatch handling | covered at unit/harness level + lib trust; assert error path returns generic JSON | Med | Unit |
| REGR-01 | U1 password-only login | zero change; existing `02-auth` + MFA specs all green | Critical | E2E |
| I18N-01 | da+en parity on all new strings (profile, challenge, login, errors) | no raw `t()` keys | Low | E2E |
| OPS-01 | After `sync:live`, test DB has no `user_passkeys` rows | truncation ran; no member gated on unusable factor | High | Integration |

## 6. Risk assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| New promotion path grants session without valid assertion | Med | Critical | PWL-02/03, CHA-03, SEC-02 gate release; review every `$_SESSION['user_id'] =` |
| Chromium-only CI ≠ member reality (mobile-first club, Safari/iOS) | High | High | one-time launch device pass (decided 🔴-1 B); **accepted residual risk:** later mobile regressions from code/browser/OS changes are not re-verified — revisit if member reports spike |
| rpId changed after launch → all passkeys orphaned | Low | Critical | `PASSKEY_RPID` config constant, fail-loud check in `passkeyRpId()` (decided 🔴-2 A); gotchas entry |
| Live passkey rows poison test DB via `sync:live` | High (once live has passkeys) | Med | OPS-01 + `sync.js` truncation ships in Phase 1 |
| Vendored lib drift / upstream CVE | Low | High | pinned `VERSION` file; update procedure; auth suite rerun on bump |
| Sign-count always 0 on platform authenticators → false confidence | High | Low | policy is advisory-only (plan §1.2); SEC-01 tests the enforced branch only |
| Flakiness from WebAuthn prompts | Low | Med | virtual authenticator with `automaticPresenceSimulation` — no OS dialogs, no timing waits |

## 7. Definition of Done (testing)

☐ PWL-01/02/03 + CHA-02/03 + SEC-02 green · ☐ challenge single-use proven (unit + PWL-03) ·
☐ CSRF on all new endpoints · ☐ REV-01/02 green · ☐ REGR-01 + full existing auth suite green ·
☐ OPS-01 green · ☐ da/en parity · ☐ error-parity asserts in PWL-04 (byte-identical bodies) ·
☐ one-time launch device checklist signed off on test env before the **first** live deploy of this epic ·
☐ every live deploy thereafter: full auth E2E suite green on test env with the deployed code (`plan.md` Verification).

---

## 8. Test-manager review of the refined epic + plan

> **Decision record:** every finding below was decided by Djarnis on 2026-07-05 (annotated *Decided* per item) and folded into `plan.md` and §§2–7 above. Final verdict at the end of this section.

**Coverage** is right-layered: crypto delegated to a pinned lib and exercised end-to-end through a real (virtual) authenticator; our glue — state machine, single-use, binding — carries the test weight, consistent with what made the MFA release safe. **Reliability** is sound: the virtual authenticator removes the classic WebAuthn flake source (native dialogs). Remaining findings:

🔴 **MUST FIX**

1. **Chromium-only E2E is a blind spot exactly where the epic's value lives.** The membership is mobile-first; iOS Safari and Android Chrome behaviour (prompt UX, iCloud/Google sync, conditional UI) is never exercised by CI. The manual real-device pass in §2/§7 must be a hard `deploy:live` gate with a written checklist (register, passwordless login, challenge login, revoke — per device), not a courtesy.
   → *Decided: option B — one-time written checklist at launch only (iPhone Safari, Android Chrome; Windows Hello optional). The reviewer's per-deploy cadence was **not** adopted; later mobile regressions are an accepted, logged residual risk (§6).*
2. **rpId is a one-way door with no technical guardrail.** Nothing stops a future config edit from changing `SITE_URL`/derived rpId and silently orphaning every passkey. Condition: the gotchas entry plus a deploy-time assertion (e.g. `schema:check`-style guard or a constant pinning `passkeyRpId()` expected value per env) before first live registration.
   → *Decided: registrable domain as rpId + option A — `PASSKEY_RPID` constant per env config, fail-loud validation in `passkeyRpId()` (plan Step 1.2).*
3. **Atomicity must be verified, not just planned.** Because `passkeyActive()` already gates login in production, CHA-01→CHA-02 (register, then complete a password login via the passkey challenge) must pass on the test env **in the same run** as REG-01 before any live deploy; a registration-only partial deploy locks members out. Add it to the deploy smoke ordering.
   → *Decided: option A — full auth E2E suite green on the test env with the deployed code is the gate for every live deploy; live smoke never registers passkeys (plan Verification).*

🟡 **SHOULD FIX**

1. **Recovery-code flash over a fetch flow (REG-02) needs explicit UX verification** — the JSON `register_verify` sets `flash_recovery_codes`, but the member only sees codes if the page reloads into the profile flash block. Assert the codes actually render, not just that the session flag is set.
   → *Decided: option A — `passkey.js` reloads the profile page on success; REG-02 asserts the codes are visible on screen.*
2. **Enumeration parity:** `login_verify` failures for "unknown credential" vs "rate-limited" vs "bad signature" must be byte-identical JSON (PWL-04/05 should assert response equality, mirroring SEC-03 in the MFA plan).
   → *Decided: full parity — byte-identical generic bodies, diagnostics only via `logToFile()` (debugging deliberately requires the server log); `Retry-After` header kept as the one divergence, consistent with `mfa_challenge.php`.*
3. **Suite speed:** every passkey spec enrolls through the UI. Keep both specs out of smoke and parallel-safe (per-spec users via `authUser()` seeds), or the fast feedback loop degrades.
   → *Decided: option A — passkey specs excluded from smoke; coverage guaranteed by the 🔴-3 every-deploy full-suite gate instead.*
4. **`sync:live` truncation is load-bearing ops, not test infra** — if it silently fails, testers hit unexplainable lockouts weeks later. OPS-01 should assert row count = 0 *and* the sync script should fail loudly if the truncate errors.
   → *Decided: hard fail — sync exits non-zero on truncate error (missing-table case tolerated); OPS-01 asserts zero rows.*

🟢 **NICE TO HAVE**

1. A vector check of the vendored lib against a couple of published WebAuthn test vectors on version bumps.
   → *Decided: implement — lives in `tests/unit/passkey-harness.php` (plan Phase 3).*
2. Success-metric instrumentation (login-method logging) so the epic's metrics are measurable at launch rather than retrofitted.
   → *Decided: implement — `logToFile()` per successful login with method (plan Phase 3).*
3. AAGUID-based default naming ("iCloud Keychain", "Google Password Manager") once real-device data confirms AAGUIDs survive attestation `none`.
   → *Decided: implement — map seeded from what the launch device pass reports; generic fallback kept if AAGUIDs come back zeroed (plan Phase 3).*

**Original verdict (2026-07-05, pre-decision): ⚠️ APPROVE WITH CONDITIONS** — implement after the three 🔴 items are folded in.

**Final verdict (2026-07-05, post-decision): ✅ APPROVE** — all findings resolved by explicit decision and folded into `plan.md` and this document. One deviation from the reviewer's recommendation is on record: the real-device pass is a one-time launch gate, not per-deploy (🔴-1 → option B); the resulting residual risk is documented in §6 and accepted by the product owner. Implement as written.
