# Feature 4: Request to Become a Core Member (Admin-Approved Promotion)

Epic: `paddock-challenges-epic.md` · Consolidated spec: `feature.md` (§B6 / §F) · Plan: `plan.md` · Test plan: `test-plan.md`

> **Decision anchor.** D14 supersedes D4's *self-serve* conversion mechanism: becoming a core member
> is now a **request an admin approves**, never self-serve (REQ-108). D4's **pool-safety default is
> retained** — an approved conversion still lands `in_competition = 0` and is admitted to the money
> pool separately (D10/REQ-505). Feature 4 specifies the whole loop: the participant's request (the
> `requestCoreMembership()` plumbing already exists), the admin **promotion queue** on
> `public/admin-challenges.php`, and the **approve/reject** actions — the only code path in the whole
> epic that writes a `users` row from a participant's initiative (and even then, an *admin* does the
> writing).

---

## Requirements

### Functional Requirements

- [REQ-701] The **only** participant-initiated step is a request: a verified or permanent participant sets `promotion_requested_at = NOW()` via `requestCoreMembership()` (entry point = Feature 3 Account tab, REQ-605.3). **No participant-initiated path writes to `users`** (NFR-103 restated).
- [REQ-702] A request is single-shot: `requestCoreMembership()` only sets the marker when it is currently NULL; while a request is pending the participant UI shows "Request pending review" and offers no re-request (idempotent).
- [REQ-703] The **Challenges admin page** `public/admin-challenges.php` (D10 — standalone, admin chrome reused, **not** a tab in `admin.php`, no `$allowedTabs` entry) shows a **Promotion requests** queue: every participant with `promotion_requested_at IS NOT NULL AND core_user_id IS NULL`, showing display name, email, **verified vs. permanent** (has `password_hash`), CP total (`getChallengeCpTotal()`), and request date, oldest first.
- [REQ-704] Each queued request has **Approve** and **Reject** actions (CSRF-guarded POSTs, `requireAdmin()`).
- [REQ-705] **Approve** runs in a single transaction and:
  1. creates a `users` row — `id = generateUUID()`, `email` = the participant's email, `display_name` = the participant's display name, `role = 'user'`, `in_competition = 0`, `points = 0`, `stars = 0`, `language` = the participant's language (shape per `public/register.php:63` / `public/tools/test-seed.php`);
  2. sets **password** — if the participant is **permanent** (`password_hash` present), copy that hash into `users.password` so they log in unchanged; if **verified-only** (no hash), create the row with an unusable password and email a **set-password link** (reuse the `password_resets` flow, `public/forgot_password.php`);
  3. links the identity: `UPDATE challenge_participants SET core_user_id = <newUserId>, promotion_requested_at = NULL WHERE id = ?`;
  4. leaves the CP ledger untouched — CP rows stay keyed to the (retained) `participant_id`, so **history is preserved automatically** and `getChallengeParticipant()` for the new core member resolves back to the same participant by `core_user_id`;
  5. sends a bilingual **welcome / account-ready** email (`sendEmail()` + `getEmailTemplate()`).
- [REQ-706] **Reject** clears `promotion_requested_at` (back to NULL) so the participant may request again later; it creates **no** `users` row and changes nothing else. An optional bilingual "not this time" email may be sent (copy-configurable; no reason is exposed to avoid friction).
- [REQ-707] After approval the participant is a **converted guest** (`email IS NOT NULL AND core_user_id IS NOT NULL`) and therefore appears in the **converted-guests list** (REQ-505) with its `in_competition` toggle, and is **excluded from the core users list** in `admin.php` (REQ-506, `public/includes/admin/users.php` filter). The admin admits them to the pool later by flipping `in_competition` there (D4 default retained).
- [REQ-708] Approval **never** calls `establishSession()` for the admin's session (the admin is not logging in as the participant). The new core member signs in themselves afterward — with their existing password (permanent) or via the set-password link (verified-only). This keeps session separation intact (Security Model).
- [REQ-709] An email that already belongs to a `users` account can never reach this queue: REQ-111 stops such an address from ever becoming a participant, so a promotion request cannot create a duplicate account. Approval still re-checks `SELECT ... FROM users WHERE email = ?` inside the transaction and **aborts safely** (no row created, request left pending, admin shown a conflict notice) if a collision somehow exists.

