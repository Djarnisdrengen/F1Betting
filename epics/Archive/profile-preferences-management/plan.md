# Implementation Plan: Profile Page Preferences Management

Feature doc: `epics/profile-preferences-management/feature.md`

---

## Step 0 — Save plan to feature folder ✅

This file.

---

## Step 1 — Translation keys (`public/lang/user.php`)

Add 7 keys to both the `'da'` and `'en'` arrays, under the `// Profile` comment block.

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

## Step 2 — Hide bottom nav on profile page (`public/includes/footer.php`)

**Line 3.** Change:
```php
<?php if ($currentPage !== 'admin'): ?>
```
To:
```php
<?php if (!in_array($currentPage, ['admin', 'profile'])): ?>
```

`$currentPage` is already `basename($_SERVER['PHP_SELF'], '.php')` set in `header.php` — no new variable needed.

---

## Step 3 — Profile page changes (`public/profile.php`)

### 3a. Flash support (lines 9–10)

Replace:
```php
$success = '';
$error = '';
```
With:
```php
$success = $_SESSION['flash_success'] ?? '';
$error   = '';
unset($_SESSION['flash_success']);
```

### 3b. New POST action — `update_preferences`

Add after the `change_password` branch (before the closing `}`):

```php
} elseif ($action === 'update_preferences') {
    $newTheme = in_array($_POST['pref_theme'] ?? '', ['dark', 'light'])       ? $_POST['pref_theme'] : 'dark';
    $newFont  = in_array($_POST['pref_font']  ?? '', ['system', 'editorial']) ? $_POST['pref_font']  : 'system';
    setTheme($newTheme);
    setFont($newFont);
    $_SESSION['flash_success'] = t('preferences_updated');
    header('Location: profile.php');
    exit;
}
```

### 3c. Preferences card — left column, after Change Password card

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

---

## Step 4 — E2E spec (`tests/e2e/09-profile-preferences.spec.js`)

Uses `ADMIN_AUTH` storage state for authenticated tests. Uses `browser.newContext()` for the unauthenticated test. Reuses `getPrefs(email)` helper pattern from `08-preferences.spec.js`.

| ID | What it asserts |
|---|---|
| PP1 | `.hf-bottom` absent on `/profile.php` (authenticated) |
| PP2 | Preferences card visible; Theme=`dark`, Font=`system` pre-selected |
| PP3 | Submit light+editorial → redirect → body classes + flash message |
| PP4 | `getPrefs()` → `theme='light'`, `font_stack='editorial'` |
| PP5 | Cookies `f1_theme=light`, `f1_font=editorial` |
| PP6 | Re-login: body still `light` |
| PP7 | `.hf-bottom` visible on `/` (regression) |
| PP8 | `.hf-bottom` visible on `/races.php` (regression) |
| PP9 | `/?toggle_theme=1` toggles body class (regression) |
| PP10 | Unauthenticated: `.hf-bottom` visible on `/`, contains login link |

PP3–PP6 run in `test.describe.serial`. PP7–PP10 run independently.

---

## Step 5 — Docs (`docs/testing.md`)

Add `09-profile-preferences.spec.js` to the E2E spec inventory table.

---

## Files changed

| File | Change |
|---|---|
| `epics/profile-preferences-management/plan.md` | This file |
| `public/lang/user.php` | +7 translation keys (DA + EN) |
| `public/includes/footer.php` | Add `'profile'` to bottom-bar exclusion |
| `public/profile.php` | Flash support + `update_preferences` action + Preferences card |
| `tests/e2e/09-profile-preferences.spec.js` | New spec PP1–PP10 |
| `docs/testing.md` | Add spec to table |

**No changes to:** `functions.php`, `header.php`, `bottom_bar.php`, `schema.sql`.

---

## Verification

```bash
npm run test:smoke
DEPLOY_ENV=test npx playwright test tests/e2e/09-profile-preferences.spec.js --config tests/playwright.config.js
DEPLOY_ENV=test npx playwright test tests/e2e/01-smoke.spec.js --config tests/playwright.config.js
DEPLOY_ENV=test npx playwright test tests/e2e/08-preferences.spec.js --config tests/playwright.config.js
```
