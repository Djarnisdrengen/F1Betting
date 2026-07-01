# Test Plan: Multi-Factor & Passkey Authentication

Feature: `feature.md` · Plan: `plan.md`
Reviewed by the test-strategy-manager skill. Stack: **Playwright (Node.js) E2E + `node --test`** (no PHPUnit).

---

## 1. Scope & objectives

- **Testing:** the two-step authentication state machine and the three Phase-1 factors (TOTP, recovery codes, email OTP).
- **Why it matters:** any path that sets `$_SESSION['user_id']` before the second factor completes is a full auth bypass. The crypto is the easy part — the **session state machine is the thing to test hardest**.
- **Success criteria:** bypass guards green, single-use guarantees proven under concurrency, password-only regression unchanged.
- **Out of scope (Phase 1):** passkeys/WebAuthn (Phase 2 plan), SMS, social login.

## 2. Test types

| Type | Tool | Scope |
|---|---|---|
| Unit | `node --test` + small PHP CLI harness | RFC 6238 verify (±1 step, skew, replay-in-step), recovery gen/hash/consume, base32 codec, secretbox seal/open |
| Integration | Playwright → test DB | state machine, recovery/email single-use transaction, enroll→confirm lifecycle |
| E2E | Playwright (`DEPLOY_ENV=test`) | enroll, login w/ each factor, **negative bypass paths** |
| Security | `npm run test:security` + manual | bypass, replay, rate-limit, CSRF on new endpoints, enumeration parity, secret-never-rendered |

New specs: `tests/e2e/auth/30-totp-mfa.spec.js`, `33-mfa-bypass-negative.spec.js`, `34-email-otp.spec.js`.

## 3. Test data (seed in test DB only)

| User | State | Purpose |
|---|---|---|
| U1 | password-only | regression: login path unchanged |
| U2 | confirmed TOTP + fresh recovery codes | happy path + bypass guards |
| U3 | confirmed TOTP, all recovery used | recovery exhaustion |
| U4 | pending TOTP (`confirmed_at IS NULL`) | must behave as password-only |
| U5 | email OTP enabled | email factor |
| U6 | TOTP + email OTP | multi-method challenge |

- **TOTP determinism:** unit harness injects fixed secret + frozen timestamp → reproducible codes; E2E derives the current code from the enrolled secret.
- **Email codes:** read via existing **Mailsac** integration (real-SMTP flag-file mode).
- **Cleanup:** truncate `user_totp`, `user_recovery_codes`, `user_email_otp` and clear `mfa_pending` in `afterEach`.

## 4. Acceptance criteria

See `feature.md` Gherkin. Critical scenarios: password-only unchanged · pending holds at challenge ·
**direct access while pending denied** · correct factor promotes + regenerates session · recovery
single-use · disable requires re-auth · email OTP rate-limited.

## 5. Test cases

| ID | Case | Expected | Pri | Type |
|---|---|---|---|---|
| MFA-01 | Pending session grants no access (request protected page) | redirect to login, no protected HTML | Critical | Sec/E2E |
| MFA-02 | Valid TOTP promotes + regenerates session | `user_id` set, new session id, pending cleared | Critical | Integration |
| MFA-03 | Re-POST `/login.php` mid-flow | still pending, no `user_id` | Critical | Sec |
| MFA-04 | Pending expiry (past TTL) | rejected, restart from login | High | Integration |
| MFA-05 | Pending TOTP user (U4) logs in as password-only | no challenge | High | Integration |
| TOTP-01 | Enroll → confirm activates | `confirmed_at` set; recovery codes shown once | High | E2E |
| TOTP-02 | Replayed code within same step | rejected | Med | Unit |
| TOTP-03 | ±1 step skew accepted, ±2 rejected | per RFC 6238 | High | Unit |
| TOTP-04 | Disable requires re-auth | rejected without password; row intact | High | Sec |
| TOTP-05 | Secret never rendered post-enrollment | base32/QR absent from response | High | Sec |
| REC-01 | Recovery single-use (serial) | first ok, reuse rejected | High | Integration |
| REC-02 | Recovery single-use (parallel) | exactly one accepted | High | Integration |
| REC-03 | Regenerate invalidates old set | old code rejected | Med | Integration |
| EOTP-01 | Enable → confirm; code arrives at Mailsac | active only after correct confirm | High | E2E |
| EOTP-02 | Login held; emailed code promotes session | `user_id` only post-code | Critical | E2E |
| EOTP-03 | Expired code rejected (past TTL) | must resend | High | Integration |
| EOTP-04 | Send + verify rate-limited | no mail-bomb / cost spike | High | Sec |
| EOTP-05 | Attempts lock after N | locked, generic error | High | Integration |
| SEC-01 | CSRF on every new POST | `requireCsrf()` blocks | High | Sec |
| SEC-02 | Challenge brute-force throttled | blocked, `Retry-After` | High | Sec |
| SEC-03 | No MFA-status enumeration | responses indistinguishable | Med | Sec |
| I18N-01 | da+en parity (screens + OTP email) | no raw `t()` keys | Low | E2E |

## 6. Risk assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| `user_id` set before 2nd factor | Med | Critical | MFA-01/03 gate release; review every `$_SESSION['user_id'] =` |
| Recovery/email double-spend race | Med | High | atomic conditional UPDATE + `rowCount()`; REC-02 / EOTP-05 |
| Unthrottled code guessing | Low | High | SEC-02 + per-row attempts |
| Email volume / cost spike | Low | Med | EOTP-04 rate limit on send |
| Password-only regression | Low | High | MFA-05 + smoke login (U1) |

## 7. Definition of Done (testing)

☐ MFA-01/02/03 green · ☐ recovery single-use proven under concurrency · ☐ email OTP TTL + attempts +
rate-limit green · ☐ CSRF on all new endpoints · ☐ da/en parity · ☐ U1 password-only unchanged ·
☐ no `users` schema regression beyond additive flag.
