# Admin Area Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split admin.php (1107 lines) into a slim router + 6 tab partials, fix 8 DELETE-via-GET security issues, and enforce integer bounds in 2 update handlers.

**Architecture:** admin.php keeps all action handlers and becomes a ~220-line router. HTML for each tab moves to `public/includes/admin/*.php`. PHP's `include` shares scope automatically — partials access variables set by admin.php without any explicit passing. Security: 8 GET-based mutations become inline POST forms with CSRF. Two sanitization fixes add bounds checking to update_driver and update_settings.

**Tech Stack:** PHP 8+, PDO/MySQL, `csrfField()` from config.php, `sanitizeInt()` from functions.php.

---

## File map

| File | Change |
|---|---|
| `public/admin.php` | Modify — shrinks from 1107 to ~220 lines |
| `public/includes/admin/races.php` | Create |
| `public/includes/admin/drivers.php` | Create |
| `public/includes/admin/users.php` | Create |
| `public/includes/admin/bets.php` | Create |
| `public/includes/admin/invites.php` | Create |
| `public/includes/admin/settings.php` | Create |

---

### Task 1: Create partial stubs and wire include dispatcher in admin.php

**Files:**
- Create: `public/includes/admin/races.php`, `drivers.php`, `users.php`, `bets.php`, `invites.php`, `settings.php`
- Modify: `public/admin.php`

- [ ] **Step 1: Create the directory and six stub files**

```bash
mkdir -p public/includes/admin
for tab in races drivers users bets invites settings; do
  echo "<?php // $tab tab ?>" > "public/includes/admin/$tab.php"
done
```

- [ ] **Step 2: Replace all six tab if-blocks in admin.php with the include dispatcher**

In `public/admin.php`, delete from the comment `<!-- RACES TAB -->` (line 443) through the last `<?php endif; ?>` before `</div>` (line 1103 — end of settings tab). Replace with:

```php
    <?php
    $allowedTabs = ['races', 'drivers', 'users', 'bets', 'invites', 'settings'];
    if (in_array($currentTab, $allowedTabs)) {
        include __DIR__ . "/includes/admin/{$currentTab}.php";
    }
    ?>
```

The closing `</div>` for `.tabs-container` (line 1104) and the footer include (line 1106) stay as-is.

- [ ] **Step 3: Verify the page loads with empty tabs**

```bash
npm run deploy:test
```

Open `https://www.hpovlsen.dk/admin.php`. Click each tab. Each should show the navigation bar with empty content below — no PHP errors, no white screen.

- [ ] **Step 4: Commit**

```bash
git add public/admin.php public/includes/admin/
git commit -m "refactor: add admin tab partial stubs and include dispatcher"
```

---

### Task 2: Move races tab HTML to partial

**Files:**
- Modify: `public/admin.php` (delete lines 443–594)
- Modify: `public/includes/admin/races.php`

- [ ] **Step 1: Populate races.php**

Copy everything between `<?php if ($currentTab === 'races'): ?>` and its `<?php endif; ?>` (admin.php lines 445–593, not including the `if`/`endif` tags themselves) into `public/includes/admin/races.php`.

The file should begin with the card for the Add Race form and end with the `</script>` closing the toggleForm block.

`public/includes/admin/races.php` (full content):

```php
<div class="card mb-2" id="add-race-form">
    <div class="card-header collapsible-header toggleForm" data-link="race-form-body" id="race-form-header">
        <h3><i class="fas fa-plus-circle text-accent"></i> <?= $lang === 'da' ? 'Tilføj Løb' : 'Add Race' ?></h3>
        <i class="fas fa-chevron-down toggle-icon"></i>
    </div>
    <div id="race-form-body" class="collapsible-form">
        <div class="card-body">
            <form method="POST">
            <?= csrfField() ?>
                <div class="grid grid-2 mb-2">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label"><?= t('name') ?></label>
                        <input type="text" name="race_name" class="form-input" required placeholder="Monaco Grand Prix">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label"><?= t('location') ?></label>
                        <input type="text" name="race_location" class="form-input" required placeholder="Monte Carlo">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label"><?= t('race_date') ?></label>
                        <input type="date" name="race_date" class="form-input" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label"><?= t('race_time') ?> (CET)</label>
                        <input type="time" name="race_time" class="form-input" required>
                    </div>
                </div>
                <div class="grid grid-3 mb-2">
                    <?php foreach (['quali_p1', 'quali_p2', 'quali_p3'] as $i => $key): ?>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Quali P<?= $i + 1 ?></label>
                            <select name="<?= $key ?>" class="form-select">
                                <option value=""><?= t('select_driver') ?></option>
                                <?php foreach ($drivers as $d): ?>
                                    <option value="<?= $d['id'] ?>"><?= escape($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" name="add_race" class="btn btn-primary"><i class="fas fa-plus"></i> <?= t('add') ?></button>
            </form>
        </div>
    </div>
</div>

<?php foreach ($races as $race): ?>
    <div class="card mb-1 <?= isset($_GET['edit']) && $_GET['edit'] === $race['id'] ? 'edit-form-active' : '' ?>" id="race-<?= escape($race['id']) ?>">
        <div class="card-body">
            <div class="flex items-center justify-between mb-1">
                <div>
                    <strong><?= escape($race['name']) ?></strong>
                    <br><small class="text-muted"><?= escape($race['location']) ?> - <?= escape($race['race_date']) ?> <?= escape(substr($race['race_time'], 0, 5)) ?> CET</small>
                </div>
                <div class="flex gap-1">
                    <a href="?tab=races&edit=<?= escape($race['id']) ?>#race-<?= escape($race['id']) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
                    <a href="?tab=races&delete_race=<?= escape($race['id']) ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($race['name']) ?>"><i class="fas fa-trash"></i></a>
                </div>
            </div>
            <?php if ($race['quali_p1']): ?>
                <small class="text-muted"><?= t('qualifying') ?>: <?= escape($driversById[$race['quali_p1']]['name'] ?? '?') ?>, <?= escape($driversById[$race['quali_p2']]['name'] ?? '?') ?>, <?= escape($driversById[$race['quali_p3']]['name'] ?? '?') ?></small>
            <?php endif; ?>
            <?php if ($race['result_p1']): ?>
                <br><small class="text-accent"><?= t('results') ?>: <?= escape($driversById[$race['result_p1']]['name'] ?? '?') ?>, <?= escape($driversById[$race['result_p2']]['name'] ?? '?') ?>, <?= escape($driversById[$race['result_p3']]['name'] ?? '?') ?></small>
            <?php endif; ?>
        </div>
        <?php if (isset($_GET['edit']) && $_GET['edit'] === $race['id']): ?>
            <div class="card-body" style="border-top: 1px solid var(--border-color); background: var(--bg-hover);">
                <form method="POST">
                <?= csrfField() ?>
                    <input type="hidden" name="race_id" value="<?= escape($race['id']) ?>">
                    <div class="grid grid-2 mb-2">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label"><?= t('name') ?></label>
                            <input type="text" name="race_name" class="form-input" value="<?= escape($race['name']) ?>" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label"><?= t('location') ?></label>
                            <input type="text" name="race_location" class="form-input" value="<?= escape($race['location']) ?>" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label"><?= t('race_date') ?></label>
                            <input type="date" name="race_date" class="form-input" value="<?= escape($race['race_date']) ?>" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label"><?= t('race_time') ?> (CET)</label>
                            <input type="time" name="race_time" class="form-input" value="<?= escape($race['race_time']) ?>" required>
                        </div>
                    </div>
                    <label class="form-label"><?= t('qualifying') ?></label>
                    <div class="grid grid-3 mb-2">
                        <?php foreach (['quali_p1', 'quali_p2', 'quali_p3'] as $i => $key): ?>
                            <select name="<?= $key ?>" class="form-select">
                                <option value="">P<?= $i + 1 ?></option>
                                <?php foreach ($drivers as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $race[$key] === $d['id'] ? 'selected' : '' ?>><?= escape($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endforeach; ?>
                    </div>
                    <label class="form-label"><?= t('results') ?></label>
                    <div class="grid grid-3 mb-2">
                        <?php foreach (['result_p1', 'result_p2', 'result_p3'] as $i => $key): ?>
                            <select name="<?= $key ?>" class="form-select">
                                <option value="">P<?= $i + 1 ?></option>
                                <?php foreach ($drivers as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $race[$key] === $d['id'] ? 'selected' : '' ?>><?= escape($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex gap-1">
                        <button type="submit" name="update_race" class="btn btn-primary"><?= t('save') ?></button>
                        <a href="?tab=races" class="btn btn-secondary"><?= t('cancel') ?></a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function() {
    const divs = document.querySelectorAll('.toggleForm');
    divs.forEach(div => {
        div.addEventListener('click', function() {
            toggleForm(this.getAttribute('data-link'));
        });
    });
});
function toggleForm(formId) {
    const form = document.getElementById(formId);
    const header = form.previousElementSibling;
    form.classList.toggle('expanded');
    header.classList.toggle('expanded');
}
</script>
```

