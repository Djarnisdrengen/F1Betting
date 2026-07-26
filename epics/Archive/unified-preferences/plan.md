# Plan: Unified Preferences — Anonymous & Authenticated Sessions

## Context

The F1 site has three bottom-nav toggles (theme / language / font). Today theme and font are session-only (lost on logout/expiry) and are never written to the user profile. Language already has the full pattern (session + DB + survives login). The epic closes the gap:

- Add persistent preference **cookies** (`f1_theme`, `f1_font`) so anonymous visitors get device persistence without localStorage.
- Add **DB columns** (`theme`, `font_stack` on `users`) so authenticated users carry prefs across devices.
- Wire **login** to inherit session prefs into profile on first login, or load profile prefs on returning login.
- Fix the **theme icon bug** (currently shows sun when dark — inverted).
- Change the **defaults** for new visitors: `dark` (was `light`) and `system` (was `editorial`).
- Language already works correctly — **do not duplicate its pattern**, just extend the same shape to theme and font.

Decisions locked: cookies (not localStorage), last-write-wins on concurrent devices, new defaults apply to new visitors only.

---

## Files to change

| File | Change |
|---|---|
| `database/schema.sql` | Add `theme` and `font_stack` columns to users |
| `public/includes/functions.php` | Update `getTheme`/`setTheme`, add `getFont`/`setFont`, update `getCurrentUser` SELECT |
| `public/includes/header.php` | Replace inline font session logic with `getFont()`/`setFont()`, use `setFont()` in toggle handler |
| `public/includes/bottom_bar.php` | Fix theme icon (line 23) |
| `public/login.php` | Load profile prefs on login; seed DB if NULL (AC4/AC5) |
| `public/tools/test-seed.php` | Add `get_prefs` action for E2E DB assertions |
| `tests/e2e/08-preferences.spec.js` | New spec covering AC1–AC11 |
| `docs/patterns.md` | Add "UI toggle conventions" section (AC12) |

`logout.php` — **no change needed**. Cookies are set independently of the session; when `session_unset()` fires, cookies remain. `getTheme()`/`getFont()` fallback chain catches them on the next request.

---

## Step-by-step implementation

### 1. DB migration (`database/schema.sql`)

Add to the `users` table definition (after `language` column):

```sql
theme      ENUM('dark','light')        NULL DEFAULT NULL,
font_stack ENUM('system','editorial')  NULL DEFAULT NULL,
```

`NULL` = no profile pref yet (AC4 seeding path). An explicit value means the user chose it.

Also add the migration comment block:
```sql
-- Migration: add theme and font_stack to users (run once on each environment)
-- ALTER TABLE users
--   ADD COLUMN theme      ENUM('dark','light')       NULL DEFAULT NULL,
--   ADD COLUMN font_stack ENUM('system','editorial')  NULL DEFAULT NULL;
```

---

### 2. `public/includes/functions.php`

#### 2a. Update `getTheme()` — add cookie fallback + fix default

```php
function getTheme() {
    return $_SESSION['theme'] ?? $_COOKIE['f1_theme'] ?? 'dark';
}
```

(Default changes from `'light'` to `'dark'`.)

#### 2b. Update `setTheme()` — add cookie write + DB write (mirrors `setLang()`)

```php
function setTheme($theme) {
    $valid = in_array($theme, ['dark', 'light']) ? $theme : 'dark';
    $_SESSION['theme'] = $valid;
    setcookie('f1_theme', $valid, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!empty($_SESSION['user_id'])) {
        getDB()->prepare("UPDATE users SET theme = ? WHERE id = ?")
               ->execute([$valid, $_SESSION['user_id']]);
    }
}
```

#### 2c. Add `getFont()` and `setFont()` (new — same shape as getTheme/setTheme)

```php
function getFont() {
    return $_SESSION['font_stack'] ?? $_COOKIE['f1_font'] ?? 'system';
}

function setFont($font) {
    $valid = in_array($font, ['system', 'editorial']) ? $font : 'system';
    $_SESSION['font_stack'] = $valid;
    setcookie('f1_font', $valid, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (!empty($_SESSION['user_id'])) {
        getDB()->prepare("UPDATE users SET font_stack = ? WHERE id = ?")
               ->execute([$valid, $_SESSION['user_id']]);
    }
}
```

#### 2d. Update `getCurrentUser()` SELECT to include new columns

```php
$stmt = $db->prepare(
    "SELECT id, email, display_name, role, points, stars, created_at,
            in_competition, language, theme, font_stack, last_login
     FROM users WHERE id = ?"
);
```

---

### 3. `public/includes/header.php`

#### 3a. Font toggle handler — replace inline session assignment with `setFont()`

Replace:
```php
$_SESSION['font_stack'] = ($_SESSION['font_stack'] ?? 'editorial') === 'editorial' ? 'system' : 'editorial';
```
With:
```php
setFont(getFont() === 'system' ? 'editorial' : 'system');
```

