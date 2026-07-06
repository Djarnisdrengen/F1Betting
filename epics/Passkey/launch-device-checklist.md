# Passkey Launch — Real-Device Checklist (one-time gate, 🔴-1 B)

Run once on the **test env** (`https://www.hpovlsen.dk`) before the first `deploy:live`
of the passkey release. Sign and date each device row. Windows Hello is optional.

**Account to use:** a normal member account on the test DB — **never `f1_admin`**
(enrolling any factor on it breaks every smoke/E2E run, see docs/gotchas.md #16).
Create one via an admin invite, or use your own member account (after `sync:live`
all passwords are `SYNC_TEST_PASSWORD`). Note: E2E runs and `sync:live` may wipe
the account/passkey rows — if it vanishes mid-test, recreate it and also delete
the orphaned passkey from the phone's password manager.

## Steps (repeat per device)

1. **Prep** — iPhone: iCloud Keychain on, Face ID/Touch ID enabled. Android:
   Google Password Manager, screen lock set.
2. **Register** — log in with email+password → Profile → Security tab →
   "Tilføj passkey" / "Add passkey" → complete the biometric prompt.
   ✔ Row appears with a sensible label · ✔ status "Aktiv"/"Active" ·
   ✔ if first factor: recovery codes shown once (dismiss; reload does not re-show).
3. **Sync check (iPhone)** — Settings → Passwords: the hpovlsen.dk passkey exists
   in iCloud Keychain. (Android: Google Password Manager equivalent.)
4. **Challenge login** — log out → log in with email+password → challenge page
   leads with the passkey block → tap "Log ind med passkey" → biometric → landed
   on index, profile page reachable.
5. **Rename** — Security tab: rename the passkey; new name sticks.
6. **Revoke** — Security tab: revoke with a wrong password first (must fail, row
   survives) → revoke with the correct password (row gone) → log out → log in
   with password only → **no challenge** (password-only restored).
7. **Note the label/AAGUID quality** — was the default name useful? (Feeds the
   Phase 3 AAGUID-naming decision.)

## Results

| Device | OS/Browser version | Register | Sync | Challenge login | Rename | Revoke | Notes | Date / initials |
|---|---|---|---|---|---|---|---|---|
| iPhone (Safari) | *(fill in)* | ☑ | ☑ | ☑ | ☑ | ☑ | | 2026-07-06 / Djarnis |
| Android (Chrome) | *(fill in)* | ☑ | ☑ | ☑ | ☑ | ☑ | | 2026-07-06 / Djarnis |
| Windows Hello (optional) | | ☐ | n/a | ☐ | ☐ | ☐ | not run (optional) | |

**Gate: PASSED 2026-07-06** — all mandatory rows verified by Djarnis on the test
env; passkey release cleared for live. *(OS/browser versions to be filled in for
the record.)* Later mobile regressions are accepted residual risk (test-plan.md §6).