- [ ] **Step 2: Delete the races tab block from admin.php**

In `public/admin.php`, delete the comment `<!-- RACES TAB -->` and the entire `<?php if ($currentTab === 'races'): ?>` block including its closing `<?php endif; ?>`.

- [ ] **Step 3: Verify races tab**

```bash
npm run deploy:test
```

Navigate to `https://www.hpovlsen.dk/admin.php` (races tab is default). Confirm: race list shows, Add Race collapsible opens/closes, inline edit form works.

- [ ] **Step 4: Commit**

```bash
git add public/admin.php public/includes/admin/races.php
git commit -m "refactor: move races tab HTML to partial"
```

---

### Task 3: Move drivers tab HTML to partial + consolidate toggleForm JS

**Files:**
- Modify: `public/admin.php`
- Modify: `public/includes/admin/races.php` (remove toggleForm script)
- Create: `public/includes/admin/drivers.php`

- [ ] **Step 1: Remove the toggleForm script from races.php**

In `public/includes/admin/races.php`, delete the entire `<script>` block at the bottom (the toggleForm function and its DOMContentLoaded listener). The file should end after the last `<?php endforeach; ?>`.

- [ ] **Step 2: Add the shared toggleForm script to admin.php**

In `public/admin.php`, find the line `</div>` that closes `.tabs-container` (just before `<?php include __DIR__ . '/includes/footer.php'; ?>`). Insert the script between the `</div>` and the footer include:

```php
</div>

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function() {
    const divs = document.querySelectorAll('.toggleForm');
    divs.forEach(div => {
        div.addEventListener('click', function() {
            toggleForm(this.getAttribute('data-link'));
        });
    });
});
function toggleForm(formId) {
    const form = document.getElementById(formId);
    const header = form.previousElementSibling;
    form.classList.toggle('expanded');
    header.classList.toggle('expanded');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
```

- [ ] **Step 3: Populate drivers.php**

Copy the drivers tab content (admin.php between `<?php if ($currentTab === 'drivers'): ?>` and its `<?php endif; ?>`, not including the `if`/`endif` tags) into `public/includes/admin/drivers.php`. **Omit the toggleForm `<script>` block** at the end — it's now in admin.php.

`public/includes/admin/drivers.php` (full content):

```php
<div class="card mb-2" id="add-driver-form">
    <div class="card-header collapsible-header toggleForm" data-link="driver-form-body" id="driver-form-header">
        <h3><i class="fas fa-plus-circle text-accent"></i> <?= $lang === 'da' ? 'Tilføj Kører' : 'Add Driver' ?></h3>
        <i class="fas fa-chevron-down toggle-icon"></i>
    </div>
    <div id="driver-form-body" class="collapsible-form">
        <div class="card-body">
            <form method="POST" class="grid grid-3" style="align-items: end;">
                <?= csrfField() ?>
                <div class="form-group" style="margin:0;">
                    <label class="form-label"><?= t('name') ?></label>
                    <input type="text" name="driver_name" class="form-input" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label"><?= t('team') ?></label>
                    <input type="text" name="driver_team" class="form-input" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label"><?= t('number') ?></label>
                    <input type="number" name="driver_number" class="form-input" required>
                </div>
                <button type="submit" name="add_driver" class="btn btn-primary">
                    <i class="fas fa-plus"></i> <?= t('add') ?>
                </button>
            </form>
        </div>
    </div>
</div>

<?php foreach ($drivers as $driver): ?>
    <div class="card mb-1 <?= isset($_GET['edit']) && $_GET['edit'] === $driver['id'] ? 'edit-form-active' : '' ?>" id="driver-<?= escape($driver['id']) ?>">
        <div class="card-body flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="text-accent" style="font-weight: bold; font-size: 1.25rem;">#<?= intval($driver['number']) ?></span>
                <div>
                    <strong><?= escape($driver['name']) ?></strong>
                    <br><small class="text-muted"><?= escape($driver['team']) ?></small>
                </div>
            </div>
            <div class="flex gap-1">
                <a href="?tab=drivers&edit=<?= escape($driver['id']) ?>#driver-<?= escape($driver['id']) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
                <a href="?tab=drivers&delete_driver=<?= escape($driver['id']) ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($driver['name']) ?>"><i class="fas fa-trash"></i></a>
            </div>
        </div>
        <?php if (isset($_GET['edit']) && $_GET['edit'] === $driver['id']): ?>
            <div class="card-body" style="border-top: 1px solid var(--border-color); background: var(--bg-hover);">
                <form method="POST" class="grid grid-3" style="align-items: end;">
                    <?= csrfField() ?>
                    <input type="hidden" name="driver_id" value="<?= escape($driver['id']) ?>">
                    <div class="form-group" style="margin:0;">
                        <input type="text" name="driver_name" class="form-input" value="<?= escape($driver['name']) ?>" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <input type="text" name="driver_team" class="form-input" value="<?= escape($driver['team']) ?>" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <input type="number" name="driver_number" class="form-input" value="<?= intval($driver['number']) ?>" required>
                    </div>
                    <div class="flex gap-1">
                        <button type="submit" name="update_driver" class="btn btn-primary btn-sm"><?= t('save') ?></button>
                        <a href="?tab=drivers" class="btn btn-secondary btn-sm"><?= t('cancel') ?></a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
```

