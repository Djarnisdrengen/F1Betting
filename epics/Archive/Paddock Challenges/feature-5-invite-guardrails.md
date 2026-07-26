# Feature 5: Invite Guardrails & Consent

Epic: `paddock-challenges-epic.md` · Consolidated spec: `feature.md` (§B2 / REQ-119) · Plan: `plan.md` · Test plan: `test-plan.md`

> **Why this is a must-have, not polish.** Feature 1's "Challenge a friend" (feature.md §B2/D12) emails
> a **third party who gave no prior consent** — the friend. Under EU GDPR and the Danish Marketing
> Practices Act (markedsføringsloven §10), unsolicited email to a private person is tightly limited;
> a single *transactional, human-triggered, clearly-explained, easy-to-stop* invite is defensible, a
> spammy or repeatable one is not. Section B explicitly defers all sender caps, rate limits,
> deduplication, and the opt-out/suppression path to **Feature 5** (REQ-119), and requires that **no
> invite email is sent without passing these checks** and that **a suppressed address is never
> re-emailed**. Phase 1 ships `createChallengeInvite()` with these guardrails **stubbed with a TODO →
> Feature 5** (plan.md Phase 1.4); this feature fills that gap.

---

## Requirements

### Functional Requirements

- [REQ-801] A single guardrail gate — `canSendInvite($db, $senderParticipantId, $senderIp, $friendEmail)` — is called **before** any friend-invite email is sent; if it returns false the invite email is **not** sent (and, where the invite row would be pointless, not created). No send path bypasses it.
- [REQ-802] **Suppression is absolute:** if `$friendEmail` is on the suppression list, no invite email is ever sent to it — not now, not on a later invite from anyone. Suppression is checked on every send (REQ-801).
- [REQ-803] **Opt-out link in every friend email:** each friend-invite email carries a one-click **opt-out** link and a plain "**why you received this**" line naming the owner who challenged them (e.g. "[Owner] challenged you on Paddock Picks. Not interested? Opt out — you won't be emailed again."). Bilingual.
- [REQ-804] The opt-out link resolves at a **public, no-login** endpoint `public/challenges-optout.php` that adds `$friendEmail` to the suppression list (reason `opt_out`) and confirms in-page. The link is **verifiable without a DB lookup at send time**: it carries `hash_hmac('sha256', $email, <server secret>)` so any past or future link for that address is honoured even after the originating invite expires.
- [REQ-805] **Per-friend deduplication:** if an **unaccepted** invite to `$friendEmail` already exists (status `sent`, not expired), a second invite to the same address is **not** emailed again within the dedupe window (default 24h) — one pending ask per friend at a time.
- [REQ-806] **Per-sender cap:** a participant may send at most `challenge_invite_daily_cap` friend invites per rolling 24h (default **5**, a `settings` value). Over the cap → no further sends until the window rolls.
- [REQ-807] **Per-IP and per-friend-email rate limiting** reuse the existing `login_attempts` mechanism with scope **`'invite'`** (`isRateLimited()` / `recordLoginAttempt()`, `public/includes/functions.php:491/513`): the IP bucket caps burst sending from one origin; the account bucket (keyed on the friend email) caps how often one address can be targeted. Fail-closed.
- [REQ-808] **One-time transactional send:** exactly **one** email per accepted invite send — no reminders, drips, or follow-ups in v1. (A "your friend beat your score" owner notification is the head-to-head result of REQ-117, not a new solicitation, and goes to the owner who already opted in.)
- [REQ-809] **Owner emails are separate and lighter-touch:** the owner-confirmation email (REQ-114.1) goes to the person who *initiated* the action about their *own* address — it is not third-party solicitation. It is still rate-limited (scope `'magic'`, NFR-107) and still offers an opt-out for future notifications, but it is **not** gated by the friend-suppression/cap rules.
- [REQ-810] **Enumeration safety preserved:** when a send is blocked (suppressed, capped, deduped, rate-limited), the HTTP response to the owner stays **byte-identical** to a successful send (NFR-106) — the owner can never learn from the response whether the friend is suppressed or already invited.
- [REQ-811] **Retention/minimisation:** `friend_email` lives only on the `challenge_invites` row and is purged when that row expires or is cleaned up (existing invite lifecycle, 14-day `expires_at`); the suppression list stores only the email + reason + timestamp needed to honour the opt-out. No friend address is retained for any purpose other than delivering the one invite and honouring suppression.
- [REQ-812] **Admin visibility (optional, lightweight):** the Challenges admin page (`public/admin-challenges.php`) may show a small suppression/opt-out count and allow the admin to add an address to suppression manually (reason `admin`) — useful for handling a complaint. Read + manual-add only; no bulk export.

