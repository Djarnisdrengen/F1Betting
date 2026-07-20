# Patterns & Best Practices

## Contents

- [PHP Page Structure](#php-page-structure)
- [Input Sanitization and Output Escaping](#input-sanitization-and-output-escaping)
- [CSRF Protection](#csrf-protection)
- [Token Comparison (constant-time)](#token-comparison-constant-time)
- [Auth Guards](#auth-guards)
- [Reusable Include Pattern (qualifying-display.php)](#reusable-include-pattern-qualifying-displayphp)
- [Config Constants](#config-constants)
- [php-config.js Bridge](#php-configjs-bridge)
- [Translation](#translation)
- [Preferred Language (authenticated users)](#preferred-language-authenticated-users)
- [Helper Functions for Common Queries](#helper-functions-for-common-queries)
- [UUID Primary Keys](#uuid-primary-keys)
- [Betting Status](#betting-status)
- [Password Handling](#password-handling)
- [Logging](#logging)
- [Security Headers](#security-headers)
- [Code Style](#code-style)
- [UI Toggle Conventions](#ui-toggle-conventions)

---

Conventions used throughout the codebase. Follow these when adding new features.

---

## PHP Page Structure

Every page follows the same opening sequence:

```php
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();       // or requireAdmin() for admin pages

$db = getDB();
$currentUser = getCurrentUser();
$settings = getSettings();
$lang = getLang();
```

`config.php` on the server includes `config.shared.php`, which starts the session and sets security headers. `functions.php` is loaded by `config.shared.php` and available from that point on.

---

## Input Sanitization and Output Escaping

Sanitize at the point where input enters the system. Escape at the point where data leaves to HTML output. Never mix these.

```php
// Entry points (user input, $_POST, $_GET)
$email    = sanitizeEmail($_POST['email'] ?? '');      // validate + lowercase
$name     = sanitizeString($_POST['name'] ?? '');      // trim + htmlspecialchars
$limit    = sanitizeInt($_POST['limit'] ?? 10, 1, 100); // parse + clamp

// Database: always use prepared statements
$stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);

// Output: always escape
echo escape($user['display_name']);
echo escape($race['name']);
```

**`trim()` vs `sanitizeString()`:** Values stored in the database and later echoed through `escape()` should use `trim()` only on the way in — `sanitizeString()` HTML-encodes the value, which would cause double-encoding when `escape()` is called on output. Use `sanitizeString()` only when the value is displayed directly without a subsequent `escape()` call.

---

## CSRF Protection

Every HTML form must include the CSRF field, and every POST handler must validate it before doing anything.

```php
// In the form template
<form method="POST">
    <?= csrfField() ?>
    ...
</form>

// At the top of the POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();   // dies with 403 if token missing or wrong
    ...
}
```

`requireCsrf()` is a one-liner that calls `validateCsrfToken()` and terminates the request on failure.

---

## Token Comparison (constant-time)

Any endpoint that trusts a bare token instead of a session (cron scripts, backup/seed tools, the e2e-only password-reset backdoor) must compare it with `hash_equals()`, never `===`/`==`. A naive string compare leaks timing information an attacker can use to guess the token byte-by-byte.

Since F6, the token itself should also travel via the `Authorization: Bearer` header (`getBearerToken()` in `functions.php`), not a `?token=` query string — query strings land in web-server/proxy access logs and `Referer` headers. `?token=` is only still acceptable for a client that structurally cannot send a custom header (e.g. a browser-pasted URL with no request-building client behind it — see `seed_f1_admin.php`), and even then only `hash_equals()`-compared.

```php
// Good
$token = getBearerToken() ?? '';
if (!hash_equals(CRON_SECRET, $token)) {
    die('Forbidden');
}

// Bad — token exposed in URLs/logs, and a timing side-channel besides
if ($_GET['token'] !== CRON_SECRET) {
    die('Forbidden');
}
```

`hash_equals()` is also used for OTP/TOTP code comparison in `includes/mfa.php` and the CSRF check in `functions.php`. This became the codebase-wide convention during the 2026-07-05 security review — see [security-review-log.md](security-review-log.md#2026-07-05--ad-hoc-review-of-token-gated-endpoints-and-cron-auth-f1f12).

---

## Auth Guards

```php
requireLogin();   // redirects to login.php if session has no user
requireAdmin();   // redirects to index.php if user is not role='admin'
```

Place these immediately after loading config and functions, before any output.

---

## Reusable Include Pattern (qualifying-display.php)

Shared display blocks use a caller-sets-variables include pattern instead of functions, to avoid the overhead of passing large arrays and to keep template logic in template files.

```php
// Caller sets variables, then includes
$_qd_data  = $race;                                      // data source
$_qd_keys  = ['quali_p1', 'quali_p2', 'quali_p3'];      // which fields to read
$_qd_label = t('qualifying');                            // section label
// $_qd_style = 'margin-top: 1rem;';                     // optional style override
include __DIR__ . '/includes/qualifying-display.php';
```

The include reads `$_qd_data[$_qd_keys[0..2]]`, renders the P1/P2/P3 badges, then `unset()`s all `$_qd_*` variables to keep the scope clean for the next caller.

Use this pattern when the same visual block appears in more than two templates.

---

## Config Constants

All configuration is accessed via PHP `define()` constants. Never read from `$_ENV` or `getenv()` in PHP — those only work if the environment variable was explicitly set. Constants are always available after `config.php` is loaded.

```php
// Good
$secret = CRON_SECRET;
$url    = SITE_URL;

// Bad — do not do this
$secret = $_ENV['CRON_SECRET'];
$secret = getenv('CRON_SECRET');
```

---

## php-config.js Bridge

Node.js build and test scripts cannot include PHP files. The bridge `build-deploy/php-config.js` extracts string constants from `config.*.php` using regex:

```js
const { readPhpConfig } = require('./build-deploy/php-config');
const cfg = readPhpConfig('test');  // or 'live'
// cfg.siteUrl, cfg.adminEmail, cfg.adminPassword,
// cfg.integrationSeedToken, cfg.cronSecret
```

The parser only handles string defines (`define('KEY', 'value')` with single quotes). Numeric defines and boolean/null defines are not extracted — read them from process.env or hardcode them in the script if needed.

---

## Translation

All user-visible strings go through `t($key)`. Never hardcode strings in English or Danish in PHP templates.

```php
// Good
echo t('place_bet');
echo t('no_upcoming_races');

// Bad
echo 'Place bet';
echo 'Der er ingen kommende løb';
```

Add new strings to both `public/lang/user.php` (or `admin.php`) under `'da'` and `'en'` keys.

Email functions receive the recipient's language explicitly and must pass it to `t()`:

```php
// In email functions — always pass $lang, never rely on the session
$subject = sprintf(t('email_betting_open_subject', $lang), $appName, $raceName);
```

---

## Preferred Language (authenticated users)

Authenticated users have a `language` column in the `users` table that persists their preferred language across sessions.

**Reading:** `getCurrentUser()` returns `$currentUser['language']`. Use it when rendering user-specific UI (e.g. the profile language selector pre-selection).

**Writing:** Always go through `setLang($lang)` — it updates both `$_SESSION['lang']` and `users.language` in one call.

```php
// Correct — updates session + DB atomically
setLang('en');

// Wrong — session only, DB not updated
$_SESSION['lang'] = 'en';
```

**On login:** `login.php` loads `$user['language']` from the database and writes it to `$_SESSION['lang']` so the preference takes effect immediately without an extra `setLang()` call.

**On logout:** `logout.php` preserves `$_SESSION['lang']` in the new anonymous session so public pages stay in the user's language after signing out.

---

## Helper Functions for Common Queries

Shared database queries live in `functions.php`, not inline in individual pages.

```php
[$drivers, $driversById] = fetchDrivers($db);          // sorted by last name
[$drivers, $driversById] = fetchDrivers($db, 'number'); // sorted by car number
$races = getRaces($db);                                 // all races, ordered by date
$betsByRace = getBetsByRace($db);                       // keyed by race_id
```

If you need to add a query used in more than one page, add a function to `functions.php`.

---

## UUID Primary Keys

All main entity tables use `VARCHAR(36)` UUID primary keys. Generate them in PHP with `generateUUID()`:

```php
$id = generateUUID();
$stmt = $db->prepare("INSERT INTO bets (id, user_id, race_id, ...) VALUES (?, ?, ?, ...)");
$stmt->execute([$id, $userId, $raceId, ...]);
```

Never use `AUTO_INCREMENT` integers for entities that are exposed in URLs or API responses.

---

## Betting Status

Race state (pending / open / closed / completed) is always determined by `getBettingStatus($race, $settings)`. Never replicate this logic inline.

```php
$status = getBettingStatus($race, $settings);
// $status['status']  → 'pending' | 'open' | 'closed' | 'completed'
// $status['label']   → translated display string
// $status['class']   → CSS class for the badge
```

- `pending`: betting window hasn't opened yet (> `betting_window_hours` before race)
- `open`: within the window and no result yet
- `closed`: race time has passed but no result entered
- `completed`: result is in the database

---

## Password Handling

Passwords are hashed with bcrypt plus a server-side pepper constant:

```php
$hash = hashPassword($plaintextPassword);    // store this
$ok   = verifyPassword($plaintextPassword, $hash);  // check on login
```

Never store or log plaintext passwords. Never compare password strings directly.

---

## Logging

```php
logToFile(APP_LOG_FILE, 'Something happened: ' . $detail);
logToFile(MAIL_LOG_FILE, 'Email sent to ' . $email);
```

`logToFile()` prepends a timestamp and rotates the file at 200 KB. Use the constants defined in `config.shared.php` rather than hardcoding paths.

---

## Security Headers

Security headers are set once in `config.shared.php` (HSTS, X-Frame-Options, etc.). The CSP header with a per-request nonce is set in `public/includes/header.php`. Do not set security headers again in individual pages — it causes duplicate headers.

---

## Code Style

- PHP: PSR-12 via `.php-cs-fixer.php`. Auto-format on save if configured in VSCode.
- PHP: single quotes for strings unless interpolation is needed.
- JS: no framework — plain Node.js in build scripts, plain browser JS in `app.js`.
- SQL: uppercase keywords, lowercase identifiers, prepared statements only.

---

## UI Toggle Conventions

The three bottom-nav preference toggles (theme, language, font) display an icon and label representing the **current active state**, not the action that clicking will perform.

| Toggle | Current state | Icon |
|---|---|---|
| Theme | Dark | `fa-moon` |
| Theme | Light | `fa-sun` |
| Language | Danish | Globe (`fa-globe`) + label `DA` |
| Language | English | Globe (`fa-globe`) + label `EN` |
| Font | System | Font (`fa-font`) + label `SYS` |
| Font | Editorial | Font (`fa-font`) + label `EDIT` |

---

## Challenges Accent Colour

Everything that marks a surface as "Challenges" (Rumor or Not / Trivia / Duels — not the core podium-betting game) uses **Telemetry Blue** — `var(--f1-accent-challenges)` (`#2472e8`, with `-light`/`-dark` variants) — instead of the site's core brand red (`var(--f1-red)`). This covers Challenges page-title icons, the burger-drawer and bottom-bar Challenges nav items, and Challenges' primary buttons.

Because `.btn-primary`, `.hf-tab-btn`, `.hf-pref-btn`, `.text-accent`, and the admin `.admin-*` tab classes are **shared with non-Challenges pages** (core `profile.php`, `bet.php`, `admin.php`, etc.), the blue is applied via additive, Challenges-only selectors rather than by editing those shared rules — so a shared class's definition always stays red/neutral, and only an explicitly-opted-in element goes blue:

- Buttons: add the `btn-accent-challenges` modifier class alongside `btn btn-primary` (`.btn-primary.btn-accent-challenges` in `style.css`) — never repoint `.btn-primary` itself.
- Tab/preference toggles on `challenges-profile.php`: scoped via the `.hf-arena-base` ancestor (`.hf-arena-base .hf-tab-btn.active`, etc.) — `.hf-arena-base` only exists on Challenges pages, so the override can't reach core `profile.php`'s identical-looking tabs.
- Icons: inline `color:var(--f1-accent-challenges)`, same pattern the icon already used for `var(--f1-red)`.

**Deliberately excluded** (kept as their existing colour, not part of this accent): semantic right/wrong-answer feedback (Rumor-or-Not's red/green guess buttons, Trivia's correct/incorrect icons, duel win/loss colouring), gold CP/streak/points indicators, and the entire `admin-challenges.php` control room (its tab/badge classes are shared verbatim with core `admin.php`; recolouring them would bleed into non-Challenges admin screens).

Any new preference toggle added in future must follow the same current-state convention.
