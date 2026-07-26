# Epic: Unified Preferences Across Anonymous and Authenticated Sessions

## Business Value

Visitors should get a consistent, predictable preferences experience whether they are anonymous, returning on the same device, or signed in across devices. Preferences should follow the user once they have an account — not reset on login, not be forgotten on the next visit, and not feel "broken" after logout.

## Background

The F1 website has 3 quick toggles in the bottom navigation: **theme**, **language**, **font**. Today their behaviour is inconsistent:

- Theme and font are stored in the PHP session only — they are lost when the session expires and are never written to the user profile.
- Language is stored in the session and user profile and already survives re-login.
- The theme toggle icon uses the "next state" convention (shows a sun when dark is active). All three toggles will be standardised to "current state."

This epic closes those gaps and establishes a single resolution model covering the full preference lifecycle.

## Defaults

| Pref | Default | Current code value |
|---|---|---|
| Theme | `dark` | `light` ← will change |
| Language | Danish (`da`) | `da` ← no change |
| Font | `sys` | `editorial` ← will change |

The new defaults apply only to first-time visitors (no stored preference). Visitors with an existing preference in device storage or profile are not affected.

## Architecture decision: preference device storage

"Device storage" in this epic means a **persistent preference cookie** (not localStorage). Rationale:

- PHP reads cookies server-side before rendering `<body class="...">` → zero flash of unstyled content (FOUC) with no JavaScript required.
- The existing toggle mechanism (GET-param redirect, `setTheme()` / `setFont()` in PHP) can set the cookie in the same call — no new JS patterns.
- No Content-Security-Policy changes needed.
- Works without JavaScript enabled.

Cookie spec: `f1_theme` and `f1_font`, `HttpOnly: true`, `Secure: true`, `SameSite: Lax`, `Max-Age: 31536000` (1 year). Does not contain PII.

## Required DB migration

```sql
ALTER TABLE users
  ADD COLUMN theme      ENUM('dark','light')        NULL DEFAULT NULL,
  ADD COLUMN font_stack ENUM('system','editorial')  NULL DEFAULT NULL;
```

`NULL` = no profile preference yet (triggers AC4 seeding path). An explicit value means the user chose it. The `language` column already exists and is unchanged.

## Resolution Logic

| Scenario | Preferences source |
|---|---|
| First-time visitor (no preference cookie) | System defaults |
| Returning anonymous visitor (preference cookie exists) | Preference cookie |
| Authenticated user, no profile prefs yet (DB columns are NULL) | Inherit current session prefs → seed profile |
| Authenticated user with saved profile prefs | Profile (overrides cookie) |
| At logout | Current session prefs already reflected in cookie (set on every toggle) |
| Return after logout | Last logged-in prefs via preference cookie |

---

## Scope

### In scope
- Add `theme` and `font_stack` columns to `users` (migration)
- Preference resolution logic at session start (anonymous and authenticated)
- Persistent preference cookies (`f1_theme`, `f1_font`) for device storage
- Sync on login (seed profile if NULL; override session from profile if set)
- Sync on preference change (when authenticated, persist to profile AND update cookie)
- Theme and font loaded from DB on login (alongside existing language)
- Theme toggle icon correction
- Consistent icon semantics across all three toggles

### Out of scope
- localStorage-based storage (replaced by cookies per architecture decision above)
- Real-time cross-device sync of open sessions
- Adding new preference types, theme options, font options, or languages
- A "reset to defaults" UI action

---

## Features

**F1. Default prefs for new visitors** — Site loads with dark / Danish / sys for visitors with no preference cookie. Cookie is written on first response.

**F2. Persisted prefs for returning anonymous visitors** — PHP reads the preference cookie before rendering; last preferences are applied server-side with no flash.

**F3. Inheritance on first login** — If the user's profile has no saved prefs (DB columns NULL), the current session values are written to the profile. The UI does not change.

**F4. Profile prefs override on subsequent logins** — On login, if the profile has saved prefs, they are loaded into the session and written to the preference cookie on this device, overriding whatever was there.

**F5. Authenticated changes sync to profile** — `setTheme()` and `setFont()` write to DB when a user is logged in, mirroring the existing `setLang()` pattern.

