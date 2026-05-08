<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/scoring.php';
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
$settings = getSettings();

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
    $number = sanitizeInt($_POST['driver_number'] ?? 0, 1, 99);
    
    if ($id && $name && $team && $number) {
        $stmt = $db->prepare("UPDATE drivers SET name = ?, team = ?, number = ? WHERE id = ?");
        $stmt->execute([$name, $team, $number, $id]);
        $message = $lang === 'da' ? 'Kører opdateret!' : 'Driver updated!';
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

if (isset($_POST['delete_race'])) {
    $id = $_POST['race_id'];
    $stmt = $db->prepare("DELETE FROM bets WHERE race_id = ?");
    $stmt->execute([$id]);
    $stmt = $db->prepare("DELETE FROM races WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: admin.php?tab=races&msg=deleted");
    exit;
}

// ============ USERS ============
if (isset($_POST['toggle_role'])) {
    $userId = $_POST['user_id'];
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

if (isset($_POST['toggle_competition'])) {
    $userId = $_POST['user_id'];
    $stmt = $db->prepare("SELECT in_competition FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if ($user) {
        $newStatus = $user['in_competition'] ? 0 : 1;
        $stmt = $db->prepare("UPDATE users SET in_competition = ? WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);
        $message = $lang === 'da' 
            ? ($newStatus ? 'Bruger tilføjet til konkurrence' : 'Bruger fjernet fra konkurrence')
            : ($newStatus ? 'User added to competition' : 'User removed from competition');
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
        $message = $lang === 'da' ? 'Adgangskode nulstillet!' : 'Password reset!';

        // Send email til bruger
        require_once __DIR__ . '/includes/smtp.php';
        $appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
        
        if ($lang === 'da') {
            $subject = "Dit kodeord er nulstillet! - $appName";
            $greeting = "Hej " . $userName . ",";
            $intro = "Dit kodeord er blevet nulstillet af " . $currentUser['display_name'] . ". Den nye kode er: '" . $newPassword . "'";
            $buttonText = "Gå til appen";
            $expiry = "Kontakt administrator hvis du har spørgsmål.";
            $regards = "Venlig hilsen,<br>$appName";
        } else {
            $subject = "Your password has been reset! - $appName";
            $greeting = "Hi " . $userName . ",";
            $intro = "Your password has been reset by " . $currentUser['display_name'] . "). The new password is: '" . $newPassword . "'";
            $buttonText = "Go to app";
            $expiry = "Contact administrator if you have questions.";
            $regards = "Regards,<br>$appName";
        }
        
        $emailBaseUrl = defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : SITE_URL;
        $htmlContent = getEmailTemplate($greeting, $intro, $buttonText, $emailBaseUrl, $expiry, '', $regards, $appName);
        sendEmail($userEmail, $subject, $htmlContent);

    } else {
        $error = $lang === 'da' ? 'Adgangskoden skal være mindst 6 tegn' : 'Password must be at least 6 characters';
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
            
            $emailBaseUrl = defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : SITE_URL;
            $htmlContent = getEmailTemplate($greeting, $intro, $buttonText, $emailBaseUrl, $expiry, '', "Best regards,<br>$appName", $appName);
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
    $bettingWindowHours = sanitizeInt($_POST['betting_window_hours'] ?? 48, 1, 168);
    $betSize = intval($_POST['bet_size'] ?? 10);
    
    $stmt = $db->prepare("UPDATE settings SET app_title = ?, app_year = ?, hero_title_en = ?, hero_title_da = ?, hero_text_en = ?, hero_text_da = ?, points_p1 = ?, points_p2 = ?, points_p3 = ?, points_wrong_pos = ?, betting_window_hours = ?, bet_size = ? WHERE id = 1");
    $stmt->execute([$appTitle, $appYear, $heroTitleEn, $heroTitleDa, $heroTextEn, $heroTextDa, $pointsP1, $pointsP2, $pointsP3, $pointsWrongPos, $bettingWindowHours, $betSize]);
    $message = $lang === 'da' ? 'Indstillinger gemt!' : 'Settings saved!';
}

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

<?php include __DIR__ . '/includes/footer.php'; ?>
