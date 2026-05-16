<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/scoring.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();
$lang = getLang();

// Validate CSRF for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
}

// Sanitization convention:
//   sanitizeString() — for values echoed directly back to the page without further escaping
//   trim()           — for values stored in the DB and escaped on output via escape()
//   sanitizeEmail()  — for email inputs (trim + format validation)
//   sanitizeInt()    — for numeric inputs

// Handle actions
$message = '';
$error = '';
$settings = getSettings();

// E2E test mode: skip SMTP and collect markers for Playwright assertions.
// Gated by INTEGRATION_SEED_TOKEN so it only activates in the test environment.
$testMode = defined('INTEGRATION_SEED_TOKEN') && !empty($_GET['e2e_token']) && $_GET['e2e_token'] === INTEGRATION_SEED_TOKEN;
$emailTestOutput = [];
// Markers passed through a redirect are base64-encoded in e2e_markers.
if ($testMode && !empty($_GET['e2e_markers'])) {
    foreach (explode("\n", base64_decode($_GET['e2e_markers'])) as $m) {
        if (trim($m) !== '') $emailTestOutput[] = trim($m);
    }
}

// ============ DRIVERS ============
if (isset($_POST['add_driver'])) {
    $name = sanitizeString($_POST['driver_name'] ?? '');
    $team = sanitizeString($_POST['driver_team'] ?? '');
    $number = sanitizeInt($_POST['driver_number'] ?? 0, 1, 99);
    
    if ($name && $team && $number) {
        $id = generateUUID();
        $stmt = $db->prepare("INSERT INTO drivers (id, name, team, number) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $name, $team, $number]);
        $message = t('driver_added');
    } else {
        $error = t('driver_fields_error');
    }
}

if (isset($_POST['update_driver'])) {
    $id = sanitizeString($_POST['driver_id'] ?? '');
    $name = sanitizeString($_POST['driver_name'] ?? '');
    $team = sanitizeString($_POST['driver_team'] ?? '');
    $number = sanitizeInt($_POST['driver_number'] ?? 0, 1, 99);
    
    if ($id && $name && $team && $number) {
        $stmt = $db->prepare("UPDATE drivers SET name = ?, team = ?, number = ? WHERE id = ?");
        $stmt->execute([$name, $team, $number, $id]);
        $message = t('driver_updated');
    } else {
        $error = t('driver_fields_error');
    }
}

if (isset($_POST['delete_driver'])) {
    $id = $_POST['driver_id'];
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
    $quali_p1 = $_POST['quali_p1'] ?? null;
    $quali_p2 = $_POST['quali_p2'] ?? null;
    $quali_p3 = $_POST['quali_p3'] ?? null;
    
    if ($name && $location && $date && $time) {
        // Validate date format
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        $timeObj = DateTime::createFromFormat('H:i', $time);
        
        if ($dateObj && $timeObj) {
            $id = generateUUID();
            $stmt = $db->prepare("INSERT INTO races (id, name, location, race_date, race_time, quali_p1, quali_p2, quali_p3) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $name, $location, $date, $time, $quali_p1, $quali_p2, $quali_p3]);
            $message = t('race_added');
        } else {
            $error = t('invalid_date_time');
        }
    } else {
        $error = t('fill_all_fields');
    }
}

if (isset($_POST['update_race'])) {
    $id = $_POST['race_id'] ?? '';
    $name = trim($_POST['race_name'] ?? '');
    $location = trim($_POST['race_location'] ?? '');
    $date = $_POST['race_date'] ?? '';
    $time = $_POST['race_time'] ?? '';
    $quali_p1 = $_POST['quali_p1'] ?? null;
    $quali_p2 = $_POST['quali_p2'] ?? null;
    $quali_p3 = $_POST['quali_p3'] ?? null;
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

        $message = t('race_updated');
    } else {
        $error = t('name_required');
    }
}

if (isset($_POST['delete_race'])) {
    $id = $_POST['race_id'];
    $stmt = $db->prepare("DELETE FROM bets WHERE race_id = ?");
    $stmt->execute([$id]);
    $stmt = $db->prepare("DELETE FROM races WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: admin.php?tab=races&msg=deleted");
    exit;
}

if (isset($_POST['reset_race_result'])) {
    $id = $_POST['race_id'] ?? '';

    // Safety: only allow resetting the most recently completed race
    $lastId = $db->query("SELECT id FROM races WHERE result_p1 IS NOT NULL ORDER BY race_date DESC LIMIT 1")->fetchColumn();

    if ($lastId && $lastId === $id) {
        $stmtRace = $db->prepare("SELECT * FROM races WHERE id = ?");
        $stmtRace->execute([$id]);
        $race = $stmtRace->fetch();

        $db->prepare("
            UPDATE users u
            JOIN bets b ON u.id = b.user_id
            SET u.points = GREATEST(0, u.points - b.points),
                u.stars  = GREATEST(0, u.stars  - b.is_perfect)
            WHERE b.race_id = ?
        ")->execute([$id]);
        $db->prepare("UPDATE bets SET points = 0, is_perfect = 0 WHERE race_id = ?")
           ->execute([$id]);

        // Undo pool rollover to next race if no one won
        if (!$race['bettingpool_won']) {
            $stmtNext = $db->prepare("SELECT id, bettingpool_size FROM races WHERE race_date > ? ORDER BY race_date ASC LIMIT 1");
            $stmtNext->execute([$race['race_date']]);
            $nextRace = $stmtNext->fetch();
            if ($nextRace) {
                $db->prepare("UPDATE races SET bettingpool_size = ? WHERE id = ?")
                   ->execute([max(0, $nextRace['bettingpool_size'] - $race['bettingpool_size']), $nextRace['id']]);
            }
        }

        $db->prepare("UPDATE races SET result_p1 = NULL, result_p2 = NULL, result_p3 = NULL, bettingpool_won = NULL WHERE id = ?")
           ->execute([$id]);

        $resetMsg = t('result_reset');
        header("Location: admin.php?tab=races&edit=" . urlencode($id) . "&msg=" . urlencode($resetMsg));
        exit;
    } else {
        $error = t('reset_most_recent_only');
    }
}

// ============ USERS ============
if (isset($_POST['toggle_role'])) {
    $userId = $_POST['user_id'];
    if ($userId !== $currentUser['id']) {
        $db->prepare("UPDATE users SET role = IF(role = 'admin', 'user', 'admin') WHERE id = ?")
           ->execute([$userId]);
    }
    header("Location: admin.php?tab=users");
    exit;
}

if (isset($_POST['toggle_competition'])) {
    $userId = $_POST['user_id'];
    $stmt = $db->prepare("SELECT in_competition FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if ($user) {
        $newStatus = $user['in_competition'] ? 0 : 1;
        $stmt = $db->prepare("UPDATE users SET in_competition = ? WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);
        $message = $newStatus ? t('user_added_to_competition') : t('user_removed_from_competition');
    }
    header("Location: admin.php?tab=users&msg=" . urlencode($message));
    exit;
}

if (isset($_POST['delete_user'])) {
    $userId = $_POST['user_id'];
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
    $userEmail = $_POST['user_email'] ?? '';
    $userName = $_POST['user_name'] ?? '';

    if ($userId && strlen($newPassword) >= 6) {
        $hashedPassword = hashPassword($newPassword);
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $userId]);
        $message = t('password_reset_success');

        // Send email til bruger
        require_once __DIR__ . '/includes/smtp.php';
        $appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
        
        $subject    = sprintf(t('email_admin_reset_subject', $lang), $appName);
        $greeting   = sprintf(t('email_admin_reset_greeting', $lang), $userName);
        $intro      = sprintf(t('email_admin_reset_intro', $lang), $currentUser['display_name'], $newPassword);
        $buttonText = t('email_admin_reset_button', $lang);
        $expiry     = t('email_admin_contact', $lang);
        $regards    = sprintf(t('email_regards', $lang), $appName);
        
        $emailBaseUrl = defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : SITE_URL;
        $htmlContent = getEmailTemplate($greeting, $intro, $buttonText, $emailBaseUrl, $expiry, '', $regards, $appName);
        if ($testMode) {
            $emailTestOutput[] = "[admin-reset-to] {$userEmail}";
            $emailTestOutput[] = "[admin-reset-subject] {$subject}";
            $emailTestOutput[] = "[admin-reset-new-password] {$newPassword}";
        } else {
            sendEmail($userEmail, $subject, $htmlContent);
        }

    } else {
        $error = t('password_min_6_admin');
    }


}

// ============ DELETE BET ============
if (isset($_POST['delete_bet'])) {
    $betId = $_POST['bet_id'];
    
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
                $db->prepare("UPDATE users SET points = GREATEST(0, points - ?), stars = GREATEST(0, stars - ?) WHERE id = ?")
                   ->execute([$bet['points'], $bet['is_perfect'] ? 1 : 0, $bet['bet_user_id']]);
            }
            
            // Slet bet
            $stmt = $db->prepare("DELETE FROM bets WHERE id = ?");
            $stmt->execute([$betId]);
            
            // Send email til bruger
            require_once __DIR__ . '/includes/smtp.php';
            $appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
            
            $userName   = $bet['display_name'] ?: $bet['email'];
            $subject    = sprintf(t('email_bet_deleted_subject', $lang), $appName);
            $greeting   = sprintf(t('email_bet_deleted_greeting', $lang), $userName);
            $intro      = sprintf(t('email_bet_deleted_intro', $lang), htmlspecialchars($bet['race_name']));
            $buttonText = t('email_go_to_app', $lang);
            $expiry     = t('email_contact_admin', $lang);
            
            $emailBaseUrl = defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : SITE_URL;
            $regards     = sprintf(t('email_regards', $lang), $appName);
            $htmlContent = getEmailTemplate($greeting, $intro, $buttonText, $emailBaseUrl, $expiry, '', $regards, $appName);
            if ($testMode) {
                $betMarkers = "[bet-deleted-to] {$bet['email']}\n[bet-deleted-race] {$bet['race_name']}";
            } else {
                sendEmail($bet['email'], $subject, $htmlContent);
            }

            $message = t('bet_deleted_notified');
        } else {
            $message = t('bet_delete_open_only');
        }
    }

    $redirectExtra = ($testMode && !empty($betMarkers ?? ''))
        ? '&e2e_token=' . urlencode($_GET['e2e_token']) . '&e2e_markers=' . urlencode(base64_encode($betMarkers))
        : '';
    header("Location: admin.php?tab=bets&msg=" . urlencode($message) . $redirectExtra);
    exit;
}

