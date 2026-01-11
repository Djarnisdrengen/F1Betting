<?php
require_once __DIR__ . '/../config.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();
$lang = getLang();

// Validate CSRF for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
}

// Handle actions
$message = '';
$error = '';

// ============ DRIVERS ============
if (isset($_POST['add_driver'])) {
    $name = sanitizeString($_POST['driver_name'] ?? '');
    $team = sanitizeString($_POST['driver_team'] ?? '');
    $number = sanitizeInt($_POST['driver_number'] ?? 0, 1, 99);
    
    if ($name && $team && $number) {
        $id = generateUUID();
        $stmt = $db->prepare("INSERT INTO drivers (id, name, team, number) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $name, $team, $number]);
        $message = $lang === 'da' ? 'Kører tilføjet!' : 'Driver added!';
    }
}

if (isset($_POST['update_driver'])) {
    $id = sanitizeString($_POST['driver_id'] ?? '');
    $name = sanitizeString($_POST['driver_name'] ?? '');
    $team = sanitizeString($_POST['driver_team'] ?? '');
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
        // Validate date format
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        $timeObj = DateTime::createFromFormat('H:i', $time);
        
        if ($dateObj && $timeObj) {
            $id = generateUUID();
            $stmt = $db->prepare("INSERT INTO races (id, name, location, race_date, race_time, quali_p1, quali_p2, quali_p3) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $name, $location, $date, $time, $quali_p1, $quali_p2, $quali_p3]);
            $message = $lang === 'da' ? 'Løb tilføjet!' : 'Race added!';
        } else {
            $error = $lang === 'da' ? 'Ugyldig dato eller tid' : 'Invalid date or time';
        }
    } else {
        $error = $lang === 'da' ? 'Udfyld alle felter' : 'Fill in all fields';
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
        $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt->execute([$userId]);
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
    }
    header("Location: admin.php?tab=users&msg=deleted");
    exit;
}

// Admin reset user password
if (isset($_POST['reset_user_password'])) {
    $userId = $_POST['user_id'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    
    if ($userId && strlen($newPassword) >= 6) {
        $hashedPassword = hashPassword($newPassword);
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $userId]);
        $message = $lang === 'da' ? 'Adgangskode nulstillet!' : 'Password reset!';
    } else {
        $error = $lang === 'da' ? 'Adgangskoden skal være mindst 6 tegn' : 'Password must be at least 6 characters';
    }
}

