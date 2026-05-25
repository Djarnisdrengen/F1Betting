# Plan: Improved Profile Page – Intuitive Name & Preferences Management

## Context

The current profile page stacks three independent cards vertically (Edit Profile, Change Password, Preferences) making the page long and cognitively heavy. All validation is server-round-trip only. The preferences section uses plain dropdowns with no visual preview. Inline styles on the stats strip and layout grid bypass the design system. This plan restructures the view layer only — no backend logic changes.

---

## Wireframes

### Mobile (< 768px)

```
┌──────────────────────────────┐
│  [T]  Thomas Helveg          │   ← avatar initial + display name
│       thpovlsen@gmail.com    │   ← email sub-line
└──────────────────────────────┘

┌───────┬───────┬───────┬──────┐
│ 152   │  3    │ User  │  Yes │   ← stats strip (CSS class, no inline grid)
│ Points│ Stars │ Role  │ Comp │
└───────┴───────┴───────┴──────┘

[ Profile ] [ Security ] [ Preferences ]   ← tab bar

┌──────────────────────────────┐
│  Display Name                │
│  ┌────────────────────────┐  │
│  │ Thomas Helveg          │  │
│  └────────────────────────┘  │
│                        12/100│   ← live char counter
│                              │
│  [         Save            ] │
└──────────────────────────────┘

┌──────────────────────────────┐
│  Betting History             │
│  ──────────────              │
│  Monaco GP ★                 │
│  Monaco · 25 May             │
│  VER · PER · LEC       [15p] │
│                              │
│  Bahrain GP                  │
│  Bahrain · 2 Mar             │
│  VER · ALO · PER        [8p] │
└──────────────────────────────┘
```

### Security Tab (mobile)

```
[ Profile ] [ Security ] [ Preferences ]

┌──────────────────────────────┐
│  🔒 Change Password          │
│                              │
│  Current Password            │
│  ┌────────────────────────┐  │
│  │ ••••••••               │  │
│  └────────────────────────┘  │
│                              │
│  New Password                │
│  ┌────────────────────────┐  │
│  │ ••••••••               │  │
│  └────────────────────────┘  │
│  ████░░░░  Medium            │   ← password strength bar
│                              │
│  Confirm Password            │
│  ┌────────────────────────┐  │
│  │ ••••••••               │  │
│  └────────────────────────┘  │
│  ✓ Passwords match           │   ← live match indicator
│                              │
│  [   Change Password       ] │
└──────────────────────────────┘
```

### Preferences Tab (mobile)

```
[ Profile ] [ Security ] [ Preferences ]

┌──────────────────────────────┐
│  Theme                       │
│  ┌────────────┬─────────────┐│
│  │  🌙 Dark   │  ☀️  Light  ││   ← segmented toggle
│  └────────────┴─────────────┘│
│                              │
│  Font                        │
│  ┌────────────┬─────────────┐│
│  │   System   │  Editorial  ││   ← segmented toggle
│  └────────────┴─────────────┘│
│                              │
│  Language                    │
│  ┌────────────┬─────────────┐│
│  │ 🇩🇰 Dansk  │ 🇬🇧 English ││   ← segmented toggle (moved from Profile)
│  └────────────┴─────────────┘│
│                              │
│  [         Save            ] │
└──────────────────────────────┘
```

### Desktop (≥ 768px) — 2-column, tabs left

```
┌──────────────────────────────────────────────────────────────────┐
│  [T]  Thomas Helveg Povlsen     thpovlsen@gmail.com              │
└──────────────────────────────────────────────────────────────────┘

┌──────────┬──────────┬──────────┬──────────┐
│  152 pts │   3 ★    │   User   │   Yes    │
└──────────┴──────────┴──────────┴──────────┘

┌─────────────────────────────┐  ┌────────────────────────────────┐
│ [Profile] Security Prefs    │  │ Betting History                │
├─────────────────────────────┤  │                                │
│ Display Name           12/100│  │ Monaco GP ★                   │
│ ┌───────────────────────┐  │  │ Monaco · 25 May 2025           │
│ │ Thomas Helveg         │  │  │ VER · PER · LEC          [15p] │
│ └───────────────────────┘  │  │                                │
│                             │  │ Bahrain GP                     │
│ [         Save            ] │  │ Bahrain · 2 Mar 2025           │
└─────────────────────────────┘  │ HAM · VER · NOR           [8p] │
                                 │                                │
                                 │ ...                            │
                                 └────────────────────────────────┘
```

---

## Architecture Review (web-architecture-review)

**Issues in current code:**

