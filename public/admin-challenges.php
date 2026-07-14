<?php
// Standalone admin page (D10) — not a tab in admin.php: the promotion queue is the only
// participant-adjacent path that writes a `users` row, so it gets its own gated surface.
// Tab shell mirroring admin.php's own ?tab= convention: includes/admin-challenges/*.php
// partials (members, rumors, trivia, duels, suppressions), one per ?tab=, only the active
// tab's detail query + partial run per request — same as admin.php's races/users/etc.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';
require_once __DIR__ . '/includes/smtp.php';
requireAdmin();

$db          = getDB();
$currentUser = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $rumorStatusFilter = in_array($_POST['rumor_status'] ?? '', ['all', 'draft', 'published'], true)
        ? $_POST['rumor_status'] : 'all';

    if ($action === 'approve_promotion') {
        $participantId = sanitizeString($_POST['participant_id'] ?? '');

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT * FROM challenge_participants WHERE id = ? FOR UPDATE");
            $stmt->execute([$participantId]);
            $p = $stmt->fetch();

            if (!$p || !empty($p['core_user_id']) || empty($p['promotion_requested_at'])) {
                // Double-submit / stale-row guard (NFR-705) — no-op, nothing to roll back.
                $db->rollBack();
            } else {
                $collision = $db->prepare("SELECT id FROM users WHERE email = ?");
                $collision->execute([$p['email']]);

                if ($collision->fetch()) {
                    // REQ-709 — an email collision aborts safely; request stays pending.
                    $db->rollBack();
                    $_SESSION['flash_error'] = t('admin_ch_promo_conflict');
                } else {
                    $newUserId    = generateUUID();
                    $isPermanent  = !empty($p['password_hash']);
                    $passwordHash = $isPermanent ? $p['password_hash'] : hashPassword(bin2hex(random_bytes(32)));

                    $db->prepare("
                        INSERT INTO users (id, email, password, display_name, role, in_competition, points, stars, language)
                        VALUES (?, ?, ?, ?, 'user', 0, 0, 0, ?)
                    ")->execute([$newUserId, $p['email'], $passwordHash, $p['display_name'], $p['language'] ?: 'da']);

                    $db->prepare("UPDATE challenge_participants SET core_user_id = ?, promotion_requested_at = NULL WHERE id = ?")
                       ->execute([$newUserId, $participantId]);

                    $db->commit();

                    // Post-commit — a delivery failure here must never unwind the account creation.
                    $pLang = $p['language'] ?: 'da';
                    $name  = $p['display_name'] ?: $p['email'];
                    $footer = sprintf(t('email_footer', $pLang), SMTP_FROM_NAME);
                    try {
                        if ($isPermanent) {
                            $html = getEmailTemplate(
                                sprintf(t('ch_email_promoted_greeting', $pLang), $name),
                                t('ch_email_promoted_intro', $pLang),
                                t('ch_email_promoted_button', $pLang),
                                SITE_URL . '/login.php', '', '', $footer, SMTP_FROM_NAME
                            );
                            sendEmail($p['email'], t('ch_email_promoted_subject', $pLang), $html);
                        } else {
                            $token = bin2hex(random_bytes(32));
                            $db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$newUserId]);
                            $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))")
                               ->execute([$newUserId, $token]);
                            $html = getEmailTemplate(
                                sprintf(t('ch_email_setpassword_greeting', $pLang), $name),
                                t('ch_email_setpassword_intro', $pLang),
                                t('ch_email_setpassword_button', $pLang),
                                SITE_URL . '/reset_password.php?token=' . $token,
                                t('ch_email_setpassword_expiry', $pLang), '', $footer, SMTP_FROM_NAME
                            );
                            sendEmail($p['email'], t('ch_email_setpassword_subject', $pLang), $html);
                        }
                    } catch (Exception $e) {
                        if (defined('APP_LOG_FILE')) {
                            logToFile(APP_LOG_FILE, '[CHALLENGES] promotion email failed for ' . $newUserId . ': ' . $e->getMessage());
                        }
                    }

                    $_SESSION['flash_success'] = t('admin_ch_promo_approved');
                }
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash_error'] = t('admin_ch_promo_conflict');
        }
        header('Location: admin-challenges.php?tab=members');
        exit;

    } elseif ($action === 'reject_promotion') {
        $participantId = sanitizeString($_POST['participant_id'] ?? '');
        $db->prepare("UPDATE challenge_participants SET promotion_requested_at = NULL WHERE id = ? AND core_user_id IS NULL")
           ->execute([$participantId]);
        $_SESSION['flash_success'] = t('admin_ch_promo_rejected');
        header('Location: admin-challenges.php?tab=members');
        exit;

    } elseif ($action === 'toggle_guest_competition') {
        $userId = sanitizeString($_POST['user_id'] ?? '');
        $stmt = $db->prepare("SELECT in_competition FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if ($u) {
            $db->prepare("UPDATE users SET in_competition = ? WHERE id = ?")
               ->execute([$u['in_competition'] ? 0 : 1, $userId]);
        }
        header('Location: admin-challenges.php?tab=members');
        exit;

    } elseif ($action === 'admin_suppress_email') {
        $email = sanitizeEmail($_POST['suppress_email'] ?? '');
        if ($email) {
            $db->prepare("INSERT INTO challenge_email_suppressions (email, reason) VALUES (?, 'admin')
                          ON DUPLICATE KEY UPDATE reason = reason")
               ->execute([$email]);
            $_SESSION['flash_success'] = t('admin_ch_suppress_added');
        }
        header('Location: admin-challenges.php?tab=suppressions');
        exit;

    } elseif ($action === 'remove_suppression') {
        $suppressionId = (int) ($_POST['suppression_id'] ?? 0);
        if ($suppressionId) {
            $db->prepare("DELETE FROM challenge_email_suppressions WHERE id = ?")->execute([$suppressionId]);
            $_SESSION['flash_success'] = t('admin_ch_suppress_removed');
        }
        header('Location: admin-challenges.php?tab=suppressions');
        exit;

    } elseif ($action === 'save_rumor_draft' || $action === 'publish_rumor_draft') {
        $itemId      = sanitizeString($_POST['item_id'] ?? '');
        $wasExisting = $itemId !== ''; // stays on the same row (?edit=) vs. back to the plain list
        $fields = [
            'text_da'    => sanitizeString($_POST['text_da'] ?? ''),
            'text_en'    => sanitizeString($_POST['text_en'] ?? ''),
            'context_da' => sanitizeString($_POST['context_da'] ?? ''),
            'context_en' => sanitizeString($_POST['context_en'] ?? ''),
            'explain_da' => sanitizeString($_POST['explain_da'] ?? ''),
            'explain_en' => sanitizeString($_POST['explain_en'] ?? ''),
            'is_real'    => ($_POST['is_real'] ?? '0') === '1' ? 1 : 0,
            'publish_date' => sanitizeString($_POST['publish_date'] ?? '') ?: date('Y-m-d'),
        ];

        if ($wasExisting) {
            // Save never touches status (so editing a published item can't silently revert
            // it to draft); Publish sets it regardless of current status, so re-publishing
            // an edited item works the same whether it started as draft or published.
            $sql = "
                UPDATE challenge_items
                SET text_da = ?, text_en = ?, context_da = ?, context_en = ?,
                    explain_da = ?, explain_en = ?, is_real = ?, publish_date = ?"
                . ($action === 'publish_rumor_draft' ? ", status = 'published'" : "") . "
                WHERE id = ?
            ";
            $db->prepare($sql)->execute([
                $fields['text_da'], $fields['text_en'], $fields['context_da'], $fields['context_en'],
                $fields['explain_da'], $fields['explain_en'], $fields['is_real'], $fields['publish_date'],
                $itemId,
            ]);
        } else {
            $itemId = generateUUID();
            $status = $action === 'publish_rumor_draft' ? 'published' : 'draft';
            $db->prepare("
                INSERT INTO challenge_items
                (id, text_da, text_en, context_da, context_en, explain_da, explain_en, is_real, status, publish_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $itemId, $fields['text_da'], $fields['text_en'], $fields['context_da'], $fields['context_en'],
                $fields['explain_da'], $fields['explain_en'], $fields['is_real'], $status, $fields['publish_date'],
            ]);
        }

        $_SESSION['flash_success'] = $action === 'publish_rumor_draft' ? t('admin_ch_rumor_published') : t('admin_ch_rumor_saved');
        header('Location: admin-challenges.php?tab=rumors&rumor_status=' . $rumorStatusFilter
            . ($wasExisting ? '&edit=' . urlencode($itemId) : ''));
        exit;

    } elseif ($action === 'quick_publish_rumor_item') {
        // Compact-row Publish — status only, never touches text fields (unlike
        // publish_rumor_draft above, which is reachable only from inside the expanded edit
        // form and always carries the full field set). Posting just item_id through the
        // full-field UPDATE would blank out the item's text; this exists to avoid that.
        $itemId = sanitizeString($_POST['item_id'] ?? '');
        $db->prepare("UPDATE challenge_items SET status = 'published' WHERE id = ?")->execute([$itemId]);
        $_SESSION['flash_success'] = t('admin_ch_rumor_published');
        header('Location: admin-challenges.php?tab=rumors&rumor_status=' . $rumorStatusFilter);
        exit;

    } elseif ($action === 'unpublish_rumor_item') {
        $itemId = sanitizeString($_POST['item_id'] ?? '');
        $db->prepare("UPDATE challenge_items SET status = 'draft' WHERE id = ?")->execute([$itemId]);
        $_SESSION['flash_success'] = t('admin_ch_rumor_unpublished');
        header('Location: admin-challenges.php?tab=rumors&rumor_status=' . $rumorStatusFilter);
        exit;

    } elseif ($action === 'veto_rumor_draft') {
        $itemId = sanitizeString($_POST['item_id'] ?? '');
        $db->prepare("DELETE FROM challenge_items WHERE id = ? AND status = 'draft'")->execute([$itemId]);
        $_SESSION['flash_success'] = t('admin_ch_rumor_vetoed');
        header('Location: admin-challenges.php?tab=rumors&rumor_status=' . $rumorStatusFilter);
        exit;

    } elseif ($action === 'delete_rumor_item') {
        // challenge_answers.item_id is ON DELETE CASCADE — no separate cleanup needed.
        $itemId = sanitizeString($_POST['item_id'] ?? '');
        $db->prepare("DELETE FROM challenge_items WHERE id = ?")->execute([$itemId]);
        $_SESSION['flash_success'] = t('admin_ch_rumor_deleted');
        header('Location: admin-challenges.php?tab=rumors&rumor_status=' . $rumorStatusFilter);
        exit;

    } elseif ($action === 'save_trivia_question' || $action === 'publish_trivia_question') {
        $questionId  = sanitizeString($_POST['question_id'] ?? '');
        $wasExisting = $questionId !== '';

        // Options are collected pairwise (da/en); an option only counts once both language
        // fields are filled, so a blank trailing row (REQ-401 allows 2-4 options) drops cleanly.
        $optionsDa = [];
        $optionsEn = [];
        for ($i = 1; $i <= 4; $i++) {
            $da = trim(sanitizeString($_POST["option{$i}_da"] ?? ''));
            $en = trim(sanitizeString($_POST["option{$i}_en"] ?? ''));
            if ($da !== '' && $en !== '') {
                $optionsDa[] = $da;
                $optionsEn[] = $en;
            }
        }

        $fields = [
            'question_da'    => sanitizeString($_POST['question_da'] ?? ''),
            'question_en'    => sanitizeString($_POST['question_en'] ?? ''),
            'options_da'     => json_encode($optionsDa),
            'options_en'     => json_encode($optionsEn),
            'correct_option' => intval($_POST['correct_option'] ?? 0),
            'topic'          => sanitizeString($_POST['topic'] ?? ''),
            'explain_da'     => sanitizeString($_POST['explain_da'] ?? ''),
            'explain_en'     => sanitizeString($_POST['explain_en'] ?? ''),
            'publish_date'   => sanitizeString($_POST['publish_date'] ?? '') ?: date('Y-m-d'),
        ];

        if ($wasExisting) {
            $sql = "
                UPDATE challenge_trivia_questions
                SET question_da = ?, question_en = ?, options_da = ?, options_en = ?,
                    correct_option = ?, topic = ?, explain_da = ?, explain_en = ?, publish_date = ?"
                . ($action === 'publish_trivia_question' ? ", status = 'published'" : "") . "
                WHERE id = ?
            ";
            $db->prepare($sql)->execute([
                $fields['question_da'], $fields['question_en'], $fields['options_da'], $fields['options_en'],
                $fields['correct_option'], $fields['topic'], $fields['explain_da'], $fields['explain_en'],
                $fields['publish_date'], $questionId,
            ]);
        } else {
            $questionId = generateUUID();
            $status = $action === 'publish_trivia_question' ? 'published' : 'draft';
            $db->prepare("
                INSERT INTO challenge_trivia_questions
                (id, question_da, question_en, options_da, options_en, correct_option, topic, explain_da, explain_en, status, publish_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $questionId, $fields['question_da'], $fields['question_en'], $fields['options_da'], $fields['options_en'],
                $fields['correct_option'], $fields['topic'], $fields['explain_da'], $fields['explain_en'],
                $status, $fields['publish_date'],
            ]);
        }

        $_SESSION['flash_success'] = $action === 'publish_trivia_question' ? t('admin_ch_trivia_published') : t('admin_ch_trivia_saved');
        header('Location: admin-challenges.php?tab=trivia' . ($wasExisting ? '&edit=' . urlencode($questionId) : ''));
        exit;

    } elseif ($action === 'quick_publish_trivia_question') {
        // Compact-row Publish — status only, mirrors quick_publish_rumor_item above.
        $questionId = sanitizeString($_POST['question_id'] ?? '');
        $db->prepare("UPDATE challenge_trivia_questions SET status = 'published' WHERE id = ?")->execute([$questionId]);
        $_SESSION['flash_success'] = t('admin_ch_trivia_published');
        header('Location: admin-challenges.php?tab=trivia');
        exit;

    } elseif ($action === 'delete_trivia_question') {
        // challenge_trivia_answers.question_id is ON DELETE CASCADE — no separate cleanup needed.
        $questionId = sanitizeString($_POST['question_id'] ?? '');
        $db->prepare("DELETE FROM challenge_trivia_questions WHERE id = ?")->execute([$questionId]);
        $_SESSION['flash_success'] = t('admin_ch_trivia_deleted');
        header('Location: admin-challenges.php?tab=trivia');
        exit;
    }
}