// ============ DELETE BET ============
if (isset($_GET['delete_bet'])) {
    $betId = $_GET['delete_bet'];
    
    // Hent bet info inkl race data for at tjekke betting status
    $stmt = $db->prepare("
        SELECT b.*, u.email, u.display_name, u.id as bet_user_id, 
               r.name as race_name, r.race_date, r.race_time, r.result_p1
        FROM bets b 
        JOIN users u ON b.user_id = u.id 
        JOIN races r ON b.race_id = r.id 
        WHERE b.id = ?
    ");
    $stmt->execute([$betId]);
    $bet = $stmt->fetch();
    
    if ($bet) {
        // Tjek om betting vindue er åbent - kun tillad sletning hvis åbent
        $bettingWindowHours = $settings['betting_window_hours'] ?? 48;
        $raceDateTime = new DateTime($bet['race_date'] . ' ' . $bet['race_time']);
        $now = new DateTime();
        $bettingOpens = clone $raceDateTime;
        $bettingOpens->modify("-{$bettingWindowHours} hours");
        
        $canDelete = !$bet['result_p1'] && $now >= $bettingOpens && $now < $raceDateTime;
        
        if ($canDelete) {
            // Hvis bet har point, skal vi trække dem fra brugeren
            if ($bet['points'] > 0 || $bet['is_perfect']) {
                $stmt2 = $db->prepare("SELECT points, stars FROM users WHERE id = ?");
                $stmt2->execute([$bet['bet_user_id']]);
                $user = $stmt2->fetch();
                
                $newPoints = max(0, $user['points'] - $bet['points']);
                $newStars = max(0, $user['stars'] - ($bet['is_perfect'] ? 1 : 0));
                
                $stmt3 = $db->prepare("UPDATE users SET points = ?, stars = ? WHERE id = ?");
                $stmt3->execute([$newPoints, $newStars, $bet['bet_user_id']]);
            }
            
            // Slet bet
            $stmt = $db->prepare("DELETE FROM bets WHERE id = ?");
            $stmt->execute([$betId]);
            
            // Send email til bruger
            require_once __DIR__ . '/includes/smtp.php';
            $appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
            
            if ($lang === 'da') {
                $subject = "Dit bet er blevet slettet - $appName";
                $greeting = "Hej " . ($bet['display_name'] ?: $bet['email']) . ",";
                $intro = "Dit bet på <strong>" . htmlspecialchars($bet['race_name']) . "</strong> er blevet slettet af en administrator.";
                $buttonText = "Gå til appen";
                $expiry = "Kontakt administrator hvis du har spørgsmål.";
            } else {
                $subject = "Your bet has been deleted - $appName";
                $greeting = "Hi " . ($bet['display_name'] ?: $bet['email']) . ",";
                $intro = "Your bet on <strong>" . htmlspecialchars($bet['race_name']) . "</strong> has been deleted by an administrator.";
                $buttonText = "Go to app";
                $expiry = "Contact administrator if you have questions.";
            }
            
            $htmlContent = getEmailTemplate($greeting, $intro, $buttonText, SITE_URL, $expiry, '', "Best regards,<br>$appName", $appName);
            sendEmail($bet['email'], $subject, $htmlContent);
            
            $message = $lang === 'da' ? 'Bet slettet og bruger notificeret!' : 'Bet deleted and user notified!';
        } else {
            $message = $lang === 'da' ? 'Kan kun slette bets hvor betting vindue er åbent' : 'Can only delete bets where betting window is open';
        }
    }
    
    header("Location: admin.php?tab=bets&msg=" . urlencode($message));
    exit;
}

// ============ INVITES ============
if (isset($_POST['create_invite'])) {
    $inviteEmail = trim($_POST['invite_email'] ?? '');
    
    if ($inviteEmail && filter_var($inviteEmail, FILTER_VALIDATE_EMAIL)) {
        // Tjek om email allerede findes som bruger
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$inviteEmail]);
        if ($stmt->fetch()) {
            $error = $lang === 'da' ? 'Email er allerede registreret som bruger' : 'Email is already registered as user';
        } else {
            // Tjek om der allerede er en aktiv invite
            $stmt = $db->prepare("SELECT id FROM invites WHERE email = ? AND used = 0 AND expires_at > NOW()");
            $stmt->execute([$inviteEmail]);
            if ($stmt->fetch()) {
                $error = $lang === 'da' ? 'Der er allerede en aktiv invitation til denne email' : 'There is already an active invite for this email';
            } else {
                // Opret invite
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
                
                $stmt = $db->prepare("INSERT INTO invites (email, token, created_by, expires_at) VALUES (?, ?, ?, ?)");
                $stmt->execute([$inviteEmail, $token, $currentUser['id'], $expiresAt]);
                
                // Generer invite link
                $inviteLink = SITE_URL . "/register.php?token=" . $token;
                
                // Send email via SMTP
                require_once __DIR__ . '/includes/smtp.php';
                $result = sendInviteEmail($inviteEmail, $inviteLink, $currentUser['display_name'] ?: $currentUser['email'], $lang);
                
                if ($result['success']) {
                    $message = $lang === 'da' ? 'Invitation sendt til ' . $inviteEmail : 'Invitation sent to ' . $inviteEmail;
                } else {
                    $message = $lang === 'da' 
                        ? 'Invitation oprettet! Email kunne ikke sendes. Del linket manuelt:<br><code style="word-break:break-all;font-size:0.75rem;">' . $inviteLink . '</code>'
                        : 'Invitation created! Email could not be sent. Share link manually:<br><code style="word-break:break-all;font-size:0.75rem;">' . $inviteLink . '</code>';
                }
            }
        }
    } else {
        $error = $lang === 'da' ? 'Ugyldig email' : 'Invalid email';
    }
}

if (isset($_GET['delete_invite'])) {
    $inviteId = intval($_GET['delete_invite']);
    $stmt = $db->prepare("DELETE FROM invites WHERE id = ?");
    $stmt->execute([$inviteId]);
    header("Location: admin.php?tab=invites&msg=deleted");
    exit;
}