- [ ] **Step 4: Delete the drivers tab block from admin.php**

In `public/admin.php`, delete the `<!-- DRIVERS TAB -->` comment and the entire `<?php if ($currentTab === 'drivers'): ?>` block.

- [ ] **Step 5: Verify both tabs and JS consolidation**

```bash
npm run deploy:test
```

Navigate to races tab: Add Race collapsible opens/closes. Navigate to drivers tab: Add Driver collapsible opens/closes. Both toggles should work via the single shared script.

- [ ] **Step 6: Commit**

```bash
git add public/admin.php public/includes/admin/races.php public/includes/admin/drivers.php
git commit -m "refactor: move drivers tab to partial, consolidate toggleForm JS"
```

---

### Task 4: Move users tab HTML to partial

**Files:**
- Modify: `public/admin.php`
- Modify: `public/includes/admin/users.php`

- [ ] **Step 1: Populate users.php**

Copy everything between `<?php if ($currentTab === 'users'): ?>` and its `<?php endif; ?>` (not including the tags) from admin.php into `public/includes/admin/users.php`.

The users tab has no toggleForm script. It has a `toggleResetPasswordForm` script — leave that in the partial.

`public/includes/admin/users.php` (full content — copy verbatim from admin.php):

```php
<?php foreach ($users as $user): ?>
    <div class="card mb-1">
        <div class="card-body flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="user-avatar"><?= escape(strtoupper(substr($user['display_name'] ?: $user['email'], 0, 1))) ?></div>
                <div>
                    <strong><?= escape($user['display_name'] ?: $user['email']) ?></strong>
                    <br><small class="text-muted"><?= escape($user['email']) ?></small>
                </div>
                <span class="badge" style="background: <?= $user['role'] === 'admin' ? 'var(--f1-red)' : 'var(--bg-secondary)' ?>; color: <?= $user['role'] === 'admin' ? 'white' : 'var(--text-primary)' ?>;">
                    <?= escape($user['role']) ?>
                </span>
                <?php if ($user['stars'] > 0): ?>
                    <span class="star">★<?= intval($user['stars']) ?></span>
                <?php endif; ?>
                <span class="text-accent"><?= intval($user['points']) ?> pts</span>
            </div>
            <div class="flex gap-1">
                <a href="?tab=users&toggle_competition=<?= escape($user['id']) ?>" class="btn btn-sm" style="background: <?= $user['in_competition'] ? 'var(--f1-red)' : 'var(--bg-secondary)' ?>; color: <?= $user['in_competition'] ? 'white' : 'var(--text-primary)' ?>; border: none;">
                    <i class="fas fa-<?= $user['in_competition'] ? 'check-circle' : 'times-circle' ?>"></i> <?= $user['in_competition'] ? ($lang === 'da' ? 'I Konkurrence' : 'In Competition') : ($lang === 'da' ? 'Ikke I Konkurrence' : 'Not In Competition') ?>
                </a>
                <?php if ($user['id'] !== $currentUser['id']): ?>
                    <a href="?tab=users&toggle_role=<?= escape($user['id']) ?>" class="btn btn-secondary btn-sm">
                        <?= $user['role'] === 'admin' ? ($lang === 'da' ? 'Gør Bruger' : 'Make User') : ($lang === 'da' ? 'Gør Admin' : 'Make Admin') ?>
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm btn-reset-pwd" data-link="<?= escape($user['id']) ?>">
                        <i class="fas fa-key"></i>
                    </button>
                    <a href="?tab=users&delete_user=<?= escape($user['id']) ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($user['display_name'] ?: $user['email']) ?>"><i class="fas fa-trash"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($user['id'] !== $currentUser['id']): ?>
            <div id="reset-pw-<?= escape($user['id']) ?>" class="hidden" style="padding: 1rem; border-top: 1px solid var(--border-color);">
                <form method="POST" class="flex gap-1 items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="user_id" value="<?= escape($user['id']) ?>">
                    <input type="hidden" name="user_email" value="<?= escape($user['email']) ?>">
                    <input type="hidden" name="user_name" value="<?= escape($user['display_name']) ?>">
                    <div class="form-group" style="margin:0; flex:1;">
                        <label class="form-label"><?= $lang === 'da' ? 'Ny adgangskode' : 'New password' ?></label>
                        <input type="password" name="new_password" class="form-input" required minlength="6" placeholder="••••••••">
                    </div>
                    <button type="submit" name="reset_user_password" class="btn btn-primary btn-sm">
                        <?= $lang === 'da' ? 'Nulstil' : 'Reset' ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-reset-pwd').forEach(button => {
        button.addEventListener('click', function() {
            document.getElementById('reset-pw-' + this.getAttribute('data-link')).classList.toggle('hidden');
        });
    });
});
</script>
```

- [ ] **Step 2: Delete the users tab block from admin.php**

Delete the `<!-- USERS TAB -->` comment and the `<?php if ($currentTab === 'users'): ?>` block from admin.php.

- [ ] **Step 3: Verify users tab**

```bash
npm run deploy:test
```

Navigate to the users tab. Confirm: user list shows with roles and points, reset password form toggles correctly.

- [ ] **Step 4: Commit**

```bash
git add public/admin.php public/includes/admin/users.php
git commit -m "refactor: move users tab HTML to partial"
```

---

### Task 5: Move bets tab HTML to partial

**Files:**
- Modify: `public/admin.php`
- Modify: `public/includes/admin/bets.php`

- [ ] **Step 1: Populate bets.php**

Copy everything between `<?php if ($currentTab === 'bets'): ?>` and its `<?php endif; ?>` into `public/includes/admin/bets.php`.

`public/includes/admin/bets.php` (full content):

