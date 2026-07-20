<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/scoring.php';
require_once __DIR__ . '/includes/challenges.php';
require_once __DIR__ . '/includes/smtp.php';
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
$_e2eRawToken = $_GET['e2e_token'] ?? $_POST['e2e_token'] ?? '';
$testMode = defined('INTEGRATION_SEED_TOKEN') && !empty($_e2eRawToken) && $_e2eRawToken === INTEGRATION_SEED_TOKEN;
$emailTestOutput = [];
// Markers passed through a redirect are base64-encoded in e2e_markers.
if ($testMode && !empty($_GET['e2e_markers'])) {
    foreach (explode("\n", base64_decode($_GET['e2e_markers'])) as $m) {
        if (trim($m) !== '') $emailTestOutput[] = trim($m);
    }
}

// ============ EMAIL DELIVERY MODE (test env only) ============
// On the test env real delivery is the default; this lets the admin switch to capture
// (interception) for manual testing. E2E manages interception itself (on for a run, off after).
if (isset($_POST['toggle_smtp_live']) && defined('SMTP_INTERCEPT') && SMTP_INTERCEPT) {
    require_once __DIR__ . '/includes/smtp.php';
    if (emailIntercepted()) {
        @unlink(smtpInterceptFlagPath());                  // back to real delivery (default)
    } else {
        file_put_contents(smtpInterceptFlagPath(), '1');   // switch to capture
    }
    header('Location: admin.php?tab=settings');
    exit;
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
    $quali_date = ($_POST['quali_date'] ?? '') ?: null;
    $quali_time = ($_POST['quali_time'] ?? '') ?: null;
    $quali_p1 = $_POST['quali_p1'] ?: null;
    $quali_p2 = $_POST['quali_p2'] ?: null;
    $quali_p3 = $_POST['quali_p3'] ?: null;

    if ($name && $location && $date && $time) {
        // Validate date format
        $dateObj = DateTime::createFromFormat('Y-m-d', $date);
        $timeObj = DateTime::createFromFormat('H:i', $time);
        if ($quali_date && !DateTime::createFromFormat('Y-m-d', $quali_date)) { $quali_date = null; }

        if ($dateObj && $timeObj) {
            $id = generateUUID();
            $stmt = $db->prepare("INSERT INTO races (id, name, location, race_date, race_time, quali_date, quali_time, quali_p1, quali_p2, quali_p3) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $name, $location, $date, $time, $quali_date, $quali_time, $quali_p1, $quali_p2, $quali_p3]);
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
    $quali_date = ($_POST['quali_date'] ?? '') ?: null;
    $quali_time = ($_POST['quali_time'] ?? '') ?: null;
    $quali_p1 = $_POST['quali_p1'] ?: null;
    $quali_p2 = $_POST['quali_p2'] ?: null;
    $quali_p3 = $_POST['quali_p3'] ?: null;
    $result_p1 = $_POST['result_p1'] ?: null;
    $result_p2 = $_POST['result_p2'] ?: null;
    $result_p3 = $_POST['result_p3'] ?: null;
    if ($quali_date && !DateTime::createFromFormat('Y-m-d', $quali_date)) { $quali_date = null; }

    if ($id && $name) {
        $stmt = $db->prepare("UPDATE races SET name = ?, location = ?, race_date = ?, race_time = ?, quali_date = ?, quali_time = ?, quali_p1 = ?, quali_p2 = ?, quali_p3 = ?, result_p1 = ?, result_p2 = ?, result_p3 = ? WHERE id = ?");
        $stmt->execute([$name, $location, $date, $time, $quali_date, $quali_time, $quali_p1, $quali_p2, $quali_p3, $result_p1, $result_p2, $result_p3, $id]);

        // Beregn point hvis resultater er sat
        if ($result_p1 && $result_p2 && $result_p3) {
            calculateRacePoints($id, $result_p1, $result_p2, $result_p3);
            // Additive, isolated from core scoring (REQ-309) — never touches bets/points/pool.
            resolveDuelsForRace($db, $id, [$result_p1, $result_p2, $result_p3]);
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
        $db->prepare("DELETE FROM leaderboard_snapshots WHERE race_id = ?")->execute([$id]);
        // Additive, isolated from core scoring (REQ-310) — never touches bets/points/pool.
        resetDuelsForRace($db, $id);

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

    $pwError = validatePasswordStrength($newPassword);

    if ($userId && !$pwError) {
        $hashedPassword = hashPassword($newPassword);
        // F12: also invalidates any of this user's other active sessions on their next
        // request (getCurrentUser()) — appropriate here since an admin-initiated reset
        // usually means the account owner no longer trusts their current session(s).
        $stmt = $db->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?");
        $stmt->execute([$hashedPassword, $userId]);
        $message = t('password_reset_success');

        // Send email til bruger
        require_once __DIR__ . '/includes/smtp.php';
        $appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';

        $stmt2 = $db->prepare("SELECT language FROM users WHERE id = ?");
        $stmt2->execute([$userId]);
        $row2     = $stmt2->fetch();
        $userLang = in_array($row2['language'] ?? '', ['da', 'en']) ? $row2['language'] : 'da';

        $subject    = t('email_admin_reset_subject', $userLang);
        $greeting   = sprintf(t('email_admin_reset_greeting', $userLang), $userName);
        $intro      = sprintf(t('email_admin_reset_intro', $userLang), $currentUser['display_name'], $newPassword);
        $buttonText = t('email_admin_reset_button', $userLang);
        $expiry     = t('email_admin_contact', $userLang);
        $regards    = sprintf(t('email_regards', $userLang), $appName);
        
        $emailBaseUrl = defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : SITE_URL;
        $htmlContent = getEmailTemplate($greeting, $intro, $buttonText, $emailBaseUrl, $expiry, '', $regards, $appName);
        if ($testMode) {
            $emailTestOutput[] = "[admin-reset-to] {$userEmail}";
            $emailTestOutput[] = "[admin-reset-subject] {$subject}";
            $emailTestOutput[] = "[admin-reset-new-password] {$newPassword}";
            $emailTestOutput[] = "[admin-reset-lang] {$userLang}";
        }
        $sendResult = sendEmail($userEmail, $subject, $htmlContent);
        if ($testMode) {
            $emailTestOutput[] = "[admin-reset-sent] " . ($sendResult['success'] ? 'true' : 'false');
            if (!$sendResult['success']) {
                $emailTestOutput[] = "[admin-reset-error] " . ($sendResult['message'] ?? 'unknown');
            }
        }

        $redirectExtra = ($testMode && !empty($emailTestOutput))
            ? '&e2e_token=' . urlencode($_e2eRawToken) . '&e2e_markers=' . urlencode(base64_encode(implode("\n", $emailTestOutput)))
            : '';
        header("Location: admin.php?tab=users&msg=" . urlencode($message) . $redirectExtra);
        exit;
    } else {
        $error = $pwError ?: t('password_min_length');
    }

}

// ============ REMOVE USER TWO-STEP FACTORS ============
// Support/lockout recovery: strips passkeys + TOTP + recovery codes and turns
// off email OTP, returning the member's login to password-only. Companion to
// the admin password reset above — together they recover any lockout (lost
// device, lost codes) without direct DB access.
if (isset($_POST['remove_user_mfa'])) {
    $userId = $_POST['user_id'] ?? '';
    $stmt = $db->prepare("SELECT id, email, display_name, language FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $target = $stmt->fetch();

    if ($target) {
        $pk = $db->prepare("DELETE FROM user_passkeys WHERE user_id = ?");
        $pk->execute([$target['id']]);
        $tp = $db->prepare("DELETE FROM user_totp WHERE user_id = ?");
        $tp->execute([$target['id']]);
        // Recovery codes go too: with no factors they are dead weight, and stale
        // rows would stop ensureRecoveryCodes() issuing fresh ones on re-enrollment.
        $rc = $db->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?");
        $rc->execute([$target['id']]);
        $db->prepare("UPDATE users SET email_otp_enabled = 0, mfa_default_method = NULL WHERE id = ?")
           ->execute([$target['id']]);

        logToFile(APP_LOG_FILE, '[ADMIN] ' . $currentUser['email'] . ' removed two-step factors for '
            . $target['email'] . ' (passkeys ' . $pk->rowCount() . ', totp ' . $tp->rowCount()
            . ', recovery codes ' . $rc->rowCount() . ')');

        // Notify the member — silent 2FA removal must be distinguishable from an attack.
        require_once __DIR__ . '/includes/smtp.php';
        $appName  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';
        $userLang = in_array($target['language'] ?? '', ['da', 'en']) ? $target['language'] : 'da';

        $subject  = t('email_mfa_removed_subject', $userLang);
        $greeting = sprintf(t('email_mfa_removed_greeting', $userLang), $target['display_name']);
        $intro    = sprintf(t('email_mfa_removed_intro', $userLang), $currentUser['display_name']);
        $regards  = sprintf(t('email_regards', $userLang), $appName);
        $emailBaseUrl = defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : SITE_URL;
        $htmlContent  = getEmailTemplate($greeting, $intro, t('email_admin_reset_button', $userLang),
            $emailBaseUrl, t('email_admin_contact', $userLang), '', $regards, $appName);
        sendEmail($target['email'], $subject, $htmlContent);
    }

    header("Location: admin.php?tab=users&msg=" . urlencode(t('mfa_removed_success')));
    exit;
}

// ============ DELETE BET ============
if (isset($_POST['delete_bet'])) {
    $betId = $_POST['bet_id'];
    
    // Hent bet info inkl race data for at tjekke betting status
    $stmt = $db->prepare("
        SELECT b.*, u.email, u.display_name, u.language, u.id as bet_user_id,
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
            $betLang    = in_array($bet['language'] ?? '', ['da', 'en']) ? $bet['language'] : 'da';
            $subject    = t('email_bet_deleted_subject', $betLang);
            $greeting   = sprintf(t('email_bet_deleted_greeting', $betLang), $userName);
            $intro      = sprintf(t('email_bet_deleted_intro', $betLang), htmlspecialchars($bet['race_name']));
            $buttonText = t('email_go_to_app', $betLang);
            $expiry     = t('email_contact_admin', $betLang);

            $emailBaseUrl = defined('EMAIL_BASE_URL') ? EMAIL_BASE_URL : SITE_URL;
            $regards     = sprintf(t('email_regards', $betLang), $appName);
            $htmlContent = getEmailTemplate($greeting, $intro, $buttonText, $emailBaseUrl, $expiry, '', $regards, $appName);
            $betSendResult = sendEmail($bet['email'], $subject, $htmlContent);
            if ($testMode) {
                $betMarkers = "[bet-deleted-to] {$bet['email']}\n[bet-deleted-race] {$bet['race_name']}\n[bet-deleted-lang] {$betLang}";
                $betMarkers .= "\n[bet-deleted-sent] " . ($betSendResult['success'] ? 'true' : 'false');
                if (!$betSendResult['success']) {
                    $betMarkers .= "\n[bet-deleted-error] " . ($betSendResult['message'] ?? 'unknown');
                }
            }

            $message = t('bet_deleted_notified');
        } else {
            $message = t('bet_delete_open_only');
        }
    }

    $betMarkers ??= '';
    $redirectExtra = ($testMode && !empty($betMarkers))
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
                }
                $result = sendInviteEmail($inviteEmail, $inviteLink, $currentUser['display_name'] ?: $currentUser['email'], $lang);
                if ($testMode) {
                    $emailTestOutput[] = "[invite-sent] " . ($result['success'] ? 'true' : 'false');
                    if (!$result['success']) {
                        $emailTestOutput[] = "[invite-error] " . ($result['message'] ?? 'unknown');
                    }
                }
                if ($result['success']) {
                    $message = sprintf(t('invite_sent_to'), $inviteEmail);
                } else {
                    $message = sprintf(t('invite_created_email_failed'), $inviteLink);
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
            $message = sprintf(t('invite_extended_email_failed'), $inviteLink);
        }
    }
    header("Location: admin.php?tab=invites&msg=" . urlencode($message));
    exit;
}

// ============ LEADERBOARD MAINTENANCE ============
if (isset($_POST['backfill_snapshots'])) {
    $scoredRaces = $db->query(
        "SELECT id, race_date, race_time FROM races
         WHERE result_p1 IS NOT NULL
         ORDER BY race_date ASC, race_time ASC"
    )->fetchAll();

    $insert = $db->prepare(
        "INSERT INTO leaderboard_snapshots (user_id, race_id, `rank`, points)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE `rank` = VALUES(`rank`), points = VALUES(points)"
    );

    foreach ($scoredRaces as $race) {
        $standings = $db->prepare(
            "SELECT u.id AS user_id,
                    COALESCE(SUM(sb.points), 0)     AS cum_points,
                    COALESCE(SUM(sb.is_perfect), 0) AS cum_stars
             FROM users u
             LEFT JOIN (
                 SELECT b.user_id, b.points, b.is_perfect
                 FROM bets b
                 JOIN races r ON b.race_id = r.id
                 WHERE r.result_p1 IS NOT NULL
                   AND (r.race_date < ? OR (r.race_date = ? AND r.race_time <= ?))
             ) sb ON sb.user_id = u.id
             WHERE u.in_competition = 1
             GROUP BY u.id
             ORDER BY cum_stars DESC, cum_points DESC"
        );
        $standings->execute([$race['race_date'], $race['race_date'], $race['race_time']]);
        $rows = $standings->fetchAll();

        foreach ($rows as $i => $row) {
            $insert->execute([$row['user_id'], $race['id'], $i + 1, $row['cum_points']]);
        }
    }

    $message = sprintf(t('backfill_snapshots_done'), count($scoredRaces));
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

// ============ SECURITY: CLEAR LOGIN ATTEMPTS ============
// Account-only, same as clearLoginAttempts() itself — there is deliberately no way to
// clear the IP-wide bucket from here (see the comment on clearLoginAttempts()).
if (isset($_POST['clear_login_attempts'])) {
    $scope   = $_POST['scope'] ?? '';
    $account = $_POST['account'] ?? '';
    if (in_array($scope, ['login', 'mfa'], true) && $account !== '') {
        clearLoginAttempts($db, $scope, $account);
    }
    header("Location: admin.php?tab=security&msg=" . urlencode(t('login_attempts_cleared')));
    exit;
}

$currentTab = $_GET['tab'] ?? 'races';

$tabIcons = [
    'races'   => 'flag',
    'drivers' => 'car',
    'users'   => 'users',
    'invites' => 'envelope',
    'bets'    => 'trophy',
    'security'=> 'shield-halved',
    'settings'=> 'cog',
];

// Tab count badges — lightweight COUNT queries for all tabs
$tabCounts = [
    'races'   => $db->query("SELECT COUNT(*) FROM races")->fetchColumn(),
    'drivers' => $db->query("SELECT COUNT(*) FROM drivers")->fetchColumn(),
    'users'   => $db->query("SELECT COUNT(*) FROM users u WHERE u.id NOT IN (
                    SELECT core_user_id FROM challenge_participants
                    WHERE core_user_id IS NOT NULL AND email IS NOT NULL
                  )")->fetchColumn(),
    'invites' => $db->query("SELECT COUNT(*) FROM invites")->fetchColumn(),
    'bets'    => $db->query("SELECT COUNT(*) FROM bets")->fetchColumn(),
];

// Pending Challenges promotion requests — badges the standalone admin-challenges.php link below.
$challengesPromoCount = (int) $db->query("
    SELECT COUNT(*) FROM challenge_participants
    WHERE promotion_requested_at IS NOT NULL AND core_user_id IS NULL
")->fetchColumn();

// Security tab: group login_attempts by IP and by account over the same 15-minute
// window isRateLimited() checks, so the badge count and the tab body always agree
// with the actual lockout state. Fetched here (not just in the switch below) so the
// badge reflects it on every tab, matching the other count badges.
$ipBuckets = $db->query(
    "SELECT ip AS bucket_key, scope, COUNT(*) AS attempts, MAX(attempted_at) AS last_attempt
     FROM login_attempts
     WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
     GROUP BY ip, scope
     ORDER BY attempts DESC, last_attempt DESC"
)->fetchAll();
$acctBuckets = $db->query(
    "SELECT account AS bucket_key, scope, COUNT(*) AS attempts, MAX(attempted_at) AS last_attempt
     FROM login_attempts
     WHERE account IS NOT NULL AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
     GROUP BY account, scope
     ORDER BY attempts DESC, last_attempt DESC"
)->fetchAll();
$securityLockedCount = 0;
foreach ($ipBuckets as $b)   if ($b['attempts'] >= rateLimitThreshold($b['scope'], 'ip'))      $securityLockedCount++;
foreach ($acctBuckets as $b) if ($b['attempts'] >= rateLimitThreshold($b['scope'], 'account')) $securityLockedCount++;
$tabCounts['security'] = $securityLockedCount;

// $drivers always needed: races add/edit dropdowns and bets display
[$drivers, $driversById] = fetchDrivers($db);

switch ($currentTab) {
    case 'races':
        $allRaces = $db->query("SELECT * FROM races ORDER BY race_date ASC")->fetchAll();
        $upcomingRaces  = array_values(array_filter($allRaces, fn($r) => $r['result_p1'] === null));
        $completedRaces = array_values(array_reverse(array_filter($allRaces, fn($r) => $r['result_p1'] !== null)));
        $lastCompletedRaceId = $db->query("SELECT id FROM races WHERE result_p1 IS NOT NULL ORDER BY race_date DESC LIMIT 1")->fetchColumn() ?: null;
        break;
    case 'users':
        // Converted guests (promoted via the Challenges admin-approval queue, REQ-506) live
        // on admin-challenges.php's converted-guests list instead — excluded here so they
        // aren't shown twice under two different management flows.
        $users = $db->query("SELECT u.*,
            (EXISTS (SELECT 1 FROM user_passkeys p WHERE p.user_id = u.id)
             OR EXISTS (SELECT 1 FROM user_totp tt WHERE tt.user_id = u.id AND tt.confirmed_at IS NOT NULL)
             OR u.email_otp_enabled = 1) AS has_mfa
            FROM users u
            WHERE u.id NOT IN (
                SELECT core_user_id FROM challenge_participants
                WHERE core_user_id IS NOT NULL AND email IS NOT NULL
            )
            ORDER BY u.points DESC")->fetchAll();
        break;
    case 'bets':
        $races      = $db->query("SELECT * FROM races ORDER BY race_date ASC")->fetchAll();
        $racesById  = array_column($races, null, 'id');
        $bets       = $db->query("SELECT b.*, u.display_name, u.email, r.name as race_name FROM bets b JOIN users u ON b.user_id = u.id JOIN races r ON b.race_id = r.id ORDER BY b.placed_at DESC")->fetchAll();
        break;
    case 'invites':
        $invites = $db->query("SELECT i.*, u.display_name as created_by_name, u.email as created_by_email FROM invites i JOIN users u ON i.created_by = u.id ORDER BY i.created_at DESC")->fetchAll();
        break;
    case 'security':
        // $ipBuckets / $acctBuckets already fetched above (needed for the tab badge on every page load).
        // Resolve scope='mfa' accounts (user UUIDs) to an email/display name for display.
        $usersById = array_column($db->query("SELECT id, email, display_name FROM users")->fetchAll(), null, 'id');
        break;
    // settings and drivers tabs: $settings + $drivers already loaded
}

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">
<h1 class="mb-3"><i class="fas fa-cog text-accent"></i> <?= t('admin') ?></h1>

<!-- Admin area switcher — admin.php and admin-challenges.php are separate pages, not
     tabs of one page, so this sits a level above the per-page tab row below. -->
<nav class="admin-area-nav" aria-label="<?= t('admin') ?>">
    <a href="admin.php" class="admin-area-tab active">
        <i class="fas fa-cog"></i>
        <span><?= t('admin_area_core') ?></span>
    </a>
    <a href="admin-challenges.php" class="admin-area-tab">
        <i class="fas fa-user-check"></i>
        <span><?= t('admin_area_challenges') ?></span>
        <?php if ($challengesPromoCount > 0): ?>
            <span class="admin-area-badge"><?= $challengesPromoCount ?></span>
        <?php endif; ?>
    </a>
</nav>

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

    <nav class="admin-nav" aria-label="<?= t('admin') ?>">
        <?php foreach ($tabIcons as $key => $icon): ?>
            <a href="?tab=<?= $key ?>" class="admin-nav-tab <?= $currentTab === $key ? 'active' : '' ?>">
                <i class="fas fa-<?= $icon ?>"></i>
                <span><?= t($key) ?></span>
                <?php if (!empty($tabCounts[$key])): ?>
                    <span class="admin-nav-count"><?= $tabCounts[$key] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>


    <?php
    $allowedTabs = ['races', 'drivers', 'users', 'bets', 'invites', 'security', 'settings'];
    if (in_array($currentTab, $allowedTabs)) {
        include __DIR__ . "/includes/admin/{$currentTab}.php";
    }
    ?>
</div>
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