### Non-Functional Requirements

- [NFR-801] simply.com shared hosting (PHP 8 + PDO/MySQL, no Node); no build step.
- [NFR-802] Rate-limit responses fail-closed with `Retry-After: 900` and **never** HTTP 429 (OpenResty strips it — precedent `public/login.php:42-46`, NFR-107). Scope `'invite'` for friend sends; scope `'magic'` for owner-confirm.
- [NFR-803] The opt-out endpoint is CSRF-exempt for the GET link but performs the suppression write **idempotently** (repeat clicks are safe; `INSERT ... ON DUPLICATE KEY UPDATE` on a UNIQUE email); it takes no action other than suppressing the single HMAC-verified address and must not leak whether the address had a pending invite.
- [NFR-804] The HMAC opt-out token uses a server-side secret already available in config (never shipped to the client beyond the derived token); an invalid/tampered token renders a neutral "link not valid" page and writes nothing.
- [NFR-805] No friend address is ever exposed on the CP board, hub, or any public surface; suppression checks never reveal list membership to an unauthenticated caller.
- [NFR-806] Compliance posture is documented: lawful basis = the owner's affirmative action initiating a one-time transactional invite to someone they assert they know; transparency (who + why) + frictionless opt-out + guaranteed no-reuse are the controls. Danish/EU alignment is a **release gate**, not a nice-to-have (REQ-119).

### Technical Constraints

- Must work on simply.com shared hosting (PHP 8, MySQL, Apache + mod_rewrite, no Node).
- No build step — direct deployment of source files.
- Reuse the existing `login_attempts` rate-limit table/helpers (scope column is free-form text — `'invite'` needs no migration).
- Reuse `sendEmail()`/`getEmailTemplate()` and `t()` for all copy; the opt-out line and link are part of the standard template footer for friend emails.
- One small additive table (`challenge_email_suppressions`) and one `settings` key (`challenge_invite_daily_cap`); everything else already exists.

---

## User Story

### Primary User Goal

The invite loop should grow the group **without** turning anyone into a spammer or putting the app on
the wrong side of Danish/EU email law — a friend who gets one invite can stop it in one click and
never hear from it again.

### User Story Format

**As a** person who receives a "beat my score" invite
**I want to** clearly see who challenged me and opt out in one click
**So that** I'm never repeatedly emailed by an app I didn't sign up for.

**And as the** app owner (Djarnis)
**I want** every friend invite gated by caps, rate limits, dedupe and a suppression list
**So that** the growth loop stays a *friendly* invite and stays legally defensible.

### User Personas

- **The friend (third party):** got one invite; either plays or opts out in a single click.
- **Casual sender:** challenges two or three friends after a good deck — well under the cap, no friction.
- **Abusive/accidental sender:** tries to blast many addresses — stopped by the per-sender cap and IP rate limit before it becomes spam.
- **Admin (Djarnis):** occasionally suppresses an address after a complaint; trusts that the loop is compliant by construction.

---

## Functionality

### User Flow

1. An owner finishes a deck and submits "Challenge a friend" with their own + a friend's email (Feature 1 §B2).
2. Before sending the friend email, `challenges-invite.php` calls `canSendInvite()` — checking, in order: **suppression** → **per-friend dedupe** → **per-sender cap** → **IP/email rate limit**.
3. **Pass:** the friend invite is sent — one transactional email, naming the owner, with a "why you received this" line and a one-click opt-out link. `recordLoginAttempt(..., 'invite', friendEmail)` logs the send.
4. **Fail (any check):** no friend email is sent; the owner's HTTP response is byte-identical to the pass case (REQ-810).
5. The **friend** either plays (Feature 1 §B2 continues) or clicks **Opt out** → `challenges-optout.php` verifies the HMAC, adds the address to suppression, shows "You won't be emailed again."
6. Any future invite to that address — from anyone — is silently dropped at step 2's suppression check.

### Detailed Specifications

- **`canSendInvite($db, $senderId, $ip, $friendEmail)`** (new, in `public/includes/challenges.php`) returns bool; ordered checks, all fail-closed:
  1. **Suppressed?** `SELECT 1 FROM challenge_email_suppressions WHERE email = ?` → false if present (REQ-802).
  2. **Already core?** `SELECT 1 FROM users WHERE email = ?` → false (REQ-111 already blocks participant creation; the friend gets the "you already have an account" email path instead, handled by the invite endpoint, not a solicitation).
  3. **Pending dupe?** an unexpired `challenge_invites` row to this `friend_email` with status `sent` inside the dedupe window → false (REQ-805).
  4. **Sender cap?** count this sender's invites in the last 24h ≥ `challenge_invite_daily_cap` → false (REQ-806).
  5. **Rate limited?** `isRateLimited($db, $ip, 'invite', $friendEmail)` → false (REQ-807).
  Otherwise true.