| Severity | Location | Problem | Fix |
|---|---|---|---|
| Low | profile.php:99 | Stats strip uses inline `style="display:grid..."` | Move to `.hf-profile-stats` CSS class |
| Low | profile.php:126 | 2-col grid class `hf-profile-grid` exists but stats above it do not | Verify class exists; add if missing |
| Medium | UX | 3 stacked forms = long vertical scroll, no orientation cues | Tab-based navigation |
| Medium | UX | Preferences dropdowns give no visual preview of the selection | Segmented toggle buttons |
| Low | UX | No char counter on display_name (max 100) | Client-side counter |
| Low | UX | Password mismatch only caught server-side | Client-side match indicator |

**Backend change — language moves to `update_preferences`:**
- Remove `language` field and `setLang()` call from `update_profile` handler
- Add `$newLang = in_array(...) ? ... : 'da'` and `setLang($newLang)` to `update_preferences` handler
- The `update_profile` POST form no longer includes a language field

**What stays unchanged (do not touch):**
- `change_password` handler — untouched
- `requireCsrf()` / `csrfField()` calls on all forms
- All prepared statements and sanitization helpers
- Bet history query (correct single JOIN, no N+1)

---

## Test Strategy (test-strategy-manager)

### Acceptance Criteria (Gherkin)

```gherkin
Feature: Profile Page Tabs

  Scenario: Tab navigation defaults to Profile tab
    Given I navigate to profile.php
    Then the "Profile" tab is active
    And the display name field is visible
    And the password fields are hidden

  Scenario: Switching to Security tab
    Given I am on profile.php
    When I click the "Security" tab
    Then the password fields are visible
    And the display name field is hidden

  Scenario: Tab selection survives flash message redirect
    Given I submit the Profile form successfully
    When the page reloads with a success flash
    Then the "Profile" tab is active

Feature: Display Name Character Counter

  Scenario: Counter updates live as user types
    Given the display name field is visible
    When I type "Thomas" into the display name field
    Then the counter shows "6/100"

  Scenario: Counter warns at limit
    Given I type 95 characters into the display name field
    Then the counter shows "95/100" in warning color

Feature: Password Match Indicator

  Scenario: Indicator shows match when passwords align
    Given I am on the Security tab
    When I type "abc123" in new password and "abc123" in confirm password
    Then I see a green "✓ Passwords match" message

  Scenario: Indicator shows mismatch
    When I type "abc123" in new password and "xyz789" in confirm password
    Then I see a red "✗ Passwords do not match" message

Feature: Visual Preference Toggles

  Scenario: Active preference is visually highlighted
    Given my saved theme is "dark"
    When I open the Preferences tab
    Then the "Dark" toggle button has the active/selected style
    And the "Light" button is inactive

  Scenario: Clicking a toggle selects it
    Given I am on the Preferences tab
    When I click the "Light" theme toggle
    Then "Light" becomes visually active
    And submitting the form saves "light" as my theme
```

### E2E Test Cases

| ID | Scenario | Steps | Expected |
|---|---|---|---|
| TC01 | Profile update – success | Open Profile tab → change name → Save | Flash "Profile updated!", name persists on reload |
| TC02 | Profile update – name too long | Enter 101 chars → Save | Server error displayed in Profile tab (not Security tab) |
| TC03 | Password change – success | Security tab → fill all fields correctly → submit | Flash "Password changed!" |
| TC04 | Password change – wrong current password | Enter wrong current password | Error visible on Security tab |
| TC05 | Password change – mismatch | New ≠ confirm → submit | Server error; client indicator shows mismatch before submit |
| TC06 | Preferences – theme saved | Select Light → Save | Theme cookie + DB updated; light theme active on reload |
| TC07 | Preferences – font saved | Select Editorial → Save | Font cookie + DB updated; editorial font active on reload |
| TC09 | Preferences – language saved | Select English toggle → Save | Language cookie + DB updated; UI switches to English |
| TC08 | JS disabled – all forms visible | Disable JS, load profile | All three forms shown stacked (progressive enhancement) |

---

## Implementation Plan

### Phase 1 — HTML restructure (`public/profile.php`)

1. **Replace the three stacked cards** with a single `.hf-tabs` container:
   - One `<nav>` with three `<button class="hf-tab-btn" data-target="tab-profile">` elements
   - Three `<div class="hf-tab-panel" id="tab-profile">` panels wrapping the existing form markup
