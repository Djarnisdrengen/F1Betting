<?php
require_once 'config.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();
$lang = getLang();

// Handle actions
$message = '';
$error = '';

// ============ DRIVERS ============
if (isset($_POST['add_driver'])) {
    $name = trim($_POST['driver_name'] ?? '');
    $team = trim($_POST['driver_team'] ?? '');
    $number = intval($_POST['driver_number'] ?? 0);
    
    if ($name && $team && $number) {
        $id = generateUUID();
        $stmt = $db->prepare("INSERT INTO drivers (id, name, team, number) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $name, $team, $number]);
        $message = $lang === 'da' ? 'Kører tilføjet!' : 'Driver added!';
    }
}

if (isset($_POST['update_driver'])) {
    $id = $_POST['driver_id'] ?? '';
    $name = trim($_POST['driver_name'] ?? '');
    $team = trim($_POST['driver_team'] ?? '');
    $number = intval($_POST['driver_number'] ?? 0);
    
    if ($id && $name && $team && $number) {
        $stmt = $db->prepare("UPDATE drivers SET name = ?, team = ?, number = ? WHERE id = ?");
        $stmt->execute([$name, $team, $number, $id]);
        $message = $lang === 'da' ? 'Kører opdateret!' : 'Driver updated!';
    }
}

if (isset($_GET['delete_driver'])) {
    $id = $_GET['delete_driver'];
    $stmt = $db->prepare("DELETE FROM drivers WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: admin.php?tab=drivers&msg=deleted");
    exit;
}

// ============ RACES ============
if (isset($_POST['add_race'])) {
    $name = trim($_POST['race_name'] ?? '');
    $location = trim($_POST['race_location'] ?? '');
    $date = $_POST['race_date'] ?? '';
    $time = $_POST['race_time'] ?? '';
    $quali_p1 = $_POST['quali_p1'] ?: null;
    $quali_p2 = $_POST['quali_p2'] ?: null;
    $quali_p3 = $_POST['quali_p3'] ?: null;
    
    if ($name && $location && $date && $time) {
        $id = generateUUID();
        $stmt = $db->prepare("INSERT INTO races (id, name, location, race_date, race_time, quali_p1, quali_p2, quali_p3) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id, $name, $location, $date, $time, $quali_p1, $quali_p2, $quali_p3]);
        $message = $lang === 'da' ? 'Løb tilføjet!' : 'Race added!';
    }
}

if (isset($_POST['update_race'])) {
    $id = $_POST['race_id'] ?? '';
    $name = trim($_POST['race_name'] ?? '');
    $location = trim($_POST['race_location'] ?? '');
    $date = $_POST['race_date'] ?? '';
    $time = $_POST['race_time'] ?? '';
    $quali_p1 = $_POST['quali_p1'] ?: null;
    $quali_p2 = $_POST['quali_p2'] ?: null;
    $quali_p3 = $_POST['quali_p3'] ?: null;
    $result_p1 = $_POST['result_p1'] ?: null;
    $result_p2 = $_POST['result_p2'] ?: null;
    $result_p3 = $_POST['result_p3'] ?: null;
    
    if ($id && $name) {
        $stmt = $db->prepare("UPDATE races SET name = ?, location = ?, race_date = ?, race_time = ?, quali_p1 = ?, quali_p2 = ?, quali_p3 = ?, result_p1 = ?, result_p2 = ?, result_p3 = ? WHERE id = ?");
        $stmt->execute([$name, $location, $date, $time, $quali_p1, $quali_p2, $quali_p3, $result_p1, $result_p2, $result_p3, $id]);
        
        // Beregn point hvis resultater er sat
        if ($result_p1 && $result_p2 && $result_p3) {
            calculateRacePoints($id, $result_p1, $result_p2, $result_p3);
        }
        
        $message = $lang === 'da' ? 'Løb opdateret!' : 'Race updated!';
    }
}

if (isset($_GET['delete_race'])) {
    $id = $_GET['delete_race'];
    $stmt = $db->prepare("DELETE FROM bets WHERE race_id = ?");
    $stmt->execute([$id]);
    $stmt = $db->prepare("DELETE FROM races WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: admin.php?tab=races&msg=deleted");
    exit;
}

// ============ USERS ============
if (isset($_GET['toggle_role'])) {
    $userId = $_GET['toggle_role'];
    if ($userId !== $currentUser['id']) {
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $newRole = $user['role'] === 'admin' ? 'user' : 'admin';
        $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->execute([$newRole, $userId]);
    }
    header("Location: admin.php?tab=users");
    exit;
}

if (isset($_GET['delete_user'])) {
    $userId = $_GET['delete_user'];
    if ($userId !== $currentUser['id']) {
        $stmt = $db->prepare("DELETE FROM bets WHERE user_id = ?");
        $stmt->execute([$userId]);
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
    }
    header("Location: admin.php?tab=users&msg=deleted");
    exit;
}

// ============ SETTINGS ============
if (isset($_POST['update_settings'])) {
    $appTitle = trim($_POST['app_title'] ?? '');
    $appYear = trim($_POST['app_year'] ?? '');
    $heroTitleEn = trim($_POST['hero_title_en'] ?? '');
    $heroTitleDa = trim($_POST['hero_title_da'] ?? '');
    $heroTextEn = trim($_POST['hero_text_en'] ?? '');
    $heroTextDa = trim($_POST['hero_text_da'] ?? '');
    
    $stmt = $db->prepare("UPDATE settings SET app_title = ?, app_year = ?, hero_title_en = ?, hero_title_da = ?, hero_text_en = ?, hero_text_da = ? WHERE id = 1");
    $stmt->execute([$appTitle, $appYear, $heroTitleEn, $heroTitleDa, $heroTextEn, $heroTextDa]);
    $message = $lang === 'da' ? 'Indstillinger gemt!' : 'Settings saved!';
}

// Funktion til at beregne point
function calculateRacePoints($raceId, $p1, $p2, $p3) {
    global $db;
    $results = [$p1, $p2, $p3];
    
    $stmt = $db->prepare("SELECT * FROM bets WHERE race_id = ?");
    $stmt->execute([$raceId]);
    $bets = $stmt->fetchAll();
    
    foreach ($bets as $bet) {
        $oldPoints = $bet['points'];
        $oldIsPerfect = $bet['is_perfect'];
        
        $points = 0;
        $predictions = [$bet['p1'], $bet['p2'], $bet['p3']];
        
        // Exact position points
        if ($bet['p1'] === $p1) $points += 25;
        if ($bet['p2'] === $p2) $points += 18;
        if ($bet['p3'] === $p3) $points += 15;
        
        // Bonus for drivers in top 3 but wrong position
        foreach ($predictions as $i => $pred) {
            $resultIndex = array_search($pred, $results);
            if ($resultIndex !== false && $resultIndex !== $i) {
                $points += 5;
            }
        }
        
        $isPerfect = ($bet['p1'] === $p1 && $bet['p2'] === $p2 && $bet['p3'] === $p3) ? 1 : 0;
        
        // Update bet
        $stmt2 = $db->prepare("UPDATE bets SET points = ?, is_perfect = ? WHERE id = ?");
        $stmt2->execute([$points, $isPerfect, $bet['id']]);
        
        // Update user points (subtract old, add new)
        $stmt3 = $db->prepare("SELECT points, stars FROM users WHERE id = ?");
        $stmt3->execute([$bet['user_id']]);
        $user = $stmt3->fetch();
        
        $newUserPoints = $user['points'] - $oldPoints + $points;
        $newUserStars = $user['stars'] - ($oldIsPerfect ? 1 : 0) + $isPerfect;
        
        $stmt4 = $db->prepare("UPDATE users SET points = ?, stars = ? WHERE id = ?");
        $stmt4->execute([max(0, $newUserPoints), max(0, $newUserStars), $bet['user_id']]);
    }
}

// Hent data
$drivers = $db->query("SELECT * FROM drivers ORDER BY number")->fetchAll();
$races = $db->query("SELECT * FROM races ORDER BY race_date DESC")->fetchAll();
$users = $db->query("SELECT * FROM users ORDER BY points DESC")->fetchAll();
$bets = $db->query("SELECT b.*, u.display_name, u.email, r.name as race_name FROM bets b JOIN users u ON b.user_id = u.id JOIN races r ON b.race_id = r.id ORDER BY b.placed_at DESC")->fetchAll();
$settings = getSettings();

$driversById = [];
foreach ($drivers as $d) {
    $driversById[$d['id']] = $d;
}

$currentTab = $_GET['tab'] ?? 'drivers';

include 'includes/header.php';
?>

<h1 class="mb-3"><i class="fas fa-cog text-accent"></i> <?= t('admin') ?></h1>

<?php if ($message): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
    <div class="alert alert-success"><?= $lang === 'da' ? 'Slettet!' : 'Deleted!' ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs-container">
    <div class="tabs">
        <a href="?tab=drivers" class="tab <?= $currentTab === 'drivers' ? 'active' : '' ?>">
            <i class="fas fa-car"></i> <?= t('drivers') ?>
        </a>
        <a href="?tab=races" class="tab <?= $currentTab === 'races' ? 'active' : '' ?>">
            <i class="fas fa-flag"></i> <?= t('races') ?>
        </a>
        <a href="?tab=users" class="tab <?= $currentTab === 'users' ? 'active' : '' ?>">
            <i class="fas fa-users"></i> <?= t('users') ?>
        </a>
        <a href="?tab=bets" class="tab <?= $currentTab === 'bets' ? 'active' : '' ?>">
            <i class="fas fa-trophy"></i> <?= t('bets') ?>
        </a>
        <a href="?tab=settings" class="tab <?= $currentTab === 'settings' ? 'active' : '' ?>">
            <i class="fas fa-cog"></i> <?= t('settings') ?>
        </a>
    </div>
    
    <!-- DRIVERS TAB -->
    <?php if ($currentTab === 'drivers'): ?>
        <div class="card mb-2">
            <div class="card-header"><h3><?= $lang === 'da' ? 'Tilføj Kører' : 'Add Driver' ?></h3></div>
            <div class="card-body">
                <form method="POST" class="grid grid-3" style="align-items: end;">
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
        
        <?php foreach ($drivers as $driver): ?>
            <div class="card mb-1">
                <div class="card-body flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-accent" style="font-weight: bold; font-size: 1.25rem;">#<?= $driver['number'] ?></span>
                        <div>
                            <strong><?= escape($driver['name']) ?></strong>
                            <br><small class="text-muted"><?= escape($driver['team']) ?></small>
                        </div>
                    </div>
                    <div class="flex gap-1">
                        <a href="?tab=drivers&edit=<?= $driver['id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
                        <a href="?tab=drivers&delete_driver=<?= $driver['id'] ?>" class="btn btn-danger btn-sm btn-delete"><i class="fas fa-trash"></i></a>
                    </div>
                </div>
                <?php if (isset($_GET['edit']) && $_GET['edit'] === $driver['id']): ?>
                    <div class="card-body" style="border-top: 1px solid var(--border-color);">
                        <form method="POST" class="grid grid-3" style="align-items: end;">
                            <input type="hidden" name="driver_id" value="<?= $driver['id'] ?>">
                            <div class="form-group" style="margin:0;">
                                <input type="text" name="driver_name" class="form-input" value="<?= escape($driver['name']) ?>" required>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <input type="text" name="driver_team" class="form-input" value="<?= escape($driver['team']) ?>" required>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <input type="number" name="driver_number" class="form-input" value="<?= $driver['number'] ?>" required>
                            </div>
                            <button type="submit" name="update_driver" class="btn btn-primary btn-sm"><?= t('save') ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- RACES TAB -->
    <?php if ($currentTab === 'races'): ?>
        <div class="card mb-2">
            <div class="card-header"><h3><?= $lang === 'da' ? 'Tilføj Løb' : 'Add Race' ?></h3></div>
            <div class="card-body">
                <form method="POST">
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
                            <label class="form-label"><?= t('race_time') ?></label>
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
        
        <?php foreach ($races as $race): ?>
            <div class="card mb-1">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-1">
                        <div>
                            <strong><?= escape($race['name']) ?></strong>
                            <br><small class="text-muted"><?= escape($race['location']) ?> - <?= $race['race_date'] ?> <?= substr($race['race_time'], 0, 5) ?></small>
                        </div>
                        <div class="flex gap-1">
                            <a href="?tab=races&edit=<?= $race['id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
                            <a href="?tab=races&delete_race=<?= $race['id'] ?>" class="btn btn-danger btn-sm btn-delete"><i class="fas fa-trash"></i></a>
                        </div>
                    </div>
                    <?php if ($race['quali_p1']): ?>
                        <small class="text-muted"><?= t('qualifying') ?>: <?= $driversById[$race['quali_p1']]['name'] ?? '?' ?>, <?= $driversById[$race['quali_p2']]['name'] ?? '?' ?>, <?= $driversById[$race['quali_p3']]['name'] ?? '?' ?></small>
                    <?php endif; ?>
                    <?php if ($race['result_p1']): ?>
                        <br><small class="text-accent"><?= t('results') ?>: <?= $driversById[$race['result_p1']]['name'] ?? '?' ?>, <?= $driversById[$race['result_p2']]['name'] ?? '?' ?>, <?= $driversById[$race['result_p3']]['name'] ?? '?' ?></small>
                    <?php endif; ?>
                </div>
                <?php if (isset($_GET['edit']) && $_GET['edit'] === $race['id']): ?>
                    <div class="card-body" style="border-top: 1px solid var(--border-color);">
                        <form method="POST">
                            <input type="hidden" name="race_id" value="<?= $race['id'] ?>">
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
                                    <input type="date" name="race_date" class="form-input" value="<?= $race['race_date'] ?>" required>
                                </div>
                                <div class="form-group" style="margin:0;">
                                    <label class="form-label"><?= t('race_time') ?></label>
                                    <input type="time" name="race_time" class="form-input" value="<?= $race['race_time'] ?>" required>
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
                            <button type="submit" name="update_race" class="btn btn-primary"><?= t('save') ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- USERS TAB -->
    <?php if ($currentTab === 'users'): ?>
        <?php foreach ($users as $user): ?>
            <div class="card mb-1">
                <div class="card-body flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <div class="user-avatar"><?= strtoupper(substr($user['display_name'] ?: $user['email'], 0, 1)) ?></div>
                        <div>
                            <strong><?= escape($user['display_name'] ?: $user['email']) ?></strong>
                            <br><small class="text-muted"><?= escape($user['email']) ?></small>
                        </div>
                        <span class="badge" style="background: <?= $user['role'] === 'admin' ? 'var(--f1-red)' : 'var(--bg-secondary)' ?>; color: <?= $user['role'] === 'admin' ? 'white' : 'var(--text-primary)' ?>;">
                            <?= $user['role'] ?>
                        </span>
                        <?php if ($user['stars'] > 0): ?>
                            <span class="star">★<?= $user['stars'] ?></span>
                        <?php endif; ?>
                        <span class="text-accent"><?= $user['points'] ?> pts</span>
                    </div>
                    <?php if ($user['id'] !== $currentUser['id']): ?>
                        <div class="flex gap-1">
                            <a href="?tab=users&toggle_role=<?= $user['id'] ?>" class="btn btn-secondary btn-sm">
                                <?= $user['role'] === 'admin' ? ($lang === 'da' ? 'Gør Bruger' : 'Make User') : ($lang === 'da' ? 'Gør Admin' : 'Make Admin') ?>
                            </a>
                            <a href="?tab=users&delete_user=<?= $user['id'] ?>" class="btn btn-danger btn-sm btn-delete"><i class="fas fa-trash"></i></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- BETS TAB -->
    <?php if ($currentTab === 'bets'): ?>
        <?php 
        $betsByRace = [];
        foreach ($bets as $bet) {
            $betsByRace[$bet['race_id']][] = $bet;
        }
        ?>
        <?php foreach ($betsByRace as $raceId => $raceBets): 
            $raceName = $raceBets[0]['race_name'];
        ?>
            <div class="card mb-2">
                <div class="card-header flex items-center justify-between">
                    <h3><?= escape($raceName) ?></h3>
                    <span class="badge" style="background: var(--bg-secondary);"><?= count($raceBets) ?> bets</span>
                </div>
                <div class="card-body">
                    <?php foreach ($raceBets as $bet): ?>
                        <div class="bet-item <?= $bet['is_perfect'] ? 'perfect-bet' : '' ?>">
                            <div class="bet-user">
                                <div class="bet-avatar"><?= strtoupper(substr($bet['display_name'] ?: $bet['email'], 0, 1)) ?></div>
                                <div>
                                    <strong class="flex items-center gap-1">
                                        <?= escape($bet['display_name'] ?: $bet['email']) ?>
                                        <?php if ($bet['is_perfect']): ?><span class="star">★</span><?php endif; ?>
                                    </strong>
                                    <small class="text-muted"><?= date('d M H:i', strtotime($bet['placed_at'])) ?></small>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
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
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($bets)): ?>
            <div class="card"><div class="card-body text-center text-muted"><?= t('no_bets') ?></div></div>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- SETTINGS TAB -->
    <?php if ($currentTab === 'settings'): ?>
        <div class="card">
            <div class="card-header"><h3><?= t('settings') ?></h3></div>
            <div class="card-body">
                <form method="POST">
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
                    <button type="submit" name="update_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?= t('save') ?>
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