### Non-Functional Requirements

- [NFR-701] simply.com shared hosting (PHP 8 + PDO/MySQL, no Node); no build step.
- [NFR-702] `requireAdmin()` gates the whole page exactly like `admin.php`; every POST uses `csrfField()` / `requireCsrf()`. A challenge guest session or a non-admin core member is denied identically to `admin.php`.
- [NFR-703] The approve transaction is atomic: users-row insert, `core_user_id` link, and marker clear commit together or roll back together — a failure never leaves a half-linked identity or an orphaned `users` row.
- [NFR-704] Admin-facing strings go to `public/lang/admin.php` (da+en), not `user.php` (NFR-501); the welcome/account-ready and rejection emails go to `public/lang/email.php` (nested da/en, `sprintf` placeholders).
- [NFR-705] Approval is idempotent under a double-submit: a participant already carrying a `core_user_id` is skipped (the queue query excludes it; the handler re-checks) so a re-posted Approve never creates a second `users` row.
- [NFR-706] No betting points/pool math run at approval — `points`/`stars` start at 0 and `in_competition = 0`; the participant enters standings only after the manual pool admission (REQ-505), never automatically.

### Technical Constraints

- Must work on simply.com shared hosting (PHP 8, MySQL, Apache + mod_rewrite, no Node).
- No build step — direct deployment of source files.
- `users` row shape must match the existing columns (`id, email, password, display_name, role, in_competition, points, stars, language`) — model `public/register.php:63` and `public/tools/test-seed.php`.
- **Schema already in place:** `challenge_participants.promotion_requested_at` and `core_user_id` exist from Phase 1; no new table or column is required.
- Reuse the `password_resets` set-password flow for verified-only approvals rather than inventing a new one.

---

## User Story

### Primary User Goal

A dedicated participant wants to graduate from the side game into the real competition — but the
group's ~10-seat money pool must stay under the admin's control, so the participant *asks* and the
admin *decides*.

### User Story Format

**As a** verified or permanent Challenges participant
**I want to** request to become a full core member
**So that** — if the admin approves — I can join the real prediction competition while keeping the CP I already earned.

**And as the** admin (Djarnis)
**I want to** review promotion requests and approve or reject each one
**So that** the money pool and member list stay under my control and no one adds themselves.

### User Personas

- **Committed guest:** has played Challenges for weeks, wants in on the real thing; taps "Request to become a core member" and waits.
- **Admin (Djarnis):** reviews requests on the Challenges admin page, approves people he knows, and admits them to the pool on his own schedule.

---

## Functionality

### User Flow

1. **Participant** (Feature 3 Account tab) taps "Request to become a core member" → `promotion_requested_at` set → UI shows "Request pending review".
2. **Admin** opens `public/admin-challenges.php` → **Promotion requests** section lists the pending participant (name, email, permanent/verified, CP total, date).
3. Admin taps **Approve** → transaction creates the `users` row (`in_competition = 0`), links `core_user_id`, clears the marker, preserves CP, sends the account-ready email → the row leaves the queue and appears in the **converted-guests list** with an `in_competition` toggle.
4. The new core member signs in: **permanent** → same email + password immediately; **verified-only** → follows the emailed set-password link, then signs in.
5. On first hub visit the core member auto-resolves to their **same** participant via `core_user_id` (REQ-104) — CP total and streak unchanged.
6. Later, the admin flips `in_competition` on the converted-guests list to admit them to the pool/leaderboard (REQ-505).
7. If instead the admin taps **Reject**, the marker clears; the participant keeps playing Challenges and may request again later.

### Detailed Specifications