if (isset($_GET['resend_invite'])) {
    $inviteId = intval($_GET['resend_invite']);
    $stmt = $db->prepare("SELECT * FROM invites WHERE id = ? AND used = 0");
    $stmt->execute([$inviteId]);
    $invite = $stmt->fetch();
    
    if ($invite) {
        // Forlæng udløbstid
        $newExpiry = date('Y-m-d H:i:s', strtotime('+7 days'));
        $stmt = $db->prepare("UPDATE invites SET expires_at = ? WHERE id = ?");
        $stmt->execute([$newExpiry, $inviteId]);
        
        $inviteLink = SITE_URL . "/register.php?token=" . $invite['token'];
        
        // Send email via SMTP
        require_once __DIR__ . '/includes/smtp.php';
        $result = sendInviteEmail($invite['email'], $inviteLink, $currentUser['display_name'] ?: $currentUser['email'], $lang);
        
        if ($result['success']) {
            $message = $lang === 'da' ? 'Invitation gensendt!' : 'Invitation resent!';
        } else {
            $message = $lang === 'da' 
                ? 'Invitation forlænget! Email kunne ikke sendes. Del linket manuelt:<br><code style="word-break:break-all;font-size:0.75rem;">' . $inviteLink . '</code>'
                : 'Invitation extended! Email could not be sent. Share link manually:<br><code style="word-break:break-all;font-size:0.75rem;">' . $inviteLink . '</code>';
        }
    }
    header("Location: admin.php?tab=invites&msg=" . urlencode($message));
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
    $pointsP1 = intval($_POST['points_p1'] ?? 25);
    $pointsP2 = intval($_POST['points_p2'] ?? 18);
    $pointsP3 = intval($_POST['points_p3'] ?? 15);
    $pointsWrongPos = intval($_POST['points_wrong_pos'] ?? 5);
    $bettingWindowHours = intval($_POST['betting_window_hours'] ?? 48);
    
    // Validate betting window (minimum 1 hour, maximum 168 hours = 1 week)
    $bettingWindowHours = max(1, min(168, $bettingWindowHours));
    
    $stmt = $db->prepare("UPDATE settings SET app_title = ?, app_year = ?, hero_title_en = ?, hero_title_da = ?, hero_text_en = ?, hero_text_da = ?, points_p1 = ?, points_p2 = ?, points_p3 = ?, points_wrong_pos = ?, betting_window_hours = ? WHERE id = 1");
    $stmt->execute([$appTitle, $appYear, $heroTitleEn, $heroTitleDa, $heroTextEn, $heroTextDa, $pointsP1, $pointsP2, $pointsP3, $pointsWrongPos, $bettingWindowHours]);
    $message = $lang === 'da' ? 'Indstillinger gemt!' : 'Settings saved!';
}

