# Epic: Passkey (WebAuthn) Authentication

> **Status:** Refined and approved — test-manager verdict ✅ APPROVE, all review decisions signed off 2026-07-05 (`test-plan.md` §8)
> **Predecessor:** TOTP + email OTP + recovery codes — **shipped** (`public/includes/mfa.php`, `public/mfa_challenge.php`, Security tab in `public/profile.php`)
> **Companion docs:** implementation `plan.md` · test strategy `test-plan.md`

## User Value
Members of Frederikssund Formel 1 Klub currently authenticate with password and, since the MFA release, optionally an authenticator app (TOTP) or an emailed code as a second factor. Passkeys remove the friction of typing passwords and one-time codes altogether: members sign in with Face ID, Touch ID, Windows Hello, or a synced password manager, using the same biometric or device unlock they already use dozens of times a day on other sites.

This epic covers **platform passkeys only** — passkeys created via the device's built-in credential manager (Face ID/Touch ID, Windows Hello) or a password manager (iCloud Keychain, Google Password Manager, 1Password, etc.). Dedicated hardware security keys (e.g. YubiKey) are not a supported product path for this release (see the enforcement note under Constraints).

This matters especially for a volunteer-run club platform where:
- Many members are not technical and struggle with password managers or copying six-digit codes under time pressure
- Login friction directly discourages casual engagement with club features
- A stolen or reused password is the single biggest account-takeover risk on a small shared-hosting platform with no dedicated security team