// ============ INVITES ============
if (isset($_POST['create_invite'])) {
    $inviteEmail = sanitizeEmail($_POST['invite_email'] ?? '');

    if ($inviteEmail) {
        // Tjek om email allerede findes som bruger
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$inviteEmail]);
        if ($stmt->fetch()) {
            $error = t('email_already_user');
        } else {
            // Tjek om der allerede er en aktiv invite
            $stmt = $db->prepare("SELECT id FROM invites WHERE email = ? AND used = 0 AND expires_at > NOW()");
            $stmt->execute([$inviteEmail]);
            if ($stmt->fetch()) {
                $error = t('active_invite_exists');
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
                if ($testMode) {
                    $emailTestOutput[] = "[invite-to] {$inviteEmail}";
                    $emailTestOutput[] = "[invite-link] {$inviteLink}";
                    $message = sprintf(t('invite_sent_to'), $inviteEmail);
                } else {
                    $result = sendInviteEmail($inviteEmail, $inviteLink, $currentUser['display_name'] ?: $currentUser['email'], $lang);
                    if ($result['success']) {
                        $message = sprintf(t('invite_sent_to'), $inviteEmail);
                    } else {
                        $message = $lang === 'da'
                            ? 'Invitation oprettet! Email kunne ikke sendes. Del linket manuelt:<br><code style="word-break:break-all;font-size:0.75rem;">' . $inviteLink . '</code>'
                            : 'Invitation created! Email could not be sent. Share link manually:<br><code style="word-break:break-all;font-size:0.75rem;">' . $inviteLink . '</code>';
                    }
                }
            }
        }
    } else {
        $error = t('invalid_email');
    }
}