- **Queue query:** `SELECT id, email, display_name, language, password_hash, promotion_requested_at FROM challenge_participants WHERE promotion_requested_at IS NOT NULL AND core_user_id IS NULL ORDER BY promotion_requested_at ASC`, each joined to `getChallengeCpTotal()` for the CP figure; "Permanent" iff `password_hash IS NOT NULL`.
- **Approve handler** (`action=approve_promotion`, `participant_id`):
  1. `BEGIN`.
  2. Re-select the participant `FOR UPDATE`; abort if it is null, already `core_user_id`-linked, or `promotion_requested_at` is NULL (double-submit guard, NFR-705).
  3. `SELECT id FROM users WHERE email = ?` — if a row exists, `ROLLBACK` and surface a conflict notice, leaving the request pending (REQ-709).
  4. Insert the `users` row with the shape above; `password` = `password_hash` when permanent, else a placeholder that fails `verifyPassword()` for every input (e.g. a random unusable hash).
  5. `UPDATE challenge_participants SET core_user_id = ?, promotion_requested_at = NULL WHERE id = ?`.
  6. `COMMIT`.
  7. Post-commit: for verified-only, issue a `password_resets` token and email the **set-password** link; for permanent, email the **account-ready** confirmation. Bilingual via the participant's `language`.
- **Reject handler** (`action=reject_promotion`, `participant_id`): `UPDATE challenge_participants SET promotion_requested_at = NULL WHERE id = ? AND core_user_id IS NULL`; optional rejection email; flash confirmation. No `users` write.
- **CP preservation:** nothing is copied or migrated — the ledger's `participant_id` is stable and the participant row is retained, so linking `core_user_id` is sufficient. This is why REQ-108's "carry over CP history" needs no data move; it is a property of the schema.
- **Converted-guests integration:** approval is exactly what produces a converted guest, so the converted-guests list (REQ-505) and the users-tab exclusion (REQ-506) are the *downstream* surface of this feature. Deactivation and pool admission for converted guests live there, not in the queue.
- **Error handling & edge cases:**
  - Participant deletes their account / is purged (REQ-110) between request and review → queue query no longer returns them (participant row gone); Approve on a stale row aborts safely (step 2).
  - Email collision with an existing `users` row (should be impossible per REQ-111) → transaction rolls back, no partial state (REQ-709/NFR-703).
  - Admin double-clicks Approve → second attempt hits the `core_user_id IS NOT NULL` guard and no-ops (NFR-705).
  - Verified-only member never clicks the set-password link → they exist as a core `users` row with no usable password; they recover via the normal forgot-password flow (same as any core member) — no special handling.

### Scoring Logic

Not applicable. Approval **preserves** the existing CP ledger by leaving `participant_id` intact and
never touches betting `points`/`stars` (both start at 0) or pool math — the new member enters
standings only via the separate manual `in_competition` admission (REQ-707/NFR-706).

### Mobile Considerations

- The admin page is admin-only and typically desktop, but reuses the responsive admin chrome; the queue is a stacked list on narrow widths.
- Approve/Reject are ≥ 44px targets with a clear confirm affordance to prevent mis-taps on mobile.
- The participant-side request control and its pending state (Feature 3) already meet the 44px / 16px standards.

### Technical Implementation

- **Participant side (already built, Phase 1):** `requestCoreMembership($db, $pid)` in `public/includes/challenges.php` (idempotent marker set); entry point on the Feature 3 Account tab.
- **Admin side (new, this feature):** Promotion-requests section + approve/reject handlers on `public/admin-challenges.php`; queue query + `getChallengeCpTotal()` per row.
- **Reused core helpers:** `generateUUID()`, `getDB()` transactions, `requireAdmin()`, `csrfField()/requireCsrf()`, `hashPassword()`/`verifyPassword()`, the `password_resets` set-password flow (`public/forgot_password.php` / `public/reset_password.php`), `sendEmail()`/`getEmailTemplate()`, `t()`.
- **Modified:** `public/includes/admin/users.php` — exclude `email IS NOT NULL AND core_user_id IS NOT NULL` from the core users list (REQ-506, shared with Feature/REQ-505). `public/admin.php` — add the admin-nav link to `admin-challenges.php`.
- **Translations:** admin queue strings (`admin_ch_promotion_*`, approve/reject labels, conflict notice) → `public/lang/admin.php`; account-ready and rejection email keys → `public/lang/email.php`.
- **No new table, column, setting, or cron.**

---