**F6. Theme toggle icon fix** — `fa-moon` when dark is active, `fa-sun` when light is active (current-state convention, consistent with language and font toggles).

**F7. Logout prefs continuity** — Because `setTheme()` / `setFont()` update the cookie on every toggle, the cookie already reflects the current state at logout. No extra step needed at logout time.

---

## Acceptance Criteria

### AC1 — New visitor defaults
**Given** a visitor with no preference cookie and no session
**When** the page loads
**Then** theme = dark, language = Danish, font = sys are applied
**And** preference cookies (`f1_theme`, `f1_font`) are set with these values.

*Test layer: E2E — `browser.newContext()` with empty storage state. Assert `document.body.classList` and `context.cookies()`.*

### AC2 — Returning anonymous visitor
**Given** a preference cookie exists from a previous session
**And** the visitor is not signed in
**When** the page loads
**Then** the stored preferences are applied
**And** the bottom-nav toggles reflect those values.

**No-FOUC requirement:** The correct body class must be applied on first render. Guaranteed by the cookie architecture (PHP sets `<body class>` from the cookie before output). Verified **manually** — automated tests cannot observe mid-render state. Manual check: load on slow 3G, confirm correct body class on first paint.

*Test layer: E2E — set cookie via `context.addCookies(...)`, load page, assert body class.*

### AC3 — Anonymous preference change persists
**Given** an anonymous visitor changes a preference via the bottom nav
**Then** the change is applied immediately
**And** the preference cookie is updated
**And** the preference survives page reload and a new browser context loaded from the same cookie state.

*Test layer: E2E.*

### AC4 — First login (no profile prefs)
**Given** a visitor has active session prefs (defaults or customised)
**And** the user's profile has NULL for `theme` and `font_stack`
**When** authentication completes
**Then** the current session prefs are written to the profile (DB columns set)
**And** the visible prefs do NOT change as a result of logging in.

*Test layer: E2E — seeded user with `theme = NULL, font_stack = NULL`. Assert via `get_prefs` test-seed endpoint that DB values are now non-NULL and match the pre-login state.*

### AC5 — Returning login (profile prefs exist)
**Given** a user with explicitly saved profile prefs (e.g., `theme = 'light'`)
**And** the current anonymous session has a different value (e.g., `theme = 'dark'`)
**When** they log in
**Then** profile prefs are loaded and applied
**And** the preference cookies are updated to match profile prefs.

*Test layer: E2E — seeded user with explicit `theme` and `font_stack` values. Assert body class and cookies after login.*

### AC6 — Authenticated change persists to profile
**Given** a signed-in user changes a preference
**Then** the DB is updated immediately (same behaviour as `setLang()` today)
**And** the preference cookie is also updated
**And** the value is reflected on next login from any device.

*Test layer: E2E — toggle → assert via `get_prefs` endpoint → logout → login → assert body class.*

### AC7 — Logout prefs continuity
**Given** an authenticated user with active preferences
**When** they log out
**Then** the preference cookies continue to reflect the last active prefs (automatic — every toggle already updates the cookies)
**And** the UI does not change visually during the logout redirect
**And** subsequent behaviour is anonymous (further changes update cookies only).

*Test layer: E2E — log in → verify prefs → logout → assert cookies unchanged → navigate to a page → assert body class unchanged.*

### AC8 — Continued browsing after logout (same session)
**Given** an authenticated user has just logged out
**When** they continue browsing in the same session
**Then** the last-active prefs remain applied
**And** any further preference changes update the cookies only.

*Test layer: Continuation of AC7 test.*

### AC9 — Return visit on same device after logout
**Given** a user logged out with prefs X
**When** they return as an anonymous visitor (new browser context, preference cookies retained)
**Then** prefs X are applied via the returning-anonymous-visitor path
**And** further changes update cookies only.

*Test layer: E2E — `context.storageState()` snapshot after logout, create new context from snapshot, load page, assert body class.*

### AC10 — Return visit after device prefs changed between logout and return
**Given** the same setup as AC9
**But** the preference cookie was overwritten after logout
**When** the user returns
**Then** the overwritten cookie values are applied (last write wins)
**And** no restoration is attempted.