2. **Move the stats strip** inline style to class `hf-profile-stats` (see Phase 2)
3. **Add `data-testid`** attributes: `tab-profile-btn`, `tab-security-btn`, `tab-preferences-btn`, `tab-profile-panel`, `tab-security-panel`, `tab-preferences-panel`
4. **Default state**: all panels have `hidden` attribute; JS removes it from active one; if JS disabled all visible (progressive enhancement)
5. **Add char counter**: `<span class="hf-char-counter" data-for="display_name">0/100</span>` after the display_name input
6. **Add match indicator**: `<span class="hf-pw-match" aria-live="polite"></span>` after confirm_password input
7. **Replace preference dropdowns + language dropdown** with segmented toggle markup (same pattern for all three):
   ```html
   <div class="hf-pref-toggle" role="group" aria-label="Theme">
     <button type="button" class="hf-pref-btn" data-value="dark">🌙 Dark</button>
     <button type="button" class="hf-pref-btn" data-value="light">☀️ Light</button>
   </div>
   <input type="hidden" name="pref_theme" value="<?= getTheme() ?>">

   <div class="hf-pref-toggle" role="group" aria-label="Font">
     <button type="button" class="hf-pref-btn" data-value="system">System</button>
     <button type="button" class="hf-pref-btn" data-value="editorial">Editorial</button>
   </div>
   <input type="hidden" name="pref_font" value="<?= getFont() ?>">

   <div class="hf-pref-toggle" role="group" aria-label="Language">
     <button type="button" class="hf-pref-btn" data-value="da">🇩🇰 Dansk</button>
     <button type="button" class="hf-pref-btn" data-value="en">🇬🇧 English</button>
   </div>
   <input type="hidden" name="language" value="<?= $currentUser['language'] ?? 'da' ?>">
   ```
8. **Remove language field** from the `update_profile` form entirely.

### Phase 2 — CSS (`public/assets/css/style.css`)

Add new classes (append to end of file, no changes to existing):

- `.hf-profile-stats` — grid layout for the 4-stat strip (replaces inline style on profile.php:99)
- `.hf-tabs` — wrapper
- `.hf-tab-nav` — flex row for tab buttons
- `.hf-tab-btn` — individual tab button; `.hf-tab-btn.active` for selected state
- `.hf-tab-panel` — form wrapper; `[hidden]` hides it; no JS fallback shows all
- `.hf-char-counter` — small muted text; `.hf-char-counter.warn` turns amber at ≥ 90 chars
- `.hf-pw-match` — inline feedback; `.hf-pw-match.ok` = green, `.hf-pw-match.err` = red
- `.hf-pref-toggle` — flex row for segmented buttons
- `.hf-pref-btn` — toggle button; `.hf-pref-btn.active` = filled/accent background

### Phase 3 — JavaScript (`public/assets/js/app.js`)

Add to existing file (append, do not restructure existing code):

1. **Tab init** — on DOMContentLoaded, read `?tab=` from URL (or default `profile`); remove `hidden` from matching panel; add `active` to matching button
2. **Tab click** — on click of `.hf-tab-btn`, hide all panels, deactivate all buttons, activate clicked tab; update URL hash (no page reload)
3. **Char counter** — on `input` of `[name="display_name"]`, update `hf-char-counter` text and toggle `.warn`
4. **Password match** — on `input` of `[name="new_password"]` or `[name="confirm_password"]`, compare values, update `.hf-pw-match` class and text
5. **Preference toggles** — on click of `.hf-pref-btn`, update sibling `.active` states and write value to the corresponding hidden input

### Phase 4 — Translations (`public/lang/user.php`)

Add to both `da` and `en` sections:

| Key | Danish | English |
|---|---|---|
| `tab_profile` | `Profil` | `Profile` |
| `tab_security` | `Sikkerhed` | `Security` |
| `tab_preferences` | `Præferencer` | `Preferences` |
| `passwords_match` | `Adgangskoderne matcher` | `Passwords match` |
| `pw_strength_weak` | `Svag` | `Weak` |
| `pw_strength_medium` | `Middel` | `Medium` |
| `pw_strength_strong` | `Stærk` | `Strong` |

`language_label` already exists in both locales — no new key needed for the label. The toggle values (`da`/`en`) are code values, not displayed text.

---

## Files to Modify

| File | Change |
|---|---|
| `public/profile.php` | Tab container, char counter span, toggle markup, remove stats inline style |
| `public/assets/css/style.css` | Append new CSS classes (tabs, toggles, counter, match) |
| `public/assets/js/app.js` | Append tab init, char counter, pw match, toggle JS |
| `public/lang/user.php` | Add 7 new translation keys in both `da` and `en` |

No backend PHP logic changes required. No new files required.

---

## Verification

1. Run `npm run test:e2e:test` — existing profile E2E tests must still pass
2. Manually: open profile page, confirm Profile tab active by default
3. Manually: type in display name → counter updates live
4. Manually: type in new/confirm password → match indicator updates live
5. Manually: click Dark/Light toggle → hidden input updates → Save → reload confirms preference persisted
6. Manually: disable JS in browser → confirm all three forms visible (progressive enhancement)
7. Manually: submit a form with an error → correct tab is visible on redirect (server echoes active tab via query string or the flash indicates which section)
8. Run `npm run test:security` — no regressions