$success = $_SESSION['flash_success'] ?? '';
$error   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$currentTab = $_GET['tab'] ?? 'members';

$tabIcons = [
    'members'      => 'user-check',
    'rumors'       => 'comment-dots',
    'trivia'       => 'question-circle',
    'duels'        => 'bolt',
    'suppressions' => 'ban',
];

// Cheap COUNT(*) per tab, every load — same convention as admin.php's $tabCounts. 'members'
// is the pending-promotion count (actionable, matches admin.php's own badge on the nav link
// pointing here); the other four are plain row totals, matching the old inline-nav badges.
$tabCounts = [
    'members'      => (int) $db->query("
        SELECT COUNT(*) FROM challenge_participants
        WHERE promotion_requested_at IS NOT NULL AND core_user_id IS NULL
    ")->fetchColumn(),
    'rumors'       => (int) $db->query("SELECT COUNT(*) FROM challenge_items")->fetchColumn(),
    'trivia'       => (int) $db->query("SELECT COUNT(*) FROM challenge_trivia_questions")->fetchColumn(),
    'duels'        => (int) $db->query("SELECT COUNT(*) FROM duels")->fetchColumn(),
    'suppressions' => (int) $db->query("SELECT COUNT(*) FROM challenge_email_suppressions")->fetchColumn(),
];

switch ($currentTab) {
    case 'members':
        // Promotion queue (REQ-703), oldest first.
        $pendingRequests = $db->query("
            SELECT id, email, display_name, language, password_hash, promotion_requested_at
            FROM challenge_participants
            WHERE promotion_requested_at IS NOT NULL AND core_user_id IS NULL
            ORDER BY promotion_requested_at ASC
        ")->fetchAll();

        // Converted guests (REQ-505/707) — distinct from the auto-linked native-core participant
        // rows getChallengeParticipant() creates on first hub visit (those always have email NULL).
        $convertedGuests = $db->query("
            SELECT cp.id AS participant_id, cp.email, cp.display_name, cp.verified_at,
                   u.id AS user_id, u.in_competition, u.points, u.stars
            FROM challenge_participants cp
            JOIN users u ON u.id = cp.core_user_id
            WHERE cp.email IS NOT NULL
            ORDER BY cp.verified_at DESC
        ")->fetchAll();
        break;

    case 'rumors':
        // Rumor or Not (REQ-502, extended) — full list by default so published items are no
        // longer invisible once reviewed; ?rumor_status= narrows to just drafts or published.
        $rumorFilter = in_array($_GET['rumor_status'] ?? '', ['all', 'draft', 'published'], true)
            ? $_GET['rumor_status'] : 'all';
        $rumorTotalCount = $tabCounts['rumors'];
        $rumorDraftCount = (int) $db->query("SELECT COUNT(*) FROM challenge_items WHERE status = 'draft'")->fetchColumn();
        if ($rumorFilter === 'all') {
            $rumorItems = $db->query("SELECT * FROM challenge_items ORDER BY created_at DESC")->fetchAll();
        } else {
            $stmt = $db->prepare("SELECT * FROM challenge_items WHERE status = ? ORDER BY created_at DESC");
            $stmt->execute([$rumorFilter]);
            $rumorItems = $stmt->fetchAll();
        }
        break;

    case 'trivia':
        // Trivia questions (REQ-503) — most recent publish date first, so this week's authoring
        // session (both drafts and already-published) sits at the top.
        $triviaQuestions = $db->query("
            SELECT * FROM challenge_trivia_questions ORDER BY publish_date DESC, created_at DESC
        ")->fetchAll();
        break;

    case 'duels':
        // Duel oversight (REQ-504) — read-only, newest first. No pick contents before lock
        // (REQ-303); after lock, both sides' picks are shown for debugging/support.
        $duelsOversight = $db->query("
            SELECT d.*, r.name AS race_name, r.race_date, r.race_time,
                   cp_c.display_name AS challenger_name, cp_c.email AS challenger_email,
                   cp_o.display_name AS opponent_name, cp_o.email AS opponent_email
            FROM duels d
            JOIN races r ON r.id = d.race_id
            JOIN challenge_participants cp_c ON cp_c.id = d.challenger_id
            JOIN challenge_participants cp_o ON cp_o.id = d.opponent_id
            ORDER BY d.created_at DESC
        ")->fetchAll();

        $duelPicksByDuel = [];
        if ($duelsOversight) {
            $duelIds = array_column($duelsOversight, 'id');
            $ph = implode(',', array_fill(0, count($duelIds), '?'));
            $dpStmt = $db->prepare("SELECT * FROM duel_predictions WHERE duel_id IN ($ph)");
            $dpStmt->execute($duelIds);
            foreach ($dpStmt->fetchAll() as $p) {
                $duelPicksByDuel[$p['duel_id']][$p['participant_id']] = $p;
            }
        }
        [, $duelDriversById] = fetchDrivers($db);
        break;

    case 'suppressions':
        // Suppressions (Feature 5) — full list, newest first.
        $suppressions = $db->query("SELECT * FROM challenge_email_suppressions ORDER BY created_at DESC")->fetchAll();
        break;
}

include __DIR__ . '/includes/header.php';
?>

<h1 class="mb-3"><i class="fas fa-user-check text-accent"></i> <?= t('admin_ch_title') ?></h1>

<?php if ($success): ?>
    <div class="alert alert-success"><?= escape($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= escape($error) ?></div>
<?php endif; ?>

<nav class="admin-nav" aria-label="<?= t('admin_ch_title') ?>">
    <?php foreach ($tabIcons as $key => $icon): ?>
        <a href="?tab=<?= $key ?>" class="admin-nav-tab <?= $currentTab === $key ? 'active' : '' ?>">
            <i class="fas fa-<?= $icon ?>"></i>
            <span><?= t('admin_ch_nav_' . $key) ?></span>
            <?php if (!empty($tabCounts[$key])): ?><span class="admin-nav-count"><?= $tabCounts[$key] ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php
$allowedTabs = ['members', 'rumors', 'trivia', 'duels', 'suppressions'];
if (in_array($currentTab, $allowedTabs)) {
    include __DIR__ . "/includes/admin-challenges/{$currentTab}.php";
}
?>

<!-- Collapsible "Add new" headers (Rumors/Trivia) — same mechanism as admin.php's own
     toggleForm script for "Add Race"/"Add Driver"; a no-op on tabs with no .toggleForm. -->
<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.toggleForm').forEach(function (div) {
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
