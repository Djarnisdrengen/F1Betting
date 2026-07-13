# Feature 3: Participant Profile & Identity Management

Epic: `paddock-challenges-epic.md` · Consolidated spec: `feature.md` (§B — Features 1 & 2) · Plan: `plan.md` · Test plan: `test-plan.md`

> **Where this sits.** Features 1 & 2 (feature.md §B) give a participant an *identity* — anonymous
> play, a saved email, a persistent access link, an optional password. Feature 3 gives that identity
> a **home page**: the participant equivalent of the core member's `public/profile.php`, scoped to
> what a participant can actually do. It is the surface that hosts the "set a password" step (B4/D14),
> the "sign out / sign out everywhere" controls (B3/REQ-123), and the entry point into Feature 4
> ("request to become a core member"). Per **REQ-128** a permanent participant gets "Feature 3
> profile parity **minus the History tab**" — i.e. the same profile as a verified participant, and
> deliberately **no betting-history tab** (participants have no bets).

---

## Requirements

### Functional Requirements

- [REQ-601] A **verified** or **permanent** participant with no core account has a dedicated profile page `public/challenges-profile.php`, arena-skinned, reachable from the burger drawer and the hub. It mirrors `public/profile.php`'s tabbed layout (`.hf-tabs` / `.hf-tab-nav` / `.hf-tab-panel`) but with participant-appropriate tabs only.
- [REQ-602] **Identity header** (top of the page, model: `public/profile.php:271-277`): avatar initial, display name (or "Guest ####" fallback per REQ-107), and — for verified/permanent — the saved email. A **CP stat strip** below it shows the three challenge figures **Your CP · Rank · Day streak** (via `getChallengeCpTotal()`, the CP-board rank, and `getChallengeStreak()`), never any betting points (REQ-105 isolation).
- [REQ-603] **Profile tab** — edit **display name** (`display_name`, ≤100 chars, `sanitizeString()`; REQ-107) and read-only email. Saving updates the `challenge_participants` row only; it never touches `users`.
- [REQ-604] **Preferences tab** — Theme / Font / Language segmented controls, identical in behaviour to the core Preferences tab (`public/profile.php:560-598`) and the drawer Preferences block (REQ-003). Language persists to the participant's `language` column so game content renders correctly (REQ-102/D3); theme/font persist via the existing cookie round-trip (`setTheme()/setFont()`, NFR-001). Available regardless of password state.
- [REQ-605] **Account tab** — three blocks:
  1. **Password** — a **verified** participant (no `password_hash`) sees "Set a password" → becomes a **permanent participant** (B4/D14/REQ-125); a **permanent** participant sees "Change password" (current + new + confirm, model: `public/profile.php:326-352`). Setting/changing a password never grants core rights (REQ-128).
  2. **Sessions** — "**Sign out**" (this device: `revokeAccessToken()`, clears `ch_access`, REQ-123) and "**Sign out everywhere**" (`revokeAllAccessTokens()`, revokes every access token for the participant, REQ-123).
  3. **Become a core member** — a single "Request to become a core member" action that enters the Feature 4 admin-approval queue (`requestCoreMembership()`). Shown only when the participant is **not** already core-linked and has **no** pending request; once requested it collapses to a read-only "Request pending review" state (REQ-702).
- [REQ-606] The page has **no betting-history tab** and no betting/pool/bet surface of any kind (REQ-128, epic scope "Out (v1)"). The core member's History tab (`public/profile.php:600-637`) is intentionally absent for participants.
- [REQ-607] **Anonymous** participants (status `pending`, `email NULL`) have **no** profile page: visiting `challenges-profile.php` shows a "save your spot to keep a profile" prompt that routes them into the invite/save flow (Feature 1 §B2), because an anonymous identity has nothing durable to manage.
- [REQ-608] A **core member** who opens `challenges-profile.php` is redirected to the core `public/profile.php` (their identity, preferences, security, and betting history live there); the participant profile is only for participants without a core account. No duplicate identity surface is shown.
- [REQ-609] Every tab renders in the participant's selected language via `t()`; new participant-profile strings are added to `public/lang/user.php` (da+en). Success/error feedback uses the existing flash pattern (`$_SESSION['flash_success'|'flash_error']` + PRG redirect back to the active tab).

### Non-Functional Requirements