```php
<?php
$betsByRace = [];
foreach ($bets as $bet) {
    $betsByRace[$bet['race_id']][] = $bet;
}
$bettingWindowHours = $settings['betting_window_hours'] ?? 48;
?>
<?php foreach ($betsByRace as $raceId => $raceBets):
    $raceName = $raceBets[0]['race_name'];
    $raceData = $racesById[$raceId] ?? null;
    $canDeleteBets = false;
    if ($raceData) {
        $raceDateTime = new DateTime($raceData['race_date'] . ' ' . $raceData['race_time']);
        $now = new DateTime();
        $bettingOpens = clone $raceDateTime;
        $bettingOpens->modify("-{$bettingWindowHours} hours");
        $canDeleteBets = !$raceData['result_p1'] && $now >= $bettingOpens && $now < $raceDateTime;
    }
?>
    <div class="card mb-2">
        <div class="card-header flex items-center justify-between">
            <h3><?= escape($raceName) ?></h3>
            <div class="flex items-center gap-2">
                <?php if ($canDeleteBets): ?>
                    <span class="badge status-open"><?= $lang === 'da' ? 'Betting åben' : 'Betting open' ?></span>
                <?php endif; ?>
                <span class="badge" style="background: var(--bg-secondary);"><?= count($raceBets) ?> bets</span>
            </div>
        </div>
        <div class="card-body">
            <?php foreach ($raceBets as $bet): ?>
                <div class="bet-item <?= $bet['is_perfect'] ? 'perfect-bet' : '' ?>">
                    <div class="bet-user">
                        <div class="bet-avatar"><?= escape(strtoupper(substr($bet['display_name'] ?: $bet['email'], 0, 1))) ?></div>
                        <div>
                            <strong class="flex items-center gap-1">
                                <?= escape($bet['display_name'] ?: $bet['email']) ?>
                                <?php if ($bet['is_perfect']): ?><span class="star">★</span><?php endif; ?>
                            </strong>
                            <small class="text-muted"><?= date('d M H:i', strtotime($bet['placed_at'])) ?></small>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="bet-predictions">
                            <?php foreach (['p1', 'p2', 'p3'] as $i => $key):
                                $driver = $driversById[$bet[$key]] ?? null;
                            ?>
                                <span class="bet-pred"><b>P<?= $i + 1 ?>:</b> <?= $driver ? explode(' ', $driver['name'])[count(explode(' ', $driver['name']))-1] : '?' ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($bet['points'] > 0): ?>
                            <span class="badge" style="background: var(--f1-red); color: white;"><?= $bet['points'] ?> pts</span>
                        <?php endif; ?>
                        <?php if ($canDeleteBets): ?>
                            <a href="?tab=bets&delete_bet=<?= $bet['id'] ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($bet['display_name'] ?: $bet['email']) ?>" title="<?= $lang === 'da' ? 'Slet og notificer bruger' : 'Delete and notify user' ?>">
                                <i class="fas fa-trash"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
<?php if (empty($bets)): ?>
    <div class="card"><div class="card-body text-center text-muted"><?= t('no_bets') ?></div></div>
<?php endif; ?>
```

- [ ] **Step 2: Delete the bets tab block from admin.php**

Delete the `<!-- BETS TAB -->` comment and the `<?php if ($currentTab === 'bets'): ?>` block.

- [ ] **Step 3: Verify bets tab**

```bash
npm run deploy:test
```

Navigate to the bets tab. Confirm bets are grouped by race and display correctly.

- [ ] **Step 4: Commit**

```bash
git add public/admin.php public/includes/admin/bets.php
git commit -m "refactor: move bets tab HTML to partial"
```

---

### Task 6: Move invites tab HTML to partial

**Files:**
- Modify: `public/admin.php`
- Modify: `public/includes/admin/invites.php`

- [ ] **Step 1: Populate invites.php**

Copy everything between `<?php if ($currentTab === 'invites'): ?>` and its `<?php endif; ?>` into `public/includes/admin/invites.php`. The `copyInviteLink` script stays in the partial.

`public/includes/admin/invites.php` (full content):

```php
<div class="card mb-2">
    <div class="card-header"><h3><?= $lang === 'da' ? 'Inviter ny bruger' : 'Invite new user' ?></h3></div>
    <div class="card-body">
        <form method="POST" class="flex gap-2" style="align-items: end;">
            <?= csrfField() ?>
            <div class="form-group" style="margin:0; flex:1;">
                <label class="form-label"><?= t('email') ?></label>
                <input type="email" name="invite_email" class="form-input" required placeholder="name@example.com">
            </div>
            <button type="submit" name="create_invite" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> <?= $lang === 'da' ? 'Send invitation' : 'Send invite' ?>
            </button>
        </form>
        <p class="text-muted mt-1" style="font-size: 0.875rem;">
            <?= $lang === 'da'
                ? 'Invitationen udløber efter 7 dage. Brugeren modtager en email med et registreringslink.'
                : 'Invite expires after 7 days. User will receive an email with a registration link.' ?>
        </p>
    </div>
</div>

<?php
$pendingInvites = array_filter($invites, fn($i) => !$i['used'] && strtotime($i['expires_at']) > time());
$usedInvites    = array_filter($invites, fn($i) => $i['used']);
$expiredInvites = array_filter($invites, fn($i) => !$i['used'] && strtotime($i['expires_at']) <= time());
?>

<?php if (!empty($pendingInvites)): ?>
    <h3 class="mb-1"><i class="fas fa-clock text-accent"></i> <?= $lang === 'da' ? 'Afventende invitationer' : 'Pending invites' ?> (<?= count($pendingInvites) ?>)</h3>
    <?php foreach ($pendingInvites as $invite): ?>
        <div class="card mb-1">
            <div class="card-body flex items-center justify-between">
                <div>
                    <strong><?= escape($invite['email']) ?></strong>
                    <br><small class="text-muted">
                        <?= $lang === 'da' ? 'Inviteret af' : 'Invited by' ?> <?= escape($invite['created_by_name'] ?: $invite['created_by_email']) ?>
                        · <?= $lang === 'da' ? 'Udløber' : 'Expires' ?> <?= date('d M Y H:i', strtotime($invite['expires_at'])) ?>
                    </small>
                </div>
                <div class="flex gap-1">
                    <a href="?tab=invites&resend_invite=<?= escape($invite['id']) ?>" class="btn btn-secondary btn-sm" title="<?= $lang === 'da' ? 'Gensend' : 'Resend' ?>">
                        <i class="fas fa-redo"></i>
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm invite-copy-btn" data-link="<?= escape((defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : SITE_URL) . '/register.php?token=' . $invite['token']) ?>" title="<?= $lang === 'da' ? 'Kopiér link' : 'Copy link' ?>">
                        <i class="fas fa-copy"></i>
                    </button>
                    <a href="?tab=invites&delete_invite=<?= escape($invite['id']) ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($invite['email']) ?>">
                        <i class="fas fa-trash"></i>
                    </a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($usedInvites)): ?>
    <h3 class="mb-1 mt-2"><i class="fas fa-check-circle" style="color: #10b981;"></i> <?= $lang === 'da' ? 'Brugte invitationer' : 'Used invites' ?> (<?= count($usedInvites) ?>)</h3>
    <?php foreach ($usedInvites as $invite): ?>
        <div class="card mb-1" style="opacity: 0.7;">
            <div class="card-body flex items-center justify-between">
                <div>
                    <strong><?= escape($invite['email']) ?></strong>
                    <span class="badge" style="background: #10b981; color: white; margin-left: 0.5rem;"><?= $lang === 'da' ? 'Registreret' : 'Registered' ?></span>
                    <br><small class="text-muted"><?= $lang === 'da' ? 'Inviteret' : 'Invited' ?> <?= date('d M Y', strtotime($invite['created_at'])) ?></small>
                </div>
                <a href="?tab=invites&delete_invite=<?= $invite['id'] ?>" class="btn btn-ghost btn-sm"><i class="fas fa-trash"></i></a>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($expiredInvites)): ?>
    <h3 class="mb-1 mt-2"><i class="fas fa-times-circle" style="color: #ef4444;"></i> <?= $lang === 'da' ? 'Udløbne invitationer' : 'Expired invites' ?> (<?= count($expiredInvites) ?>)</h3>
    <?php foreach ($expiredInvites as $invite): ?>
        <div class="card mb-1" style="opacity: 0.5;">
            <div class="card-body flex items-center justify-between">
                <div>
                    <strong><?= escape($invite['email']) ?></strong>
                    <span class="badge" style="background: #ef4444; color: white; margin-left: 0.5rem;"><?= $lang === 'da' ? 'Udløbet' : 'Expired' ?></span>
                    <br><small class="text-muted"><?= $lang === 'da' ? 'Udløb' : 'Expired' ?> <?= date('d M Y', strtotime($invite['expires_at'])) ?></small>
                </div>
                <div class="flex gap-1">
                    <a href="?tab=invites&resend_invite=<?= $invite['id'] ?>" class="btn btn-secondary btn-sm" title="<?= $lang === 'da' ? 'Gensend' : 'Resend' ?>">
                        <i class="fas fa-redo"></i> <?= $lang === 'da' ? 'Forny' : 'Renew' ?>
                    </a>
                    <a href="?tab=invites&delete_invite=<?= $invite['id'] ?>" class="btn btn-ghost btn-sm"><i class="fas fa-trash"></i></a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (empty($invites)): ?>
    <div class="card">
        <div class="card-body text-center text-muted"><?= $lang === 'da' ? 'Ingen invitationer endnu' : 'No invites yet' ?></div>
    </div>
<?php endif; ?>

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.invite-copy-btn').forEach(button => {
        button.addEventListener('click', function() {
            navigator.clipboard.writeText(this.getAttribute('data-link')).then(function() {
                alert('<?= $lang === 'da' ? 'Link kopieret!' : 'Link copied!' ?>');
            });
        });
    });
});
</script>
```

