# Admin Area Refactor Design

## Goal

Split `admin.php` (1107 lines) into a slim router plus 6 tab partials, fix DELETE-via-GET security issues, and fix meaningful sanitization gaps. No change to the UI or behaviour — purely internal cleanup.

## Architecture

`admin.php` becomes a ~200-line router: auth check, all action handlers, tab-aware data loading, and tab dispatch. HTML for each tab moves to a dedicated partial under `public/includes/admin/`.

```
public/
  admin.php                    ← auth, handlers, data load, tab dispatch
  includes/
    admin/
      races.php                ← races tab HTML
      drivers.php              ← drivers tab HTML
      users.php                ← users tab HTML
      bets.php                 ← bets tab HTML
      invites.php              ← invites tab HTML
      settings.php             ← settings tab HTML
```

`admin.php` internal order:
1. `require` + `requireAdmin()`
2. All POST action handlers (CSRF already validated at top)
3. `$currentTab = $_GET['tab'] ?? 'races'`
4. Tab-aware data loading (see below)
5. `include header.php`
6. Tabs navigation HTML
7. `include includes/admin/$currentTab.php`
8. Shared `toggleForm` JS block
9. `include footer.php`

## Section 1: File split

Each partial receives variables set by `admin.php` and produces only HTML. Partials do not query the DB or handle POST. They access `$lang`, `$currentUser`, `$drivers`, `$driversById`, and the tab-specific variable (`$races`, `$users`, etc.).

## Section 2: Security fixes

### DELETE operations → POST with CSRF

The following 8 actions currently trigger via GET links and have no CSRF protection. All move to POST:

| Action | Handler key |
|---|---|
| Delete driver | `delete_driver` |
| Delete race | `delete_race` |
| Delete user | `delete_user` |
| Delete invite | `delete_invite` |
| Delete bet | `delete_bet` |
| Toggle role | `toggle_role` |
| Toggle competition | `toggle_competition` |
| Resend invite | `resend_invite` |

Each link in the partials becomes a small inline form:

```html
<form method="POST" style="display:inline">
  <?= csrfField() ?>
  <input type="hidden" name="driver_id" value="<?= escape($driver['id']) ?>">
  <button type="submit" name="delete_driver" class="btn btn-danger btn-sm btn-delete"
          data-name="<?= escape($driver['name']) ?>">
    <i class="fas fa-trash"></i>
  </button>
</form>
```

All 8 handlers in `admin.php` change from `isset($_GET['action_key'])` to `isset($_POST['action_key'])`. Redirect `Location` headers after action remain unchanged.

### Sanitization: meaningful fixes only

Two places where bounds are not enforced on update paths, inconsistent with the add paths:

| Location | Current | Fix |
|---|---|---|
| `update_driver` — driver number | `intval($_POST['driver_number'])` | `sanitizeInt($_POST['driver_number'], 1, 99)` |
| `update_settings` — betting window | `intval($_POST['betting_window_hours'])` | `sanitizeInt($_POST['betting_window_hours'], 1, 168)` |

No other sanitization changes — cosmetic trim→sanitizeString differences are not worth the churn since all output already goes through `escape()`.

## Section 3: JS consolidation

The `toggleForm` script (17 lines) is duplicated verbatim in the races tab and drivers tab. Move it once to the bottom of `admin.php` after the tab partial include and before `footer.php`. It attaches to `.toggleForm` elements on load; tabs without those elements are unaffected.

Tab-specific scripts stay in their partials:
- `toggleResetPasswordForm` — `includes/admin/users.php`
- `copyInviteLink` — `includes/admin/invites.php`

## Section 4: Tab-aware data loading

Currently all 6 data queries run on every page load regardless of active tab. After determining `$currentTab`, load only what the active tab needs.

`$drivers` is always loaded (needed by races add/edit form dropdowns and by bets display).

`$settings` is fetched early (before action handlers) because the `delete_bet` handler needs `betting_window_hours` to check whether deletion is allowed.

```php
$settings = getSettings();  // early — needed by delete_bet handler

// ... action handlers ...

$currentTab = $_GET['tab'] ?? 'races';
$drivers = $db->query("SELECT * FROM drivers ORDER BY number")->fetchAll();
$driversById = array_column($drivers, null, 'id');

switch ($currentTab) {
    case 'races':
        $races = $db->query("SELECT * FROM races ORDER BY race_date ASC")->fetchAll();
        break;
    case 'users':
        $users = $db->query("SELECT * FROM users ORDER BY points DESC")->fetchAll();
        break;
    case 'bets':
        $races = $db->query("SELECT * FROM races ORDER BY race_date ASC")->fetchAll();
        $bets  = $db->query("SELECT b.*, u.display_name, u.email, r.name as race_name FROM bets b JOIN users u ON b.user_id = u.id JOIN races r ON b.race_id = r.id ORDER BY b.placed_at DESC")->fetchAll();
        break;
    case 'invites':
        $invites = $db->query("SELECT i.*, u.display_name as created_by_name, u.email as created_by_email FROM invites i JOIN users u ON i.created_by = u.id ORDER BY i.created_at DESC")->fetchAll();
        break;
    case 'settings':
        // $settings already loaded above
        break;
    // drivers tab: only $drivers needed, already loaded
}
```

## Out of scope

- No UI changes
- No new features
- No changes to `functions.php`, `config.php`, `includes/scoring.php`, or `includes/smtp.php`
- No changes to other pages (`races.php`, `leaderboard.php`, etc.)