- [NFR-601] simply.com shared hosting: PHP 8 + PDO/MySQL, no Node runtime; no build step (plain PHP/JS/CSS).
- [NFR-602] All writes are prepared statements; every POST carries `csrfField()` and the handler calls `requireCsrf()`; all output escaped at render with `escape()`/`htmlspecialchars()`.
- [NFR-603] Password set/change reuses `validatePasswordStrength()` + `hashPassword()` / `verifyPassword()` exactly as core profile does — no separate participant password policy (REQ-125/126).
- [NFR-604] Session/cookie mutations (`revokeAccessToken`, `revokeAllAccessTokens`, clearing `ch_access`) happen **before any output**; `setcookie()` is never called from page body (NFR-001, established security-header order).
- [NFR-605] No betting points, bets, pool math, or `users.total_points` are read or written anywhere on this page (REQ-105 isolation).
- [NFR-606] Mobile-first: 44px minimum touch targets, 16px minimum input font (no iOS zoom), no layout shift between tabs.

### Technical Constraints

- Must work on simply.com shared hosting (PHP 8, MySQL, Apache + mod_rewrite, no Node).
- No build step — direct deployment of source files.
- Arena skin reuses the namespaced `.hf-arena-*` block (added in Phase 1); no edits to existing CSS rules.
- Reuses `public/profile.php`'s tab markup/JS (`data-target` / `.hf-tab-btn`, `app.js` tab switching) — no new tab framework.
- **No schema change.** Every column this feature reads or writes (`display_name`, `language`, `password_hash`, `promotion_requested_at`, `challenge_access_tokens`) already exists from Phase 0/1.

---

## User Story

### Primary User Goal

A participant who has saved their spot wants one place to manage who they are in Challenges —
their name, their language, their password, their sessions — and to ask to be let into the "real"
game, without ever being shown betting machinery they can't use.

### User Story Format

**As a** verified or permanent Challenges participant (no core account)
**I want to** manage my display name, preferences, password and sessions in one place — and request full membership from there
**So that** I have a durable, self-managed identity that stays cleanly separate from the core betting game.

### User Personas

- **Casual F1 fan (verified guest):** saved their spot with one email; wants to pick a display name so they're not "Guest 4f2a" on the CP board, and to sign out on a shared laptop.
- **Committed guest (permanent participant):** set a password; wants to change it later and, eventually, request to become a core member.
- **Core member:** never sees this page — bounced to the core profile where their identity actually lives.

---

## Functionality

### User Flow

1. A verified/permanent participant opens the burger drawer → **Profile** (participant variant) → lands on `challenges-profile.php`, Profile tab active.
2. **Profile tab:** edits display name, taps Save → PRG redirect, green flash "Profile updated", CP-board name and header update.
3. **Preferences tab:** flips Theme / Font / Language segmented controls → server round-trip persists (language to their row); content re-renders in the chosen language.
4. **Account tab (verified, no password):** enters a password (≥ policy) → becomes permanent; the block flips to "Change password"; an access token + cookie are (re)issued so they stay signed in.
5. **Account tab (sessions):** "Sign out" ends this device only; "Sign out everywhere" ends all devices (both revoke access tokens per REQ-123).
6. **Account tab (promotion):** taps "Request to become a core member" → confirmation → block collapses to "Request pending review"; the request now sits in the Feature 4 admin queue.
7. An **anonymous** visitor hitting the URL instead sees a "save your spot" prompt (no tabs); a **core member** is redirected to `/profile.php`.

### Detailed Specifications

