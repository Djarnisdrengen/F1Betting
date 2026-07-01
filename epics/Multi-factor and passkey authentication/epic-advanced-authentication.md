# Epic: Advanced Authentication Options

**Platform scope:** Frederikssund Formel 1 Klub — all members, all surfaces (Paddock Picks, content, klub pages)

---

## User Value

Every member of Frederikssund Formel 1 Klub has a single account that gives access to the full platform — predictions in Paddock Picks, klub content, and anything else behind login. That one account is currently protected only by a password.

This epic gives every member the option to strengthen their account with a second factor — without forcing anyone who doesn't want it. Members who care about security can opt in. Members who don't, log in exactly as before.

The value delivered across the platform:

- **One security upgrade protects everything**: Because the klub uses a single shared account system, enrolling in MFA once covers access to Paddock Picks, content, and any future surfaces — members don't have to configure it separately per product
- **Account ownership**: A member's prediction history, points, and season standing are tied to their account — losing control of it to a compromised password has real consequences within the group
- **Mobile-first passkeys**: Most members access the klub on their phone; passkeys (Face ID / fingerprint) are faster than typing a password and a code
- **No one gets locked out**: Recovery codes ensure that losing a phone or uninstalling an authenticator app is recoverable without admin intervention

## User Experience

### Security Tab in Profile

All authentication settings live under a dedicated **Security** tab in the member's profile. This is the single place to:

- Enable or disable TOTP (authenticator app)
- Register or remove passkeys (named by device, e.g. "iPhone 15", "MacBook")
- View and regenerate recovery codes
- Set the **preferred login method** (which factor is presented first at login)

No security changes happen anywhere else on the platform.

### Login Flow

The login screen adapts to each member's setup:

1. Member enters username and password as today
2. If no MFA is enrolled → logged in immediately (no change to current experience)
3. If MFA is enrolled → second-factor prompt appears, showing the **preferred method** by default
4. A **"Use a different method"** link lets the member switch if their preferred device is unavailable
5. A **"Use a recovery code"** option is always present as a last resort

### Enrollment (via Security Tab)

**TOTP:** Member scans a QR code with their authenticator app (Google Authenticator, Authy, etc.), confirms with a 6-digit code. Setup is complete.

**Passkey:** Member taps "Add passkey" and the browser triggers the device's native biometric prompt (Face ID, fingerprint, Windows Hello). The passkey is registered and named automatically by device. Multiple passkeys can be registered (e.g. phone + laptop).

**Recovery codes:** Generated automatically when MFA is first enabled. Displayed once for the member to save offline. Can be regenerated at any time (invalidating the previous set). Each code is single-use.

### Post-Login Nudge (optional, non-blocking)

Members who have never enrolled any second factor may see a soft nudge on the dashboard ("Secure your account — set up two-factor authentication") with a direct link to the Security tab. This is dismissible and never blocks access.

## Success Metrics

- **MFA adoption rate**: % of active members with at least one second factor enrolled within 90 days of launch
- **Passkey vs. TOTP split**: Distribution of preferred methods (signals which flow to prioritise in future iteration)
- **Recovery code redemptions**: Should remain low; a spike indicates the enrollment UX needs review
- **Login success rate**: Must not decrease after launch — new flows must not break existing member access
- **Admin lockout reports**: Target zero — recovery codes must fully cover self-service account recovery
- **Security tab engagement**: % of members who visit the Security tab at least once (awareness signal)

## Acceptance Criteria

```gherkin
Feature: Advanced Authentication Options

  Scenario: Member enrolls in TOTP via Security tab
    Given a logged-in member visits Profile > Security tab
    When they choose to enable an authenticator app
    Then they are shown a QR code and manual entry key
    And after entering a valid 6-digit confirmation code, TOTP is activated on their account

  Scenario: Member registers a passkey via Security tab
    Given a logged-in member visits Profile > Security tab
    When they choose to add a passkey
    Then the browser triggers a native biometric or device PIN prompt
    And on success the passkey is saved and shown in their registered passkeys list with a device name

  Scenario: Member sets preferred authentication method
    Given a member has enrolled TOTP and at least one passkey
    When they set passkey as their preferred method in the Security tab
    Then the login screen presents the passkey prompt first at their next login

  Scenario: Member without MFA logs in unaffected
    Given a member has not enrolled any second factor
    When they log in with username and password
    Then they are logged in immediately with no additional prompt

  Scenario: MFA-enrolled member is prompted after password entry
    Given a member with MFA enabled logs in with correct credentials
    When their password is accepted
    Then the system presents their preferred second-factor prompt before granting access

  Scenario: Member switches to a different method at login
    Given a member is shown their preferred second-factor prompt
    When they choose "Use a different method"
    Then they see a list of all their enrolled methods plus the recovery code option

  Scenario: Member recovers access using a recovery code
    Given a member cannot access any enrolled second factor
    When they choose the recovery code option and enter a valid unused code
    Then they are logged in
    And they are prompted to review their Security tab settings

  Scenario: Used recovery code cannot be reused
    Given a member has redeemed a recovery code to log in
    When they attempt to use the same code again
    Then the system rejects it and displays an error

  Scenario: Member removes a second factor from Security tab
    Given a member has TOTP enabled and at least one passkey registered
    When they remove TOTP from their Security tab
    Then TOTP is deactivated and no longer presented at login
    And if no other factor remains enrolled, the account returns to password-only login
```

---

## Proposed Features

| # | Feature | Priority | Complexity |
|---|---------|----------|------------|
| 1 | **Security Tab (Profile)** — Unified UI to view and manage all enrolled factors and preferences | Must-have | Medium |
| 2 | **TOTP Enrollment & Login** — QR code setup, 6-digit code verification at login, removal | Must-have | Medium |
| 3 | **Recovery Codes** — Auto-generated on first MFA enrol, single-use redemption, regeneration | Must-have | Low |
| 4 | **Preferred Method & Method Switcher** — Set preferred factor, "Use a different method" at login | Must-have | Low |
| 5 | **Passkey Enrollment & Login** — WebAuthn registration, biometric login prompt, multi-device management | Nice-to-have | High |
| 6 | **Security Nudge** — Soft post-login prompt for members with no MFA enrolled | Nice-to-have | Low |

> **Note on feature ordering:** Features 1–4 form a complete, shippable TOTP + recovery code release. Feature 5 (Passkey) is the most technically complex — WebAuthn on PHP/simply.com shared hosting — and can follow as a separate release once TOTP is stable.