- [ ] **Step 2: Delete the invites tab block from admin.php**

Delete `<!-- INVITES TAB -->` and the `<?php if ($currentTab === 'invites'): ?>` block.

- [ ] **Step 3: Verify invites tab**

```bash
npm run deploy:test
```

Navigate to invites tab. Confirm: pending/used/expired sections show, copy button works, send invite form submits.

- [ ] **Step 4: Commit**

```bash
git add public/admin.php public/includes/admin/invites.php
git commit -m "refactor: move invites tab HTML to partial"
```

---

### Task 7: Move settings tab HTML to partial

**Files:**
- Modify: `public/admin.php`
- Modify: `public/includes/admin/settings.php`

- [ ] **Step 1: Populate settings.php**

Copy everything between `<?php if ($currentTab === 'settings'): ?>` and its `<?php endif; ?>` into `public/includes/admin/settings.php`.

`public/includes/admin/settings.php` (full content):

```php
<div class="card">
    <div class="card-header"><h3><?= t('settings') ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="grid grid-2 mb-2">
                <div class="form-group">
                    <label class="form-label">App Title</label>
                    <input type="text" name="app_title" class="form-input" value="<?= escape($settings['app_title']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $lang === 'da' ? 'År' : 'Year' ?></label>
                    <input type="text" name="app_year" class="form-input" value="<?= escape($settings['app_year']) ?>">
                </div>
            </div>
            <div class="grid grid-2 mb-2">
                <div class="form-group">
                    <label class="form-label">Hero Title (English)</label>
                    <input type="text" name="hero_title_en" class="form-input" value="<?= escape($settings['hero_title_en']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Hero Title (Dansk)</label>
                    <input type="text" name="hero_title_da" class="form-input" value="<?= escape($settings['hero_title_da']) ?>">
                </div>
            </div>
            <div class="grid grid-2 mb-2">
                <div class="form-group">
                    <label class="form-label">Hero Text (English)</label>
                    <textarea name="hero_text_en" class="form-input" rows="3"><?= escape($settings['hero_text_en']) ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Hero Text (Dansk)</label>
                    <textarea name="hero_text_da" class="form-input" rows="3"><?= escape($settings['hero_text_da']) ?></textarea>
                </div>
            </div>

            <h4 class="mb-1 mt-2"><i class="fas fa-clock text-accent"></i> <?= $lang === 'da' ? 'Betting Vindue' : 'Betting Window' ?></h4>
            <p class="text-muted mb-2" style="font-size: 0.875rem;">
                <?= $lang === 'da' ? 'Konfigurer hvornår betting åbner før løbsstart.' : 'Configure when betting opens before race start.' ?>
            </p>
            <div class="grid grid-2 mb-2">
                <div class="form-group">
                    <label class="form-label"><?= $lang === 'da' ? 'Timer før løb' : 'Hours before race' ?></label>
                    <input type="number" name="betting_window_hours" class="form-input" value="<?= intval($settings['betting_window_hours'] ?? 48) ?>" min="1" max="168">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <p class="text-muted" style="font-size: 0.875rem; margin-bottom: 0.5rem;">
                        <?= $lang === 'da'
                            ? 'Betting åbner ' . intval($settings['betting_window_hours'] ?? 48) . ' timer før løbsstart og lukker ved løbsstart.'
                            : 'Betting opens ' . intval($settings['betting_window_hours'] ?? 48) . ' hours before race start and closes at race start.' ?>
                    </p>
                </div>
            </div>

            <h4 class="mb-1 mt-2"><i class="fas fa-star text-accent"></i> <?= $lang === 'da' ? 'Point System' : 'Points System' ?></h4>
            <p class="text-muted mb-2" style="font-size: 0.875rem;">
                <?= $lang === 'da' ? 'Konfigurer hvor mange point der gives for korrekte forudsigelser.' : 'Configure how many points are awarded for correct predictions.' ?>
            </p>
            <div class="grid grid-4 mb-2">
                <div class="form-group">
                    <label class="form-label flex items-center gap-1"><span class="position-badge position-1">P1</span> <?= $lang === 'da' ? 'Point' : 'Points' ?></label>
                    <input type="number" name="points_p1" class="form-input" value="<?= intval($settings['points_p1'] ?? 25) ?>" min="0" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label flex items-center gap-1"><span class="position-badge position-2">P2</span> <?= $lang === 'da' ? 'Point' : 'Points' ?></label>
                    <input type="number" name="points_p2" class="form-input" value="<?= intval($settings['points_p2'] ?? 18) ?>" min="0" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label flex items-center gap-1"><span class="position-badge position-3">P3</span> <?= $lang === 'da' ? 'Point' : 'Points' ?></label>
                    <input type="number" name="points_p3" class="form-input" value="<?= intval($settings['points_p3'] ?? 15) ?>" min="0" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $lang === 'da' ? 'Forkert position' : 'Wrong position' ?></label>
                    <input type="number" name="points_wrong_pos" class="form-input" value="<?= intval($settings['points_wrong_pos'] ?? 5) ?>" min="0" max="100">
                </div>
            </div>
            <p class="text-muted mb-2" style="font-size: 0.75rem;">
                <i class="fas fa-info-circle"></i>
                <?= $lang === 'da'
                    ? '"Forkert position" point gives når en kører er i top 3, men på forkert position.'
                    : '"Wrong position" points are awarded when a driver is in top 3 but in wrong position.' ?>
            </p>

            <h4 class="mb-1 mt-3"><i class="fas fa-money-bill-wave text-accent"></i> <?= $lang === 'da' ? 'Betting Størrelse' : 'Bet Size' ?></h4>
            <p class="text-muted mb-2" style="font-size: 0.875rem;">
                <?= $lang === 'da' ? 'Standardstørrelse for hver indsats.' : 'Default size for each bet.' ?>
            </p>
            <div class="form-group mb-2" style="max-width: 200px;">
                <label class="form-label"><?= $lang === 'da' ? 'Indsatsstørrelse' : 'Bet Size' ?></label>
                <input type="number" name="bet_size" class="form-input" value="<?= intval($settings['bet_size'] ?? 10) ?>" min="1" max="1000">
            </div>

            <button type="submit" name="update_settings" class="btn btn-primary">
                <i class="fas fa-save"></i> <?= t('save') ?>
            </button>
        </form>
    </div>
</div>
```