- **Gating.** On load: resolve `getChallengeParticipant()`. If null → redirect to the hub / save-your-spot. If it has a `core_user_id` → 302 to `/profile.php` (REQ-608). If `status='pending'` / `email IS NULL` → render the anonymous save-your-spot prompt (REQ-607). Otherwise render the full profile.
- **Tabs.** Profile · Preferences · Account (exactly three). No History tab (REQ-606). Tab switching reuses the existing `.hf-tab-btn[data-target]` + `app.js` behaviour and the `?tab=` deep-link pattern (`public/profile.php:58` etc.) so post-action redirects land on the right tab.
- **Display name** (Profile tab): identical validation to core (`mb_strlen ≤ 100`, `sanitizeString()`); empty is allowed (falls back to "Guest ####" on the board, REQ-107). Action `update_display_name`.
- **Preferences** (Preferences tab): action `update_preferences`; theme ∈ {dark,light}, font ∈ {system,editorial}, language ∈ {da,en}; persists via `setTheme()/setFont()/setLang()`. For a participant, `setLang()` writes the `challenge_participants.language` column (not `users`) — the participant-scoped path, so subsequent game queries pick the right `*_da/_en` text.
- **Password** (Account tab):
  - *Verified → set password* (action `set_password`): `validatePasswordStrength($new)`; `$new === $confirm`; on success `UPDATE challenge_participants SET password_hash = ? WHERE id = ?` via `hashPassword($new)`; re-issue access token/cookie; flash "Password set — you can now sign in with your email and password" (REQ-125). No `users` row, no `establishSession()`.
  - *Permanent → change password* (action `change_password`): verify current against stored `password_hash` with `verifyPassword()`; then same strength/confirm checks; update hash (REQ-126). Mirrors `public/profile.php:31-59` but against `challenge_participants`.
- **Sessions** (Account tab): "Sign out" (action `signout`) → `revokeAccessToken($db)` + destroy the challenge session marker → redirect to hub. "Sign out everywhere" (action `signout_all`) → `revokeAllAccessTokens($db, $pid)` → redirect. Both no-ops for other participants' tokens.
- **Promotion entry** (Account tab): "Request to become a core member" (action `request_core`) calls `requestCoreMembership($db, $pid)` (idempotent: only sets `promotion_requested_at` when currently NULL). After it is set, render read-only "Request pending review — an admin will approve it" and hide the button (REQ-702). Full approval flow = **Feature 4**.
- **Error handling & edge cases:**
  - Setting a password when `password_hash` is already present → treated as "change password" (require current) — the UI never shows "set" for a permanent participant, and the handler re-checks state server-side.
  - Concurrent tabs / stale form after promotion already requested → `requestCoreMembership()` no-ops (WHERE `promotion_requested_at IS NULL`); the page re-renders the pending state.
  - Access-cookie already rotated in another tab → sign-out still succeeds (revokes by whatever token the cookie currently holds; "everywhere" revokes all regardless).
  - Participant row deleted mid-session (e.g. 30-day purge, REQ-110) → `getChallengeParticipant()` returns null → redirect to save-your-spot.

### Scoring Logic

Not applicable — this feature manages identity only. It **reads** CP totals for display via
`getChallengeCpTotal()` / `getChallengeStreak()` and the CP-board rank query, and **never** computes,
awards, or combines points (REQ-105).

### Mobile Considerations

- Touch targets ≥ 44px; inputs ≥ 16px (no iOS zoom) — NFR-606.
- Segmented preference controls are the same `.hf-seg`/`.hf-pref-toggle` used elsewhere; thumb-reachable.
- Single-column stacked tabs on ≤ 360px; no horizontal scroll; no layout shift between tabs.
- Destructive-ish "Sign out everywhere" is visually secondary to reduce mis-taps.

### Technical Implementation

- **New page:** `public/challenges-profile.php` — standard opening (`config.php` → `functions.php` → `challenges.php`), resolve participant, gate per REQ-607/608, `requireCsrf()` on POST, PRG per action, arena chrome + reused tab markup.
- **Helpers reused (already in `public/includes/challenges.php`):** `getChallengeParticipant()`, `getChallengeCpTotal()`, `getChallengeStreak()`, `revokeAccessToken()`, `revokeAllAccessTokens()`, `issueAccessToken()`, `requestCoreMembership()`.
- **Core helpers reused:** `validatePasswordStrength()`, `hashPassword()`, `verifyPassword()`, `sanitizeString()`, `setTheme()/setFont()/setLang()`, `csrfField()/requireCsrf()`, `escape()`, `t()`.
- **Drawer link** (`public/includes/header.php`): the account block's "Profile" row points to `challenges-profile.php` when the visitor is a participant (no core account), or `/profile.php` when core — one row, resolved by identity.
- **Translations:** `ch_profile_*`, `ch_set_password_*`, `ch_change_password_*`, `ch_signout`, `ch_signout_all`, `ch_request_core`, `ch_request_pending`, `ch_save_spot_prompt` → `public/lang/user.php` (da+en).
- **No new tables, columns, settings, or cron.**