- **Send path** (`challenges-invite.php`, extends Phase 1 plumbing): if `canSendInvite()` is true → `createChallengeInvite()` (existing) + build the friend email via `getEmailTemplate()` with the opt-out link + why-line + `recordLoginAttempt($db, $ip, 'invite', $friendEmail)`. If false → skip the send (and skip creating a dead invite row where appropriate) but return the **same** response (REQ-810).
- **Opt-out token:** `token = hash_hmac('sha256', strtolower(trim($email)), $serverSecret)`; link = `challenges-optout.php?e=<urlencoded email>&t=<token>`. The endpoint recomputes and compares with `hash_equals()`; on match → `INSERT INTO challenge_email_suppressions (email, reason, created_at) VALUES (?, 'opt_out', NOW()) ON DUPLICATE KEY UPDATE created_at = created_at`; render a neutral confirmation. On mismatch → neutral "link not valid" page, no write (NFR-804). (Emailing the address in the link is acceptable because the recipient already controls that inbox; the HMAC prevents suppressing *other* people's addresses.)
- **Owner-confirmation email** (REQ-809): sent to the initiating owner about their own address; gated only by scope `'magic'` rate limiting (NFR-107), carries its own future-notifications opt-out, and is **not** subject to the friend cap/suppression gate — but it **does** honour suppression if the owner had previously opted their own address out.
- **Settings:** `challenge_invite_daily_cap INT DEFAULT 5` added to `settings` (same style as `challenge_rumor_deck_size`, plan.md Phase 0.2).
- **Error handling & edge cases:**
  - Malformed friend email → rejected before any gate (existing input validation), byte-identical response.
  - Friend already a participant (verified) but not suppressed → invite still allowed (they may not have opted out); dedupe still applies.
  - Suppression added between `canSendInvite()` and the actual `sendEmail()` (race) → acceptable; worst case one in-flight email; the next send is blocked. (No transactional lock needed for a low-volume friends app.)
  - Owner opts their own address out, then later tries to send an invite → their *friend* sends are unaffected; only emails **to the owner's address** are suppressed.
  - Tampered opt-out token or truncated email param → neutral invalid page, nothing written.

### Scoring Logic

Not applicable — this feature governs email sending and consent only. It never awards, reads, or
alters Challenge Points; note that the head-to-head invite itself awards **no** bonus CP (REQ-117),
so there is no scoring surface here at all.

### Mobile Considerations

- The friend-invite email renders on mobile mail clients; the opt-out link is a full-width, ≥ 44px tap target in the email template footer.
- The opt-out confirmation page (`challenges-optout.php`) is a minimal, arena-light, mobile-first page — single confirmation line, no login, no form to fumble.
- The "Challenge a friend" form (Feature 1) keeps 44px targets / 16px inputs; the guardrails are server-side and add no UI friction on the happy path.

### Technical Implementation

- **New:** `public/challenges-optout.php` (public, HMAC-verified suppression write, idempotent); `canSendInvite()` in `public/includes/challenges.php`; `challenge_email_suppressions` table (in `database/add_challenges.sql`, mirrored in `schema.sql`, registered in `migrations.json`); `challenge_invite_daily_cap` setting.
- **Modified:** `public/challenges-invite.php` — replace the Phase 1 guardrail TODO with the `canSendInvite()` gate + `recordLoginAttempt(..., 'invite', ...)`; friend email template gains the why-line + opt-out link. `public/admin-challenges.php` — optional suppression count + manual-add (REQ-812).
- **Reused:** `isRateLimited()`/`recordLoginAttempt()` (scope `'invite'`), `sendEmail()`/`getEmailTemplate()`, `createChallengeInvite()` (existing), `t()`, `escape()`, config server secret for the HMAC.
- **Data model addition:**

```sql
challenge_email_suppressions           -- opt-out / complaint / bounce suppression (REQ-802/804)
  id INT AUTO_INCREMENT PK
  email VARCHAR(255) NOT NULL UNIQUE    -- normalised lower-case; checked before every friend send
  reason ENUM('opt_out','complaint','bounce','admin') NOT NULL DEFAULT 'opt_out'
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
```

- **Settings addition:** `challenge_invite_daily_cap INT NOT NULL DEFAULT 5`.
- **Translations:** friend-email why-line + opt-out label + opt-out confirmation/invalid pages → `public/lang/email.php` and `public/lang/user.php` (da+en); any admin suppression strings → `public/lang/admin.php`.

---

## Test Scenarios

```gherkin
Feature: Invite guardrails & consent

  Scenario: Every friend invite is transparent and stoppable
    Given an owner sends a valid friend invite
    Then the friend email names the owner and says why it was received
    And it carries a one-click opt-out link

  Scenario: Opt-out suppresses the address forever
    Given a friend clicks the opt-out link
    When any owner later invites that same address
    Then no invite email is sent to it

  Scenario: Sender cap stops mass sending
    Given a participant has sent the daily cap of friend invites
    When they try to send one more
    Then no further friend email is sent until the window rolls

  Scenario: Blocked sends are indistinguishable to the sender
    Given one invite to a suppressed address and one to a fresh address
    Then both HTTP responses to the owner are byte-identical

  Scenario: One transactional email, no reminders
    Given a friend invite was sent
    Then exactly one email is sent for it and no follow-up reminder

  Scenario: Rate limiting fails closed without a 429
    Given the invite IP or email bucket is exhausted
    When another send is attempted
    Then it is blocked with Retry-After 900 and never an HTTP 429
```

## Test Cases

```gherkin
Feature: Invite guardrails & consent

  Scenario: Suppressed address is never emailed
    Given "friend@example.com" is on challenge_email_suppressions
    When an owner submits a challenge invite to "friend@example.com"
    Then canSendInvite returns false
    And no friend email is sent
    And the owner's response is identical to a successful send

  Scenario: Opt-out link suppresses via valid HMAC
    Given a friend invite email to "friend@example.com" with token = hmac_sha256(email, secret)
    When the friend opens challenges-optout.php with that email and token
    Then "friend@example.com" is inserted into challenge_email_suppressions with reason opt_out
    And a neutral confirmation page is shown

  Scenario: Opt-out is idempotent
    Given "friend@example.com" is already suppressed
    When the opt-out link is clicked again
    Then no duplicate row is created and the same confirmation is shown

  Scenario: Tampered opt-out token writes nothing
    Given an opt-out URL whose token does not match hmac_sha256(email, secret)
    When it is opened
    Then no suppression row is written
    And a neutral "link not valid" page is shown

  Scenario: Opt-out cannot suppress a stranger's address
    Given an attacker crafts challenges-optout.php?e=victim@example.com with a guessed token
    When the token fails hash_equals against the server HMAC
    Then victim@example.com is not suppressed

  Scenario: Per-friend dedupe blocks a second pending invite
    Given an unexpired sent invite to "friend@example.com" exists
    When another invite to "friend@example.com" is submitted within 24h
    Then canSendInvite returns false and no second email is sent

  Scenario: Per-sender daily cap
    Given challenge_invite_daily_cap is 5
    And a participant has sent 5 friend invites in the last 24h
    When they submit a 6th
    Then canSendInvite returns false and no 6th email is sent
    And after 24h have passed a new invite is allowed again

  Scenario: IP rate limit fails closed
    Given the 'invite' scope IP bucket for this origin is exhausted
    When a send is attempted
    Then isRateLimited returns true, no email is sent
    And the response carries Retry-After 900 and is not HTTP 429

  Scenario: Friend-email rate limit fails closed
    Given the 'invite' scope account bucket for "friend@example.com" is exhausted
    When a send to it is attempted
    Then it is blocked even from a fresh IP

  Scenario: A successful send records an attempt and one email
    Given canSendInvite returns true for a fresh address under the cap
    When the invite is sent
    Then exactly one friend email is sent
    And recordLoginAttempt is called with scope 'invite' and the friend email
    And the email contains the owner's name, a why-line, and an opt-out link

  Scenario: Blocked and allowed sends are byte-identical to the owner
    Given two submissions, one that passes canSendInvite and one that fails on suppression
    Then the two HTTP responses returned to the owner are byte-for-byte identical

  Scenario: Owner-confirmation email is not gated by friend rules
    Given an owner submits a challenge with their own address not suppressed
    Then the owner-confirmation email is sent even if the friend send is blocked
    But it is still limited by the 'magic' scope rate limit

  Scenario: A core member's address short-circuits to the account path
    Given "member@example.com" already exists in users
    When it is used as a friend email
    Then no invite/participant is created for it
    And the "you already have an account, log in and open Challenges" email path is used
    And the owner's response is unchanged

  Scenario: Friend address is minimised
    Given a friend invite that expires after 14 days
    When the invite row is cleaned up
    Then friend_email no longer persists anywhere except a suppression row if they opted out
```