#### 3b. `$fontStack` assignment at bottom of toggle block — replace with helper

Replace:
```php
$fontStack = $_SESSION['font_stack'] ?? 'editorial';
```
With:
```php
$fontStack = getFont();
```

No other changes to `header.php` needed — `getTheme()` already feeds `$theme`.

---

### 4. `public/includes/bottom_bar.php` — fix theme icon (AC11)

Line 23. Replace:
```php
<i class="fas <?= $theme === 'dark' ? 'fa-sun' : 'fa-moon' ?>"></i>
```
With (current-state convention — moon when dark, sun when light):
```php
<i class="fas <?= $theme === 'dark' ? 'fa-moon' : 'fa-sun' ?>"></i>
```

---

### 5. `public/login.php` — profile sync on login (AC4 / AC5)

Insert **before** `$_SESSION['user_id'] = $user['id']`, capture pre-login anonymous values:

```php
$anonTheme = $_SESSION['theme'] ?? $_COOKIE['f1_theme'] ?? 'dark';
$anonFont  = $_SESSION['font_stack'] ?? $_COOKIE['f1_font'] ?? 'system';
```

Then replace the existing session-set block:
```php
$_SESSION['user_id'] = $user['id'];
$_SESSION['lang']    = in_array($user['language'] ?? '', ['da', 'en']) ? $user['language'] : 'da';
session_regenerate_id(true);
$db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
```
With:
```php
$_SESSION['user_id'] = $user['id'];
$_SESSION['lang']    = in_array($user['language'] ?? '', ['da', 'en']) ? $user['language'] : 'da';
session_regenerate_id(true);
$db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

// AC4: no profile prefs yet → seed from anonymous session; AC5: profile wins
setTheme($user['theme'] ?? $anonTheme);
setFont($user['font_stack'] ?? $anonFont);
```

`setTheme()`/`setFont()` now have `$_SESSION['user_id']` set, so they automatically write to DB when the profile value was NULL (seeding path) — or write back the same profile value (returning login, harmless).

---

### 6. `public/tools/test-seed.php` — add `get_prefs` action

Add a new action alongside the existing ones (guarded by existing `APP_ENV` + token checks):

```php
if ($action === 'get_prefs') {
    $email = $_GET['email'] ?? '';
    $stmt  = $db->prepare(
        "SELECT theme, font_stack, language FROM users WHERE email = ?"
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    jsonOk($row ?: ['error' => 'user not found']);
}
```

Usage from E2E tests:
`GET /tools/test-seed.php?token=...&action=get_prefs&email=alice@test.local`
→ `{"theme":"dark","font_stack":"system","language":"da"}`

---

### 7. `tests/e2e/08-preferences.spec.js` — new spec

Structure (mirrors 05-profile.spec.js pattern — shared context, `beforeAll` seed call):

```
beforeAll: global seed (resets Alice/Bob/Charlie with NULL theme/font_stack)

AC1  — fresh context (no cookies), assert body has class 'dark' + 'font-system', assert f1_theme cookie set
AC2  — set f1_theme=light cookie via context.addCookies(), load page, assert body has class 'light'
AC3  — toggle theme via ?toggle_theme=1, assert cookie updated, reload, assert persists
AC4  — login as Alice (NULL prefs), assert UI unchanged, GET get_prefs → assert DB now non-NULL
AC5  — login as Bob (set theme='light', font_stack='editorial' via direct seed UPDATE before test),
        assert body class overridden to 'light' + 'font-editorial', assert cookies updated
AC6  — login, toggle theme, GET get_prefs → assert DB updated, logout, login, assert body class matches DB
AC7  — login, logout, assert f1_theme + f1_font cookies still present in context
AC8  — continuation of AC7: navigate to /races.php, assert body class unchanged
AC9  — after AC7, snapshot context.storageState(), create new context from snapshot,
        load /index.php, assert body class matches pre-logout prefs
AC10 — after logout, overwrite f1_theme cookie to 'light', reload, assert body class = 'light' (last write wins)
AC11 — with dark body class: assert .hf-bb-item i.fa-moon visible; toggle to light: assert i.fa-sun visible
```

Seed Bob's explicit prefs (AC5) via a direct UPDATE in `beforeAll` using the existing seed helper pattern. Use the `get_prefs` endpoint for all DB-state assertions.

---

### 8. `docs/patterns.md` — add UI toggle conventions section (AC12)

Add under a new heading:

```markdown
## UI toggle conventions

All three bottom-nav preference toggles (theme, language, font) display an icon and label
representing the **current active state**, not the action that clicking will perform.

| Toggle | Current state | Icon |
|---|---|---|
| Theme | Dark | `fa-moon` |
| Theme | Light | `fa-sun` |
| Language | Danish | Globe (`fa-globe`) + label `DA` |
| Font | System | Font (`fa-font`) + label `SYS` |
| Font | Editorial | Font (`fa-font`) + label `EDIT` |

Any new preference toggle added in future must follow the same current-state convention.
```