---

## Test Scenarios

```gherkin
Feature: Participant profile & identity management

  Scenario: Verified participant manages their profile
    Given a verified participant with no password
    When they open the participant profile
    Then they see Profile, Preferences and Account tabs and no betting-history tab
    And the CP stat strip shows their CP, rank and streak with no betting points

  Scenario: Verified participant becomes permanent from the Account tab
    Given a verified participant with no password
    When they set a password on the Account tab
    Then password_hash is stored on their participant row
    And they gain no core-member rights
    And they can subsequently sign in at /login.php

  Scenario: Sessions can be ended per-device or everywhere
    Given a participant signed in on two devices
    When they choose "Sign out" on one device
    Then only that device's token is revoked
    But choosing "Sign out everywhere" revokes all their tokens

  Scenario: Promotion request is offered here and enters the admin queue
    Given a verified or permanent participant with no pending request
    When they request to become a core member
    Then the button collapses to a pending-review state
    And no users row is written by this action

  Scenario: Anonymous and core visitors do not get this page
    Given an anonymous participant, then a core member
    When each opens the participant profile URL
    Then the anonymous one sees a save-your-spot prompt
    And the core member is redirected to the core profile page
```

## Test Cases

```gherkin
Feature: Participant profile & identity management

  Scenario: Display name update is participant-scoped
    Given a verified participant "P" with display_name null
    When they submit display_name "Kimi" on the Profile tab
    Then challenge_participants.display_name for P becomes "Kimi"
    And no row in users is created or modified
    And the CP board shows "Kimi" instead of "Guest ####"

  Scenario: Display name over 100 chars is rejected
    Given a verified participant on the Profile tab
    When they submit a 101-character display name
    Then the update is rejected with a "too long" message
    And the stored display name is unchanged

  Scenario: Set-password promotes verified to permanent
    Given a verified participant with password_hash null
    And they enter a policy-valid password twice identically
    When they submit "Set a password"
    Then password_hash is written via hashPassword()
    And an access token and ch_access cookie are re-issued
    And the Account tab now shows "Change password"
    And their core_user_id remains null

  Scenario: Set-password rejects a weak password
    Given a verified participant setting a password
    When they submit a password that fails validatePasswordStrength()
    Then the policy error is shown and password_hash stays null

  Scenario: Change-password requires the current password
    Given a permanent participant
    When they submit a new password with the wrong current password
    Then the change is rejected with "current password wrong"
    And password_hash is unchanged

  Scenario: Change-password requires matching confirmation
    Given a permanent participant with the correct current password
    When new and confirm differ
    Then the change is rejected with "passwords do not match"

  Scenario: Sign out this device only
    Given participant P signed in on device A and device B
    When P signs out on device A
    Then A's access token is deleted and its ch_access cookie cleared
    And B's access token still resolves P on the next request

  Scenario: Sign out everywhere
    Given participant P with access tokens on three devices
    When P chooses "Sign out everywhere"
    Then all three access tokens for P are deleted
    And none of the three devices re-establish a session from their cookie

  Scenario: Request core membership is idempotent
    Given a verified participant with promotion_requested_at null
    When they request core membership twice
    Then promotion_requested_at is set exactly once
    And the second submit re-renders the pending state without error
    And users is never written by either submit

  Scenario: Preferences language drives content language
    Given a participant viewing a rumor item stored in da and en
    When they switch Language to "en" on the Preferences tab
    Then challenge_participants.language becomes "en"
    And the item text renders from text_en

  Scenario: No betting surface is present
    Given any participant on any tab of the participant profile
    Then no bets, pool, or total_points value appears anywhere on the page
    And there is no History tab

  Scenario: Core member is redirected away
    Given a logged-in core member (users row + linked participant)
    When they request challenges-profile.php
    Then they receive a redirect to /profile.php

  Scenario: Anonymous participant is prompted to save their spot
    Given an anonymous participant (status pending, email null)
    When they request challenges-profile.php
    Then no profile tabs render
    And a save-your-spot prompt routes them into the invite/save flow

  Scenario: Every POST is CSRF-guarded
    Given the participant profile page
    When any action (update_display_name, update_preferences, set_password, change_password, signout, signout_all, request_core) is posted without a valid CSRF token
    Then the request is rejected by requireCsrf()
```