if (isset($_POST['delete_invite'])) {
    $inviteId = intval($_POST['invite_id']);
    $stmt = $db->prepare("DELETE FROM invites WHERE id = ?");
    $stmt->execute([$inviteId]);
    header("Location: admin.php?tab=invites&msg=deleted");
    exit;
}

if (isset($_POST['resend_invite'])) {
    $inviteId = intval($_POST['invite_id']);
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
            $message = t('invite_resent');
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
    $bettingWindowHours = sanitizeInt($_POST['betting_window_hours'] ?? 48, 1, 168);
    $betSize = intval($_POST['bet_size'] ?? 10);
    
    $stmt = $db->prepare("UPDATE settings SET app_title = ?, app_year = ?, hero_title_en = ?, hero_title_da = ?, hero_text_en = ?, hero_text_da = ?, points_p1 = ?, points_p2 = ?, points_p3 = ?, points_wrong_pos = ?, betting_window_hours = ?, bet_size = ? WHERE id = 1");
    $stmt->execute([$appTitle, $appYear, $heroTitleEn, $heroTitleDa, $heroTextEn, $heroTextDa, $pointsP1, $pointsP2, $pointsP3, $pointsWrongPos, $bettingWindowHours, $betSize]);
    $message = t('settings_saved');
}

$currentTab = $_GET['tab'] ?? 'races';