- [ ] **Step 2: Delete the settings tab block from admin.php**

Delete `<!-- SETTINGS TAB -->` and the `<?php if ($currentTab === 'settings'): ?>` block.

- [ ] **Step 3: Verify settings tab**

```bash
npm run deploy:test
```

Navigate to settings tab. Confirm settings form renders and saves correctly.

- [ ] **Step 4: Commit**

```bash
git add public/admin.php public/includes/admin/settings.php
git commit -m "refactor: move settings tab HTML to partial"
```

---

### Task 8: Tab-aware data loading + tab count badges

**Files:**
- Modify: `public/admin.php` (lines 391–404 — the data loading section)

- [ ] **Step 1: Replace the flat data loading block with tab-aware loading**

In `public/admin.php`, find the data loading block (lines 391–403):

```php
// Hent data
$drivers = $db->query("SELECT * FROM drivers ORDER BY number")->fetchAll();
$races = $db->query("SELECT * FROM races ORDER BY race_date ASC")->fetchAll();
$users = $db->query("SELECT * FROM users ORDER BY points DESC")->fetchAll();
$bets = $db->query("SELECT b.*, u.display_name, u.email, r.name as race_name FROM bets b JOIN users u ON b.user_id = u.id JOIN races r ON b.race_id = r.id ORDER BY b.placed_at DESC")->fetchAll();
$invites = $db->query("SELECT i.*, u.display_name as created_by_name, u.email as created_by_email FROM invites i JOIN users u ON i.created_by = u.id ORDER BY i.created_at DESC")->fetchAll();
$settings = getSettings();

$driversById = [];
foreach ($drivers as $d) {
    $driversById[$d['id']] = $d;
}

$currentTab = $_GET['tab'] ?? 'races';
```

Replace it with:

```php
$currentTab = $_GET['tab'] ?? 'races';

// Tab count badges — lightweight COUNT queries for all tabs
$tabCounts = [
    'races'   => $db->query("SELECT COUNT(*) FROM races")->fetchColumn(),
    'drivers' => $db->query("SELECT COUNT(*) FROM drivers")->fetchColumn(),
    'users'   => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'invites' => $db->query("SELECT COUNT(*) FROM invites")->fetchColumn(),
    'bets'    => $db->query("SELECT COUNT(*) FROM bets")->fetchColumn(),
];

// $drivers always needed: races add/edit dropdowns and bets display
$drivers    = $db->query("SELECT * FROM drivers ORDER BY number")->fetchAll();
$driversById = array_column($drivers, null, 'id');

switch ($currentTab) {
    case 'races':
        $races = $db->query("SELECT * FROM races ORDER BY race_date ASC")->fetchAll();
        break;
    case 'users':
        $users = $db->query("SELECT * FROM users ORDER BY points DESC")->fetchAll();
        break;
    case 'bets':
        $races      = $db->query("SELECT * FROM races ORDER BY race_date ASC")->fetchAll();
        $racesById  = array_column($races, null, 'id');
        $bets       = $db->query("SELECT b.*, u.display_name, u.email, r.name as race_name FROM bets b JOIN users u ON b.user_id = u.id JOIN races r ON b.race_id = r.id ORDER BY b.placed_at DESC")->fetchAll();
        break;
    case 'invites':
        $invites = $db->query("SELECT i.*, u.display_name as created_by_name, u.email as created_by_email FROM invites i JOIN users u ON i.created_by = u.id ORDER BY i.created_at DESC")->fetchAll();
        break;
    // settings and drivers tabs: $settings + $drivers already loaded
}
```

- [ ] **Step 2: Update the tabs navigation to use $tabCounts**

In `public/admin.php`, find the tabs navigation HTML. Replace the `count($races)`, `count($drivers)`, etc. expressions with `$tabCounts[...]`:

```php
<div class="tabs">
    <a href="?tab=races" class="tab <?= $currentTab === 'races' ? 'active' : '' ?>">
        <i class="fas fa-flag"></i> <?= t('races') ?> <span class="tab-count">(<?= $tabCounts['races'] ?>)</span>
    </a>
    <a href="?tab=drivers" class="tab <?= $currentTab === 'drivers' ? 'active' : '' ?>">
        <i class="fas fa-car"></i> <?= t('drivers') ?> <span class="tab-count">(<?= $tabCounts['drivers'] ?>)</span>
    </a>
    <a href="?tab=users" class="tab <?= $currentTab === 'users' ? 'active' : '' ?>">
        <i class="fas fa-users"></i> <?= t('users') ?> <span class="tab-count">(<?= $tabCounts['users'] ?>)</span>
    </a>
    <a href="?tab=invites" class="tab <?= $currentTab === 'invites' ? 'active' : '' ?>">
        <i class="fas fa-envelope"></i> <?= $lang === 'da' ? 'Invitationer' : 'Invites' ?> <span class="tab-count">(<?= $tabCounts['invites'] ?>)</span>
    </a>
    <a href="?tab=bets" class="tab <?= $currentTab === 'bets' ? 'active' : '' ?>">
        <i class="fas fa-trophy"></i> <?= t('bets') ?> <span class="tab-count">(<?= $tabCounts['bets'] ?>)</span>
    </a>
    <a href="?tab=settings" class="tab <?= $currentTab === 'settings' ? 'active' : '' ?>">
        <i class="fas fa-cog"></i> <?= t('settings') ?>
    </a>
</div>
```

- [ ] **Step 3: Verify all tabs and counts**

```bash
npm run deploy:test
```