## Test Scenarios

```gherkin
Feature: Request to become a core member (admin-approved)

  Scenario: Participant can only request, not self-convert
    Given a verified participant
    When they request to become a core member
    Then only promotion_requested_at is set
    And no users row exists for them yet

  Scenario: Admin approves a permanent participant
    Given a permanent participant with a pending request
    When the admin approves it
    Then a users row is created with in_competition = 0 and their existing password
    And their participant row is linked by core_user_id
    And their CP history is unchanged

  Scenario: Admin approves a verified-only participant
    Given a verified participant (no password) with a pending request
    When the admin approves it
    Then a users row is created with an unusable password
    And a set-password link is emailed to them

  Scenario: Approval routes them to the converted-guests surface
    Given an approved promotion
    Then the new member appears in the converted-guests list with an in_competition toggle
    And they do not appear in the core users list

  Scenario: Admin rejects a request
    Given a participant with a pending request
    When the admin rejects it
    Then promotion_requested_at is cleared and no users row is created
    And the participant may request again later

  Scenario: Pool admission stays manual
    Given a freshly approved core member with in_competition = 0
    Then they appear on no leaderboard or pool calculation
    Until the admin flips their in_competition toggle
```

## Test Cases

```gherkin
Feature: Request to become a core member (admin-approved)

  Scenario: Request sets the marker exactly once
    Given a verified participant with promotion_requested_at null
    When requestCoreMembership runs twice
    Then promotion_requested_at is set on the first call only
    And no users row is created by either call

  Scenario: Non-admin cannot open the admin page
    Given a challenge guest session, then a non-admin core member
    When each requests admin-challenges.php
    Then access is denied exactly like admin.php

  Scenario: Approve permanent participant carries the password over
    Given a permanent participant P with email "kimi@example.com" and a stored password_hash H, CP total 45
    When the admin approves P inside one transaction
    Then a users row exists with email "kimi@example.com", role "user", in_competition 0, points 0, stars 0, password = H
    And challenge_participants.core_user_id for P equals the new users.id
    And promotion_requested_at for P is null
    And SUM(points) in challenge_points for P is still 45

  Scenario: Approved permanent participant logs in with the same credentials
    Given the approval above
    When P signs in at /login.php with "kimi@example.com" and their known password
    Then the login succeeds as a core member
    And opening the hub resolves the same participant by core_user_id with CP total 45

  Scenario: Approve verified-only participant emails a set-password link
    Given a verified participant with password_hash null and a pending request
    When the admin approves them
    Then the users row password never verifies against any input
    And a password_resets token is issued and a set-password email is sent
    And after they set a password via that link they can sign in

  Scenario: Approve is atomic on failure
    Given an approval where the users insert fails after BEGIN
    When the transaction rolls back
    Then no users row exists
    And core_user_id and promotion_requested_at are unchanged for the participant

  Scenario: Double-approve creates no second account
    Given a participant already linked by core_user_id from a prior approval
    When the admin re-posts Approve for that participant
    Then no second users row is created
    And the handler no-ops via the core_user_id guard

  Scenario: Email collision aborts safely
    Given a promotion request whose email somehow already exists in users
    When the admin approves it
    Then the transaction rolls back with a conflict notice
    And the request stays pending and no users row is added

  Scenario: Reject clears the marker only
    Given a participant with promotion_requested_at set
    When the admin rejects the request
    Then promotion_requested_at becomes null
    And no users row is created
    And the participant can submit a new request afterward

  Scenario: Approval preserves CP with no data migration
    Given a participant with 30 CP from rumors and 10 from trivia
    When the admin approves them
    Then no challenge_points row is inserted, deleted, or re-keyed
    And their CP total remains 40 under the same participant_id

  Scenario: Approval does not admit to the pool
    Given a freshly approved member with in_competition 0
    When the betting leaderboard renders
    Then the member is absent
    And they appear only after the admin flips in_competition on the converted-guests list

  Scenario: Every admin POST is CSRF-guarded
    Given the Challenges admin page
    When approve_promotion or reject_promotion is posted without a valid CSRF token
    Then the request is rejected by requireCsrf()
```