// Funktion til at beregne point
function calculateRacePoints($raceId, $p1, $p2, $p3) {
    global $db;
    $results = [$p1, $p2, $p3];
    
    // Hent point indstillinger
    $settings = getSettings();
    $pointsP1 = $settings['points_p1'] ?? 25;
    $pointsP2 = $settings['points_p2'] ?? 18;
    $pointsP3 = $settings['points_p3'] ?? 15;
    $pointsWrongPos = $settings['points_wrong_pos'] ?? 5;
    
    $stmt = $db->prepare("SELECT * FROM bets WHERE race_id = ?");
    $stmt->execute([$raceId]);
    $bets = $stmt->fetchAll();
    
    foreach ($bets as $bet) {
        $oldPoints = $bet['points'];
        $oldIsPerfect = $bet['is_perfect'];
        
        $points = 0;
        $predictions = [$bet['p1'], $bet['p2'], $bet['p3']];
        
        // Exact position points
        if ($bet['p1'] === $p1) $points += $pointsP1;
        if ($bet['p2'] === $p2) $points += $pointsP2;
        if ($bet['p3'] === $p3) $points += $pointsP3;
        
        // Bonus for drivers in top 3 but wrong position
        foreach ($predictions as $i => $pred) {
            $resultIndex = array_search($pred, $results);
            if ($resultIndex !== false && $resultIndex !== $i) {
                $points += $pointsWrongPos;
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

include __DIR__ . '/includes/header.php';
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
        <a href="?tab=races" class="tab <?= $currentTab === 'races' ? 'active' : '' ?>">
            <i class="fas fa-flag"></i> <?= t('races') ?> <span class="tab-count">(<?= count($races) ?>)</span>
        </a>
        <a href="?tab=drivers" class="tab <?= $currentTab === 'drivers' ? 'active' : '' ?>">
            <i class="fas fa-car"></i> <?= t('drivers') ?> <span class="tab-count">(<?= count($drivers) ?>)</span>
        </a>
        <a href="?tab=users" class="tab <?= $currentTab === 'users' ? 'active' : '' ?>">
            <i class="fas fa-users"></i> <?= t('users') ?> <span class="tab-count">(<?= count($users) ?>)</span>
        </a>
        <a href="?tab=invites" class="tab <?= $currentTab === 'invites' ? 'active' : '' ?>">
            <i class="fas fa-envelope"></i> <?= $lang === 'da' ? 'Invitationer' : 'Invites' ?> <span class="tab-count">(<?= count($invites) ?>)</span>
        </a>
        <a href="?tab=bets" class="tab <?= $currentTab === 'bets' ? 'active' : '' ?>">
            <i class="fas fa-trophy"></i> <?= t('bets') ?> <span class="tab-count">(<?= count($bets) ?>)</span>
        </a>
        <a href="?tab=settings" class="tab <?= $currentTab === 'settings' ? 'active' : '' ?>">
            <i class="fas fa-cog"></i> <?= t('settings') ?>
        </a>
    </div>
    
    <!-- RACES TAB -->
    <?php if ($currentTab === 'races'): ?>
        <div class="card mb-2" id="add-race-form">
            <div class="card-header collapsible-header" onclick="toggleForm('race-form-body')" id="race-form-header">
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
                            <a href="?tab=races&delete_race=<?= $race['id'] ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($race['name']) ?>"><i class="fas fa-trash"></i></a>
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
                                    <label class="form-label"><?= t('race_time') ?> (CET)</label>
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
                            <div class="flex gap-1">
                                <button type="submit" name="update_race" class="btn btn-primary"><?= t('save') ?></button>
                                <a href="?tab=races" class="btn btn-secondary"><?= t('cancel') ?></a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- DRIVERS TAB -->
    <?php if ($currentTab === 'drivers'): ?>
        <div class="card mb-2" id="add-driver-form">
            <div class="card-header collapsible-header" onclick="toggleForm('driver-form-body')" id="driver-form-header">
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
            <div class="card mb-1 <?= isset($_GET['edit']) && $_GET['edit'] === $driver['id'] ? 'edit-form-active' : '' ?>" id="driver-<?= $driver['id'] ?>">
                <div class="card-body flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-accent" style="font-weight: bold; font-size: 1.25rem;">#<?= $driver['number'] ?></span>
                        <div>
                            <strong><?= escape($driver['name']) ?></strong>
                            <br><small class="text-muted"><?= escape($driver['team']) ?></small>
                        </div>
                    </div>
                    <div class="flex gap-1">
                        <a href="?tab=drivers&edit=<?= $driver['id'] ?>#driver-<?= $driver['id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
                        <a href="?tab=drivers&delete_driver=<?= $driver['id'] ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($driver['name']) ?>"><i class="fas fa-trash"></i></a>
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
                                <input type="number" name="driver_number" class="form-input" value="<?= $driver['number'] ?>" required>
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
    <?php endif; ?>
    
    <!-- USERS TAB -->
    <?php if ($currentTab === 'users'): ?>
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
                    <?php if ($user['id'] !== $currentUser['id']): ?>
                        <div class="flex gap-1">
                            <a href="?tab=users&toggle_role=<?= escape($user['id']) ?>" class="btn btn-secondary btn-sm">
                                <?= $user['role'] === 'admin' ? ($lang === 'da' ? 'Gør Bruger' : 'Make User') : ($lang === 'da' ? 'Gør Admin' : 'Make Admin') ?>
                            </a>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('reset-pw-<?= escape($user['id']) ?>').classList.toggle('hidden')">
                                <i class="fas fa-key"></i>
                            </button>
                            <a href="?tab=users&delete_user=<?= escape($user['id']) ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($user['display_name'] ?: $user['email']) ?>"><i class="fas fa-trash"></i></a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($user['id'] !== $currentUser['id']): ?>
                    <div id="reset-pw-<?= escape($user['id']) ?>" class="hidden" style="padding: 1rem; border-top: 1px solid var(--border-color);">
                        <form method="POST" class="flex gap-1 items-end">
                            <?= csrfField() ?>
                            <input type="hidden" name="user_id" value="<?= escape($user['id']) ?>">
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
    <?php endif; ?>
    
    <!-- INVITES TAB -->
    <?php if ($currentTab === 'invites'): ?>
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
        $usedInvites = array_filter($invites, fn($i) => $i['used']);
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
                            <a href="?tab=invites&resend_invite=<?= $invite['id'] ?>" class="btn btn-secondary btn-sm" title="<?= $lang === 'da' ? 'Gensend' : 'Resend' ?>">
                                <i class="fas fa-redo"></i>
                            </a>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="copyInviteLink('<?= escape(SITE_URL . '/register.php?token=' . $invite['token']) ?>')" title="<?= $lang === 'da' ? 'Kopiér link' : 'Copy link' ?>">
                                <i class="fas fa-copy"></i>
                            </button>
                            <a href="?tab=invites&delete_invite=<?= $invite['id'] ?>" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($invite['email']) ?>">
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
                            <span class="badge" style="background: #10b981; color: white; margin-left: 0.5rem;">
                                <?= $lang === 'da' ? 'Registreret' : 'Registered' ?>
                            </span>
                            <br><small class="text-muted">
                                <?= $lang === 'da' ? 'Inviteret' : 'Invited' ?> <?= date('d M Y', strtotime($invite['created_at'])) ?>
                            </small>
                        </div>
                        <a href="?tab=invites&delete_invite=<?= $invite['id'] ?>" class="btn btn-ghost btn-sm">
                            <i class="fas fa-trash"></i>
                        </a>
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
                            <span class="badge" style="background: #ef4444; color: white; margin-left: 0.5rem;">
                                <?= $lang === 'da' ? 'Udløbet' : 'Expired' ?>
                            </span>
                            <br><small class="text-muted">
                                <?= $lang === 'da' ? 'Udløb' : 'Expired' ?> <?= date('d M Y', strtotime($invite['expires_at'])) ?>
                            </small>
                        </div>
                        <div class="flex gap-1">
                            <a href="?tab=invites&resend_invite=<?= $invite['id'] ?>" class="btn btn-secondary btn-sm" title="<?= $lang === 'da' ? 'Gensend' : 'Resend' ?>">
                                <i class="fas fa-redo"></i> <?= $lang === 'da' ? 'Forny' : 'Renew' ?>
                            </a>
                            <a href="?tab=invites&delete_invite=<?= $invite['id'] ?>" class="btn btn-ghost btn-sm">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if (empty($invites)): ?>
            <div class="card">
                <div class="card-body text-center text-muted">
                    <?= $lang === 'da' ? 'Ingen invitationer endnu' : 'No invites yet' ?>
                </div>
            </div>
        <?php endif; ?>
        
        <script>
        function copyInviteLink(link) {
            navigator.clipboard.writeText(link).then(function() {
                alert('<?= $lang === 'da' ? 'Link kopieret!' : 'Link copied!' ?>');
            });
        }
        </script>
    <?php endif; ?>
    
    <!-- BETS TAB -->
    <?php if ($currentTab === 'bets'): ?>
        <?php 
        // Hent race info for alle bets
        $racesById = [];
        foreach ($races as $r) {
            $racesById[$r['id']] = $r;
        }
        
        $betsByRace = [];
        foreach ($bets as $bet) {
            $betsByRace[$bet['race_id']][] = $bet;
        }
        $bettingWindowHours = $settings['betting_window_hours'] ?? 48;
        ?>
        <?php foreach ($betsByRace as $raceId => $raceBets): 
            $raceName = $raceBets[0]['race_name'];
            $raceData = $racesById[$raceId] ?? null;
            
            // Tjek betting status for dette løb
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
    <?php endif; ?>
    
    <!-- SETTINGS TAB -->
    <?php if ($currentTab === 'settings'): ?>
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
                            <label class="form-label flex items-center gap-1">
                                <span class="position-badge position-1">P1</span> <?= $lang === 'da' ? 'Point' : 'Points' ?>
                            </label>
                            <input type="number" name="points_p1" class="form-input" value="<?= intval($settings['points_p1'] ?? 25) ?>" min="0" max="100">
                        </div>
                        <div class="form-group">
                            <label class="form-label flex items-center gap-1">
                                <span class="position-badge position-2">P2</span> <?= $lang === 'da' ? 'Point' : 'Points' ?>
                            </label>
                            <input type="number" name="points_p2" class="form-input" value="<?= intval($settings['points_p2'] ?? 18) ?>" min="0" max="100">
                        </div>
                        <div class="form-group">
                            <label class="form-label flex items-center gap-1">
                                <span class="position-badge position-3">P3</span> <?= $lang === 'da' ? 'Point' : 'Points' ?>
                            </label>
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
                    
                    <button type="submit" name="update_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?= t('save') ?>
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