---

### 9. `docs/architecture.md` — update Localisation & Theme section + users schema table

#### 9a. Users schema table — add missing preference columns

The table under **Database Schema → users** currently omits `language`, `theme`, and `font_stack`. Add them:

| Column | Type | Notes |
|---|---|---|
| language | VARCHAR(2) | `'da'` or `'en'`, default `'da'` |
| theme | ENUM('dark','light') | NULL = no profile pref yet |
| font_stack | ENUM('system','editorial') | NULL = no profile pref yet |

#### 9b. Localisation & Theme section — replace with unified preference model

The current text says *"Theme and colour palette are session-only."* This is no longer true after this epic. Replace the entire **Localisation & Theme** section with:

```markdown
## Localisation & Theme

Three user preferences are exposed via the bottom-nav toggles: **language**, **theme**, and **font**.
Each preference is stored in three places depending on the user's state:

| Store | When written | Purpose |
|---|---|---|
| PHP session (`$_SESSION`) | On every `set*()` call | Runtime source of truth for the current request |
| Preference cookie (`f1_theme`, `f1_font`) | On every `setTheme()` / `setFont()` call | Device persistence for anonymous visitors and across sessions |
| DB (`users.theme`, `users.font_stack`, `users.language`) | When authenticated | Cross-device persistence, survives login on any device |

**Resolution order** (first match wins):
1. PHP session (already populated this request)
2. Preference cookie (anonymous returning visitor, or post-logout)
3. System default (`dark` / `da` / `system`)

**On login:**
- If the user's DB columns are NULL (first login), current session prefs are written to the profile.
- If the DB columns have values (returning user), those values override the session and cookies on this device.

**On logout:**
- `session_unset()` clears the session. The preference cookies remain untouched — they were kept in sync by every `setTheme()` / `setFont()` call during the session. The next anonymous page load picks them up via the cookie fallback.

**Helper functions** (all in `public/includes/functions.php`):
- `getTheme()` / `setTheme($theme)` — session → cookie → default `'dark'`
- `getFont()` / `setFont($font)` — session → cookie → default `'system'`
- `getLang()` / `setLang($lang)` — session → default `'da'`; language has no preference cookie (it already survives via DB on login and explicit session save on logout)

Toggle redirects (`?toggle_theme=1`, `?toggle_lang=1`, `?toggle_font=1`) are handled in `public/includes/header.php` and preserve existing query parameters on redirect.
```

---

### 10. `docs/testing.md` — add `08-preferences.spec.js` entry

Add to the E2E spec inventory table and add a section describing the spec's coverage and the `get_prefs` test-seed action:

```markdown
### 08-preferences.spec.js

Covers the full preference lifecycle: new-visitor defaults (AC1), returning anonymous visitor via
cookie (AC2–AC3), first-login profile seeding (AC4), returning-login profile override (AC5),
authenticated in-session sync (AC6), logout continuity (AC7–AC9), last-write-wins (AC10), and
theme icon correctness (AC11).

**Test-seed actions used:** `get_prefs` — returns `{theme, font_stack, language}` from the DB for
a given email. Used to assert server-side state without re-logging in.

GET `/tools/test-seed.php?token=...&action=get_prefs&email=alice@test.local`
→ `{"theme":"dark","font_stack":"system","language":"da"}`
```

---

## Verification

### Manual (before marking DoD complete)
- [ ] Load site with no cookies on slow 3G (Chrome DevTools throttle). Body class `dark font-system` must be correct on first visible paint — no flash.
- [ ] Toggle each preference while anonymous, close browser, reopen, verify prefs persist.
- [ ] Log in with a fresh account (NULL profile prefs): verify UI does not change, DB now has values.
- [ ] Log in with an account that has `theme = 'light'` in DB while anonymous session has `dark`: verify UI switches to `light`.
- [ ] Theme icon: dark mode shows moon, light mode shows sun.

### Automated
```bash
npm run test:smoke                  # fast sanity check post-deploy
DEPLOY_ENV=test npx playwright test tests/e2e/08-preferences.spec.js --config tests/playwright.config.js
npm run test:e2e:test               # full suite regression
```

### DB migration (run on each environment after deploy)
```sql
ALTER TABLE users
  ADD COLUMN theme      ENUM('dark','light')       NULL DEFAULT NULL,
  ADD COLUMN font_stack ENUM('system','editorial')  NULL DEFAULT NULL;
```
Run via phpMyAdmin on Simply.com (test first, then live). Existing users get NULL → AC4 path fires on their next login, seeding their profile from whatever is in their session/cookie.