Open each tab in turn. Each should load correctly. Tab badge counts should show the same numbers on all tabs (they're from COUNT queries, not from the loaded data).

- [ ] **Step 4: Commit**

```bash
git add public/admin.php
git commit -m "refactor: tab-aware data loading and COUNT badge queries"
```

---

### Task 9: Convert 8 GET-based mutations to POST with CSRF

**Files:**
- Modify: `public/admin.php` — 8 handler `isset($_GET[...])` → `isset($_POST[...])`
- Modify: `public/includes/admin/drivers.php` — delete_driver link → form
- Modify: `public/includes/admin/races.php` — delete_race link → form
- Modify: `public/includes/admin/users.php` — toggle_role, toggle_competition, delete_user links → forms
- Modify: `public/includes/admin/bets.php` — delete_bet link → form
- Modify: `public/includes/admin/invites.php` — delete_invite, resend_invite links → forms

- [ ] **Step 1: Update the 8 handlers in admin.php**

Change each `isset($_GET[...])` to `isset($_POST[...])` for these 8 handlers:

```php
// Before → After (one-line change per handler)
if (isset($_GET['delete_driver']))       → if (isset($_POST['delete_driver']))
if (isset($_GET['delete_race']))         → if (isset($_POST['delete_race']))
if (isset($_GET['toggle_role']))         → if (isset($_POST['toggle_role']))
if (isset($_GET['toggle_competition']))  → if (isset($_POST['toggle_competition']))
if (isset($_GET['delete_user']))         → if (isset($_POST['delete_user']))
if (isset($_GET['delete_bet']))          → if (isset($_POST['delete_bet']))
if (isset($_GET['delete_invite']))       → if (isset($_POST['delete_invite']))
if (isset($_GET['resend_invite']))       → if (isset($_POST['resend_invite']))
```

Also update how each handler reads its ID. For example, `delete_driver` currently reads `$_GET['delete_driver']` — change to `$_POST['driver_id']` (using a hidden field). Apply the same pattern for each:

| Handler | Old ID source | New ID source |
|---|---|---|
| `delete_driver` | `$_GET['delete_driver']` | `$_POST['driver_id']` |
| `delete_race` | `$_GET['delete_race']` | `$_POST['race_id']` |
| `toggle_role` | `$_GET['toggle_role']` | `$_POST['user_id']` |
| `toggle_competition` | `$_GET['toggle_competition']` | `$_POST['user_id']` |
| `delete_user` | `$_GET['delete_user']` | `$_POST['user_id']` |
| `delete_bet` | `$_GET['delete_bet']` | `$_POST['bet_id']` |
| `delete_invite` | `$_GET['delete_invite']` | `$_POST['invite_id']` |
| `resend_invite` | `$_GET['resend_invite']` | `$_POST['invite_id']` |

Updated handlers in admin.php (show only the changed line in each block):

```php
// delete_driver
if (isset($_POST['delete_driver'])) {
    $id = $_POST['driver_id'];
    ...
}

// delete_race
if (isset($_POST['delete_race'])) {
    $id = $_POST['race_id'];
    ...
}

// toggle_role
if (isset($_POST['toggle_role'])) {
    $userId = $_POST['user_id'];
    ...
}

// toggle_competition
if (isset($_POST['toggle_competition'])) {
    $userId = $_POST['user_id'];
    ...
}

// delete_user
if (isset($_POST['delete_user'])) {
    $userId = $_POST['user_id'];
    ...
}

// delete_bet
if (isset($_POST['delete_bet'])) {
    $betId = $_POST['bet_id'];
    ...
}

// delete_invite
if (isset($_POST['delete_invite'])) {
    $inviteId = intval($_POST['invite_id']);
    ...
}

// resend_invite
if (isset($_POST['resend_invite'])) {
    $inviteId = intval($_POST['invite_id']);
    ...
}
```

- [ ] **Step 2: Replace delete_driver link with a POST form in drivers.php**

In `public/includes/admin/drivers.php`, find:

```html
<a href="?tab=drivers&delete_driver=<?= escape($driver['id']) ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($driver['name']) ?>"><i class="fas fa-trash"></i></a>
```

Replace with:

```html
<form method="POST" style="display:inline">
    <?= csrfField() ?>
    <input type="hidden" name="driver_id" value="<?= escape($driver['id']) ?>">
    <button type="submit" name="delete_driver" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($driver['name']) ?>">
        <i class="fas fa-trash"></i>
    </button>
</form>
```

- [ ] **Step 3: Replace delete_race link with a POST form in races.php**

In `public/includes/admin/races.php`, find:

```html
<a href="?tab=races&delete_race=<?= escape($race['id']) ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($race['name']) ?>"><i class="fas fa-trash"></i></a>
```

Replace with:

```html
<form method="POST" style="display:inline">
    <?= csrfField() ?>
    <input type="hidden" name="race_id" value="<?= escape($race['id']) ?>">
    <button type="submit" name="delete_race" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($race['name']) ?>">
        <i class="fas fa-trash"></i>
    </button>
</form>
```

- [ ] **Step 4: Replace 3 user action links with POST forms in users.php**

In `public/includes/admin/users.php`, replace the three action links:

**toggle_competition** — replace:
```html
<a href="?tab=users&toggle_competition=<?= escape($user['id']) ?>" class="btn btn-sm" style="background: <?= $user['in_competition'] ? 'var(--f1-red)' : 'var(--bg-secondary)' ?>; color: <?= $user['in_competition'] ? 'white' : 'var(--text-primary)' ?>; border: none;">
    <i class="fas fa-<?= $user['in_competition'] ? 'check-circle' : 'times-circle' ?>"></i> <?= $user['in_competition'] ? ($lang === 'da' ? 'I Konkurrence' : 'In Competition') : ($lang === 'da' ? 'Ikke I Konkurrence' : 'Not In Competition') ?>
</a>
```

With:
```html
<form method="POST" style="display:inline">
    <?= csrfField() ?>
    <input type="hidden" name="user_id" value="<?= escape($user['id']) ?>">
    <button type="submit" name="toggle_competition" class="btn btn-sm" style="background: <?= $user['in_competition'] ? 'var(--f1-red)' : 'var(--bg-secondary)' ?>; color: <?= $user['in_competition'] ? 'white' : 'var(--text-primary)' ?>; border: none;">
        <i class="fas fa-<?= $user['in_competition'] ? 'check-circle' : 'times-circle' ?>"></i> <?= $user['in_competition'] ? ($lang === 'da' ? 'I Konkurrence' : 'In Competition') : ($lang === 'da' ? 'Ikke I Konkurrence' : 'Not In Competition') ?>
    </button>
</form>
```

**toggle_role** — replace:
```html
<a href="?tab=users&toggle_role=<?= escape($user['id']) ?>" class="btn btn-secondary btn-sm">
    <?= $user['role'] === 'admin' ? ($lang === 'da' ? 'Gør Bruger' : 'Make User') : ($lang === 'da' ? 'Gør Admin' : 'Make Admin') ?>
</a>
```

With:
```html
<form method="POST" style="display:inline">
    <?= csrfField() ?>
    <input type="hidden" name="user_id" value="<?= escape($user['id']) ?>">
    <button type="submit" name="toggle_role" class="btn btn-secondary btn-sm">
        <?= $user['role'] === 'admin' ? ($lang === 'da' ? 'Gør Bruger' : 'Make User') : ($lang === 'da' ? 'Gør Admin' : 'Make Admin') ?>
    </button>
</form>
```

**delete_user** — replace:
```html
<a href="?tab=users&delete_user=<?= escape($user['id']) ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($user['display_name'] ?: $user['email']) ?>"><i class="fas fa-trash"></i></a>
```

With:
```html
<form method="POST" style="display:inline">
    <?= csrfField() ?>
    <input type="hidden" name="user_id" value="<?= escape($user['id']) ?>">
    <button type="submit" name="delete_user" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($user['display_name'] ?: $user['email']) ?>">
        <i class="fas fa-trash"></i>
    </button>
</form>
```

- [ ] **Step 5: Replace delete_bet link with a POST form in bets.php**

In `public/includes/admin/bets.php`, find:

```html
<a href="?tab=bets&delete_bet=<?= $bet['id'] ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($bet['display_name'] ?: $bet['email']) ?>" title="<?= $lang === 'da' ? 'Slet og notificer bruger' : 'Delete and notify user' ?>">
    <i class="fas fa-trash"></i>
</a>
```

Replace with:

```html
<form method="POST" style="display:inline">
    <?= csrfField() ?>
    <input type="hidden" name="bet_id" value="<?= escape($bet['id']) ?>">
    <button type="submit" name="delete_bet" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($bet['display_name'] ?: $bet['email']) ?>" title="<?= $lang === 'da' ? 'Slet og notificer bruger' : 'Delete and notify user' ?>">
        <i class="fas fa-trash"></i>
    </button>
</form>
```

- [ ] **Step 6: Replace 3 invite action links with POST forms in invites.php**

In `public/includes/admin/invites.php`, make these replacements:

**delete_invite** (appears in all 3 sections — pending, used, expired). Replace every occurrence of:
```html
<a href="?tab=invites&delete_invite=<?= escape($invite['id']) ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($invite['email']) ?>">
    <i class="fas fa-trash"></i>
</a>
```
With:
```html
<form method="POST" style="display:inline">
    <?= csrfField() ?>
    <input type="hidden" name="invite_id" value="<?= escape($invite['id']) ?>">
    <button type="submit" name="delete_invite" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($invite['email']) ?>">
        <i class="fas fa-trash"></i>
    </button>
</form>
```

Note: the used and expired sections have slightly different button classes (`btn-ghost` instead of `btn-danger`). Preserve the original class in each case.

**resend_invite** (appears in pending and expired sections). Replace every:
```html
<a href="?tab=invites&resend_invite=<?= escape($invite['id']) ?>" class="btn btn-secondary btn-sm" title="...">
    <i class="fas fa-redo"></i>
</a>
```
With:
```html
<form method="POST" style="display:inline">
    <?= csrfField() ?>
    <input type="hidden" name="invite_id" value="<?= escape($invite['id']) ?>">
    <button type="submit" name="resend_invite" class="btn btn-secondary btn-sm" title="...">
        <i class="fas fa-redo"></i>
    </button>
</form>
```

- [ ] **Step 7: Verify all mutations still work**

```bash
npm run deploy:test
```

Test each action manually on the test server:
- Drivers tab: add a driver, edit it, delete it
- Races tab: add a race, edit it, delete it
- Users tab: toggle competition status, toggle role (on a non-self user), reset password
- Invites tab: send an invite, resend it, delete it

Confirm all actions complete without errors and redirects work correctly.

- [ ] **Step 8: Commit**

```bash
git add public/admin.php public/includes/admin/
git commit -m "security: convert 8 GET-based admin mutations to POST with CSRF"
```

---

### Task 10: Fix integer bounds on update_driver and update_settings

**Files:**
- Modify: `public/admin.php` (2 lines)

- [ ] **Step 1: Fix driver number bounds in update_driver handler**

In `public/admin.php`, find the `update_driver` handler (around line 34–44). Change:

```php
$number = intval($_POST['driver_number'] ?? 0);
```

To:

```php
$number = sanitizeInt($_POST['driver_number'] ?? 0, 1, 99);
```

- [ ] **Step 2: Fix betting window bounds in update_settings handler**

In `public/admin.php`, find the `update_settings` handler. Change:

```php
$bettingWindowHours = intval($_POST['betting_window_hours'] ?? 48);

// Validate betting window (minimum 1 hour, maximum 168 hours = 1 week)
$bettingWindowHours = max(1, min(168, $bettingWindowHours));
```

To (the manual clamp is now redundant — remove it):

```php
$bettingWindowHours = sanitizeInt($_POST['betting_window_hours'] ?? 48, 1, 168);
```

- [ ] **Step 3: Verify**

```bash
npm run deploy:test
```

Edit a driver and save — it should update normally. Save settings with a betting window — it should save normally. Verify the smoke tests still pass:

```bash
npm run test:smoke
```

Expected: all smoke tests pass.

- [ ] **Step 4: Commit**

```bash
git add public/admin.php
git commit -m "fix: enforce integer bounds on update_driver and update_settings"
```