Passkeys are phishing-resistant by design (the credential is bound to the site's domain), so they also reduce credential-stuffing and phishing exposure without any ongoing security-operations effort from Djarnis.

## Scope decision (signed off, 2026-07-05)
Passkeys serve **two roles** in this release ("full scope"):

1. **Passwordless sign-in (primary):** a "Sign in with a passkey" option on the login page logs the member in with a single biometric/device-unlock prompt — no password, no separate second-factor step. A passkey used with user verification is inherently two factors (the device + the biometric/PIN).
2. **Second factor (challenge method):** members who log in with their password and have a passkey enrolled can satisfy the existing two-step challenge with that passkey, alongside TOTP, email code, and recovery codes.

Password + existing MFA flows remain fully available; nothing about passkeys is mandatory.

## User Experience
- **Security tab (profile):** a member can register one or more passkeys, see each with a friendly name (renameable), creation date, and last-used date, and revoke any of them. Revoking requires re-entering the password (same `mfaReauth` guard as disabling TOTP). If a passkey is the member's **first** enrolled factor, recovery codes are generated and shown once — exactly as when TOTP or email OTP is first enabled.
- **Login page:** a "Sign in with a passkey" button next to the password form. One tap → biometric/device-unlock prompt → signed in. On supporting browsers the passkey may also be offered through the username field's autofill (conditional UI).
- **Challenge step (password logins):** members with multiple methods enrolled get a sensible default at the second-factor prompt, with no configuration required:
  1. **Passkey** first, if enrolled
  2. **Authenticator app (TOTP)** next
  3. **Email code** last
  The remaining methods and the recovery-code option stay available under "Other options", so a member on a device without their passkey is never stuck.
- **Preferred-method override:** the existing Security-tab selector gains a "Passkey" option; a member who prefers TOTP day-to-day keeps passkey enrolled but leads with TOTP at the challenge.
- **Losing a device doesn't lock a member out:** recovery codes and any other enrolled factor keep working; the lost passkey can be revoked from the Security tab and a new one registered.
- **Everything is opt-in.** No member is forced onto passkeys; password-only and password+TOTP/email flows continue unchanged.

## Constraints (from architecture review — bind the implementation)
- **Domain binding (rpId):** passkeys are cryptographically bound to the site's registrable domain (`hpovlsen.dk` on test, `formula-1.dk` on live). Credentials are **not portable between environments**, and **changing the rpId after launch orphans every registered passkey** — the rpId choice is a one-way door and must be fixed before the first live registration.
- **`sync:live` hygiene:** copying the live DB to test brings `user_passkeys` rows that can never be satisfied on the test domain — and would gate those members' test logins behind an unusable factor. The sync script must clear `user_passkeys` on the test copy (see `plan.md`).
- **Atomic release:** `passkeyActive()` is already wired into `userHasActiveFactor()` in the shipped code, so the **first** `user_passkeys` row immediately gates that member's password login. Registration, the challenge-step passkey block, and assertion verification must ship in the same release — there is no safe partial rollout.
- **JavaScript required (for passkeys only):** WebAuthn is a browser JS API. Members without JS keep the full password + TOTP/email/recovery experience; passkey options simply don't render.
- **Hardware-key exclusion is best-effort:** registration requests platform authenticators (`authenticatorAttachment: "platform"`), which steers browsers away from external security keys. This is a client-side hint — the server cannot reliably distinguish or reject a security key (attestation is not collected). A key that slips through simply works as a passkey; it is unsupported, not blocked.

## Success Metrics
- Number/percentage of active members with at least one passkey registered within 3 months of release
- Reduction in "lost my code" / "can't log in" support requests vs. the TOTP-era baseline
- Passkey share of logins (measurable from `user_passkeys.last_used_at` and login-method logging — see `plan.md` instrumentation note)
- Percentage of multi-method members who keep the smart default vs. override their preferred method
- No increase in account lockouts or recovery-code redemptions following rollout

## Acceptance Criteria

```gherkin
Feature: Passkey authentication

  Scenario: Member registers a passkey
    Given a logged-in member is on the Security tab of their profile
    When they choose to add a passkey and complete the browser's WebAuthn prompt
    Then the passkey is saved against their account with a friendly name they can edit
    And it appears in their list of registered authentication methods
    And if it is their first enrolled factor, recovery codes are generated and shown once

  Scenario: Member signs in without a password
    Given a member has a passkey available on this device
    When they choose "Sign in with a passkey" on the login page and pass the biometric/device-unlock prompt
    Then they are logged in without entering a password or a second-factor code
    And a fresh session is issued (session id regenerated)

  Scenario: Passkey satisfies the second-factor challenge after a password login
    Given a member with a passkey enrolled logs in with their password
    When the second-factor challenge appears
    Then passkey is offered as the lead method
    And a successful passkey confirmation completes the login

  Scenario: Default method ordering with multiple methods enrolled
    Given a member has enrolled a passkey and an authenticator app and has not chosen a preferred method
    When they reach the second-factor challenge
    Then passkey is offered first, with authenticator app and email code under "Other options"
    And on a device where the passkey is unavailable, the member can complete login with another method

  Scenario: Member overrides the default preferred method
    Given a member has multiple MFA methods enrolled
    When they set authenticator app as their preferred method in the Security tab
    Then subsequent challenges lead with the authenticator app instead of the passkey
    And the other enrolled methods remain reachable

  Scenario: Member revokes a passkey
    Given a member has a passkey registered
    When they revoke it from the Security tab and confirm with their password
    Then the passkey is removed and can no longer be used to sign in
    And if no other factor remains enrolled, the account returns to password-only login

  Scenario: Member loses their passkey device
    Given a member's only passkey was on a device they no longer have and was not synced
    And they still have recovery codes or another enrolled factor
    When they log in with their password
    Then they can complete the challenge via recovery codes or the other factor
    And they can revoke the lost passkey and register a new one from the Security tab

  Scenario: Registration steers members toward platform passkeys
    Given a member starts the "add a passkey" flow
    When the browser presents credential options
    Then the request asks for a platform authenticator (device credential manager / password manager)
    And the UI copy describes passkeys in terms of Face ID, Touch ID, Windows Hello, and password managers
    # Hardware security keys are unsupported, not server-blocked — see Constraints.
```

---

*Context: this epic delivers the passkey phase deferred from the Advanced Authentication epic (`epics/Multi-factor and passkey authentication/`). The TOTP + email OTP + recovery release it builds on is live; the `user_passkeys` table and the `passkeyActive()` gate already exist in production. It reuses the existing Security tab, challenge page, and preferred-method selector rather than introducing new UI surfaces.*
