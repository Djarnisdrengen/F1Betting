# Feature: Profile Page Preferences Management

> **Parent epic:** Unified Preferences (epics/unified-preferences/)  
> **Status:** Ready for implementation

---

## Contents

- [Scope Clarification](#scope-clarification)
- [Requirements](#requirements)
- [User Story](#user-story)
- [Functionality](#functionality)
- [Acceptance Criteria](#acceptance-criteria)
- [Test Strategy](#test-strategy)
- [New Translation Keys](#new-translation-keys)
- [Files to Change](#files-to-change)

---

## Scope Clarification

The original feature draft described "three toggles mirroring the bottom nav". In practice:

| Preference | Bottom nav | Edit Profile form | This feature |
|---|---|---|---|
| Language | `?toggle_lang=1` | Already a `<select>` | **No change** |
| Theme | `?toggle_theme=1` | Not present | **Add `<select>` to new card** |
| Font | `?toggle_font=1` | Not present | **Add `<select>` to new card** |

Language is an identity/localisation field that already lives in "Edit Profile" — it should stay there. This feature adds a dedicated **Preferences card** for the two visual toggles (theme + font) and hides the bottom navigation bar on the profile page so the profile page becomes the sole control surface for authenticated users.

Public pages are **not affected** — the bottom nav and its three toggles remain fully operational everywhere else.

---

## Requirements

### Functional

- [REQ-001] A "Preferences" card must be added to `public/profile.php`, positioned in the left column below the "Change Password" card.
- [REQ-002] The card must contain two `<select>` fields: **Theme** (`dark` / `light`) and **Font** (`system` / `editorial`), pre-selected to the user's current preference.
- [REQ-003] Submitting the form must call `setTheme()` and `setFont()` — the existing helpers that write session + cookie + DB atomically.
- [REQ-004] On success, the page must redirect back to `profile.php` (PRG pattern) with a success flash in `$_SESSION['flash_success']`; the form must **never** re-render with stale POST data.
- [REQ-005] The bottom navigation bar must be suppressed on `profile.php` only; it must remain visible on every other page.
- [REQ-006] All labels and option text must go through `t()` — no hardcoded DA/EN strings in the template.

### Constraints

- No changes to `setTheme()`, `setFont()`, or `setLang()` in `functions.php` — they are already correct from the parent epic.
- No changes to any page other than `profile.php`, `footer.php`, and `public/lang/user.php`.
- The POST handler must follow the existing `action` hidden-field dispatch pattern already in `profile.php`.
- Redirect after POST must use `header('Location: profile.php')` + `exit` to prevent double-submission.

---

## User Story

**As a** Paddock Picks player who is logged in  
**I want to** change my theme and font preference from my profile page  
**So that** I have one place to manage all my settings and don't need to use the bottom navigation toggles while on that page

---

## Functionality

### Profile page changes

#### New Preferences card — left column, below Change Password

```php
<!-- Preferences -->
<div class="card">
    <div class="card-body">
        <h3 style="margin-bottom:16px;">
            <i class="fas fa-sliders-h text-accent"></i> <?= t('preferences') ?>
        </h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_preferences">
            <div class="form-group">
                <label class="form-label"><?= t('theme') ?></label>
                <select name="pref_theme" class="form-input">
                    <option value="dark"  <?= getTheme() === 'dark'  ? 'selected' : '' ?>><?= t('theme_dark') ?></option>
                    <option value="light" <?= getTheme() === 'light' ? 'selected' : '' ?>><?= t('theme_light') ?></option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label"><?= t('font_label') ?></label>
                <select name="pref_font" class="form-input">
                    <option value="system"    <?= getFont() === 'system'    ? 'selected' : '' ?>><?= t('font_system') ?></option>
                    <option value="editorial" <?= getFont() === 'editorial' ? 'selected' : '' ?>><?= t('font_editorial') ?></option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">
                <i class="fas fa-save"></i> <?= t('save') ?>
            </button>
        </form>
    </div>
</div>
```

#### POST handler — new `update_preferences` action

Insert into the `if ($_SERVER['REQUEST_METHOD'] === 'POST')` block, alongside the existing `update_profile` and `change_password` actions:

```php
} elseif ($action === 'update_preferences') {
    $newTheme = in_array($_POST['pref_theme'] ?? '', ['dark', 'light'])         ? $_POST['pref_theme'] : 'dark';
    $newFont  = in_array($_POST['pref_font']  ?? '', ['system', 'editorial'])   ? $_POST['pref_font']  : 'system';
    setTheme($newTheme);
    setFont($newFont);
    $_SESSION['flash_success'] = t('preferences_updated');
    header('Location: profile.php');
    exit;
}
```

#### Flash message rendering

Replace the existing `$success` / `$error` alert blocks with session-flash support:

```php
$success = $_SESSION['flash_success'] ?? $success;
$error   = $_SESSION['flash_error']   ?? $error;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
```

(Keep `$success` / `$error` local variables for the profile-update and password-change paths so they are unchanged.)

### Bottom nav — suppress on profile page

In `public/includes/footer.php`, the existing condition is:

```php
<?php if ($currentPage !== 'admin'): ?>
<?php include __DIR__ . '/bottom_bar.php'; ?>
<?php endif; ?>
```

Change to:

```php
<?php if (!in_array($currentPage, ['admin', 'profile'])): ?>
<?php include __DIR__ . '/bottom_bar.php'; ?>
<?php endif; ?>
```

`$currentPage` is already set in `header.php` as `basename($_SERVER['PHP_SELF'], '.php')`, so no new variable is needed.

### Bottom nav on all other pages

No change. Theme, font, and language toggles via `?toggle_theme=1`, `?toggle_font=1`, `?toggle_lang=1` continue to work on every page that is not `profile` or `admin`.

---

## Acceptance Criteria

```gherkin
Feature: Profile page preferences management

  Background:
    Given Alice is logged in as an authenticated user
    And Alice's current theme is 'dark' and font is 'system'

  # ─── Profile page layout ──────────────────────────────────────────────────

  Scenario: Bottom navigation is hidden on the profile page
    When Alice navigates to /profile.php
    Then the element '.hf-bottom' is not present in the DOM

  Scenario: Preferences card is visible with correct current values
    When Alice navigates to /profile.php
    Then a card with heading matching /Preferences|Præferencer/ is visible
    And the Theme select shows 'dark' as selected
    And the Font select shows 'system' as selected

  # ─── Saving preferences ───────────────────────────────────────────────────

  Scenario: Saving theme and font updates session, cookie, DB, and page class
    Given Alice is on /profile.php
    When she selects 'light' for Theme and 'editorial' for Font
    And she submits the preferences form
    Then the page redirects back to /profile.php
    And a success message is visible
    And body has class 'light'
    And body has class 'font-editorial'
    And the Theme select now shows 'light' as selected
    And the Font select now shows 'editorial' as selected

  Scenario: Preference persists to database and survives re-login
    Given Alice has saved theme='light' via the preferences form
    When Alice logs out and logs back in
    Then body has class 'light'

  Scenario: Preference cookie is updated when preferences are saved via profile form
    Given Alice is on /profile.php
    When she selects 'light' for Theme and submits
    Then the browser cookie 'f1_theme' has value 'light'

  # ─── Public pages unaffected ──────────────────────────────────────────────

  Scenario: Bottom navigation is still visible on all other authenticated pages
    When Alice navigates to /
    Then the element '.hf-bottom' is visible
    When Alice navigates to /races.php
    Then the element '.hf-bottom' is visible
    When Alice navigates to /leaderboard.php
    Then the element '.hf-bottom' is visible

  Scenario: Bottom nav theme toggle still works on non-profile pages
    Given Alice is on / with theme='dark'
    When she visits /?toggle_theme=1
    Then body has class 'light'
    And she is redirected back to /

  # ─── Unauthenticated ──────────────────────────────────────────────────────

  Scenario: Unauthenticated users still see bottom nav on public pages
    Given no user is logged in
    When a visitor loads /
    Then the element '.hf-bottom' is visible
    And it contains the login link

  # ─── Edge cases ───────────────────────────────────────────────────────────

  Scenario: Submitting preferences form with tampered value is sanitised
    Given Alice posts pref_theme='invalid'
    Then setTheme() falls back to default 'dark'
    And no error is shown to the user
```

---

## Test Strategy

### E2E spec — `tests/e2e/09-profile-preferences.spec.js`

Uses the shared `ADMIN_AUTH` storage state (same pattern as `01-smoke.spec.js` Protected pages section). For DB-state assertions, reuse the `getPrefs(email)` helper from `08-preferences.spec.js`.

**Test file structure:**

```
describe('Profile preferences management', serial) {
  beforeAll: global seed → reset Alice/Bob to NULL prefs

  PP1 — bottom nav absent on /profile.php (authenticated)
  PP2 — preferences card visible with correct current values pre-selected
  PP3 — submit light+editorial: assert body classes, select values, flash message
  PP4 — DB updated: getPrefs() → theme='light', font_stack='editorial'
  PP5 — cookie updated: f1_theme=light, f1_font=editorial
  PP6 — re-login after change: body class still 'light'
  PP7 — bottom nav present on / (regression)
  PP8 — bottom nav present on /races.php (regression)
  PP9 — bottom nav theme toggle works on / (regression)
  PP10 — unauthenticated visitor: bottom nav present on /login.php
}
```

### Regression coverage

- `01-smoke.spec.js` "bottom bar visible on authenticated pages" must continue to pass (tests `/` not `/profile.php`, so no change needed).
- `08-preferences.spec.js` must continue to pass without modification.

### Manual checklist (before marking done)

- [ ] Profile page: no bottom nav visible on mobile (375px viewport)
- [ ] Profile page: preferences card keyboard-navigable (Tab → select → Space)
- [ ] After saving preferences, body theme class updates immediately (no flash of old class)
- [ ] On all other pages: bottom nav visible and all 3 toggles functional
- [ ] Language toggle on bottom nav still works on `/races.php` after this change

---

## New Translation Keys

Add to both `'da'` and `'en'` arrays in `public/lang/user.php`, under the `// Profile` section:

| Key | DA | EN |
|---|---|---|
| `preferences` | `'Præferencer'` | `'Preferences'` |
| `preferences_updated` | `'Indstillinger gemt!'` | `'Preferences saved!'` |
| `theme_dark` | `'Mørk'` | `'Dark'` |
| `theme_light` | `'Lys'` | `'Light'` |
| `font_label` | `'Skrifttype'` | `'Font'` |
| `font_system` | `'System'` | `'System'` |
| `font_editorial` | `'Editorial'` | `'Editorial'` |

---

## Files to Change

| File | Change |
|---|---|
| `public/profile.php` | Add `update_preferences` POST action; add Preferences card; add session-flash read at top |
| `public/includes/footer.php` | Add `'profile'` to the bottom bar exclusion condition |
| `public/lang/user.php` | Add 7 new translation keys (both `da` + `en`) |
| `tests/e2e/09-profile-preferences.spec.js` | New E2E spec (PP1–PP10) |
| `docs/testing.md` | Add `09-profile-preferences.spec.js` to the spec inventory table |

**No changes needed to:**
- `public/includes/functions.php` — `setTheme()` / `setFont()` are correct as-is
- `public/includes/header.php` — `$currentPage` variable already set
- `public/includes/bottom_bar.php` — no change to the nav content
- `database/schema.sql` — columns added in parent epic