$tabIcons = [
    'races'   => 'flag',
    'drivers' => 'car',
    'users'   => 'users',
    'invites' => 'envelope',
    'bets'    => 'trophy',
    'settings'=> 'cog',
];

// Tab count badges — lightweight COUNT queries for all tabs
$tabCounts = [
    'races'   => $db->query("SELECT COUNT(*) FROM races")->fetchColumn(),
    'drivers' => $db->query("SELECT COUNT(*) FROM drivers")->fetchColumn(),
    'users'   => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'invites' => $db->query("SELECT COUNT(*) FROM invites")->fetchColumn(),
    'bets'    => $db->query("SELECT COUNT(*) FROM bets")->fetchColumn(),
];

// $drivers always needed: races add/edit dropdowns and bets display
[$drivers, $driversById] = fetchDrivers($db);

switch ($currentTab) {
    case 'races':
        $races = $db->query("SELECT * FROM races ORDER BY race_date ASC")->fetchAll();
        $lastCompletedRaceId = $db->query("SELECT id FROM races WHERE result_p1 IS NOT NULL ORDER BY race_date DESC LIMIT 1")->fetchColumn() ?: null;
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

include __DIR__ . '/includes/header.php';
?>

<h1 class="mb-3"><i class="fas fa-cog text-accent"></i> <?= t('admin') ?></h1>

<?php if ($message): ?>
    <div class="alert alert-success"><?= escape($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= escape($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] !== ''): ?>
    <div class="alert alert-success"><?= escape(urldecode($_GET['msg'])) ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="admin-shell">

    <!-- Mobile dropdown -->
    <details class="admin-dropdown">
        <summary>
            <span class="label">
                <i class="fas fa-<?= $tabIcons[$currentTab] ?>"></i>
                <?= t($currentTab) ?>
            </span>
            <i class="fas fa-chevron-down chev"></i>
        </summary>
        <div class="menu">
            <?php foreach ($tabIcons as $key => $icon): ?>
                <a href="?tab=<?= $key ?>" class="<?= $currentTab === $key ? 'active' : '' ?>">
                    <span class="l">
                        <i class="fas fa-<?= $icon ?>"></i> <?= t($key) ?>
                    </span>
                    <?php if (!empty($tabCounts[$key])): ?>
                        <span class="tab-count">(<?= $tabCounts[$key] ?>)</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </details>

    <!-- Desktop tabs -->
    <div class="tabs">
        <?php foreach ($tabIcons as $key => $icon): ?>
            <a href="?tab=<?= $key ?>" class="tab <?= $currentTab === $key ? 'active' : '' ?>">
                <i class="fas fa-<?= $icon ?>"></i> <?= t($key) ?>
                <?php if (!empty($tabCounts[$key])): ?>
                    <span class="tab-count">(<?= $tabCounts[$key] ?>)</span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    
    
    <?php
    $allowedTabs = ['races', 'drivers', 'users', 'bets', 'invites', 'settings'];
    if (in_array($currentTab, $allowedTabs)) {
        include __DIR__ . "/includes/admin/{$currentTab}.php";
    }
    ?>
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

<?php if ($testMode && !empty($emailTestOutput)): ?>
<pre id="e2e-email-output" style="display:none"><?= implode("\n", array_map('escape', $emailTestOutput)) ?></pre>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