*Test layer: E2E — after logout, directly overwrite cookie → reload → assert overwritten value, not pre-logout value.*

### AC11 — Theme icon consistency (bug fix)
**Given** the language and font toggles display an icon representing the current state
**Then** the theme toggle follows the same convention:
- Dark theme active → `fa-moon`
- Light theme active → `fa-sun`

Fix location: `public/includes/bottom_bar.php` line 23.

*Test layer: E2E — assert icon class in each theme state.*

### AC12 — Icon convention documented
**Given** the three preference toggles
**Then** the current-state icon convention is documented in `docs/patterns.md` under "UI toggle conventions."

*Verified in PR review, not by automated test.*

---

## Non-functional Requirements

- **Performance:** Preference resolution adds zero JS to the critical path (PHP reads cookie before output). No perceptible delay.
- **Privacy:** Preference cookies contain only `theme` and `font` values — no PII, no user identifier.
- **Reliability:** If the profile DB write fails on login (AC4/AC5), fall back to the session values already in use. Log the failure. Do not fail the login.
- **Compatibility:** Degrades gracefully if cookies are blocked (falls back to system defaults on each visit).
- **Resilience:** Every `setTheme()` / `setFont()` call updates both session and cookie. The cookie is always current; no special logout sync step is needed.

---

## Edge Cases

1. Login on Device B with different anonymous prefs than Device A's profile → profile wins (AC5). Cookie on Device B is overwritten with profile values.
2. User clears browser data (including cookies) → treated as new visitor (AC1).
3. Profile contains a deprecated value → fall back to default for that field only.
4. Two devices change prefs simultaneously → last-write-wins on profile. **Accepted design decision.**
5. Logout on a shared device → next person sees previous user's last prefs via cookie until they change them (acceptable per scope, AC10).
6. Logout sync is not a distinct failure point — cookies are kept in sync by every toggle throughout the session.

---

## Open Questions — Resolved

| # | Question | Decision |
|---|---|---|
| 1 | Icon convention | Current-state across all three toggles. Proceed. |
| 2 | Conflict resolution across devices | Last-write-wins. Accepted. |
| 3 | Logout reset option | Not in scope. Users clear browser data. |
| 4 | Logout sync timing | Not applicable (cookie approach). Cookie is always current. |
| 5 | Profile write at logout | Not needed. Cookie is always current via in-session toggle sync. |

---

## Testing Requirements

### Test seeding additions needed

- Creating a user with `theme = NULL, font_stack = NULL` (for AC4) — handled automatically since the global seed INSERT does not specify these columns.
- Creating a user with explicit `theme = 'light', font_stack = 'editorial'` (for AC5) — seed before the AC5 test block.
- `get_prefs` action in `test-seed.php`: `GET /tools/test-seed.php?token=...&action=get_prefs&email=...` → `{"theme":"dark","font_stack":"system","language":"da"}`.

### FOUC — manual verification only

The no-FOUC requirement (AC2) cannot be verified by Playwright. Add to the DoD manual checklist:
- [ ] Open site on slow 3G throttling (Chrome DevTools) with a non-default preference cookie set. Confirm the correct body class is applied on first visible paint (no flash).

---

## Definition of Done

- [ ] DB migration (`theme`, `font_stack` columns on `users`) applied to both environments
- [ ] `getTheme()` / `getFont()` read from: session → preference cookie → default (in that order)
- [ ] `setTheme()` / `setFont()` write to: session + cookie (always) + DB (when authenticated)
- [ ] Login flow loads `theme` and `font_stack` from DB (alongside existing `language`)
- [ ] First-login seeding (AC4) implemented and verified
- [ ] Theme icon bug fixed (`public/includes/bottom_bar.php` line 23)
- [ ] Icon convention documented in `docs/patterns.md`
- [ ] E2E tests cover AC1–AC11 (AC2 FOUC and AC12 documentation verified manually/in PR)
- [ ] `get_prefs` test-seed endpoint added
- [ ] No FOUC verified manually on slow 3G
- [ ] Works on mobile and desktop
- [ ] Schema migration SQL added to `database/schema.sql`
