<?php
// Standalone admin page (D10) — not a tab in admin.php: the promotion queue is the only
// participant-adjacent path that writes a `users` row, so it gets its own gated surface.
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
        header('Location: admin-challenges.php');
        exit;

    } elseif ($action === 'reject_promotion') {
        $participantId = sanitizeString($_POST['participant_id'] ?? '');
        $db->prepare("UPDATE challenge_participants SET promotion_requested_at = NULL WHERE id = ? AND core_user_id IS NULL")
           ->execute([$participantId]);
        $_SESSION['flash_success'] = t('admin_ch_promo_rejected');
        header('Location: admin-challenges.php');
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
        header('Location: admin-challenges.php');
        exit;

    } elseif ($action === 'admin_suppress_email') {
        $email = sanitizeEmail($_POST['suppress_email'] ?? '');
        if ($email) {
            $db->prepare("INSERT INTO challenge_email_suppressions (email, reason) VALUES (?, 'admin')
                          ON DUPLICATE KEY UPDATE reason = reason")
               ->execute([$email]);
            $_SESSION['flash_success'] = t('admin_ch_suppress_added');
        }
        header('Location: admin-challenges.php');
        exit;

    } elseif ($action === 'save_rumor_draft' || $action === 'publish_rumor_draft') {
        $itemId = sanitizeString($_POST['item_id'] ?? '');
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
        $status = $action === 'publish_rumor_draft' ? 'published' : 'draft';

        $db->prepare("
            UPDATE challenge_items
            SET text_da = ?, text_en = ?, context_da = ?, context_en = ?,
                explain_da = ?, explain_en = ?, is_real = ?, publish_date = ?, status = ?
            WHERE id = ? AND status = 'draft'
        ")->execute([
            $fields['text_da'], $fields['text_en'], $fields['context_da'], $fields['context_en'],
            $fields['explain_da'], $fields['explain_en'], $fields['is_real'], $fields['publish_date'],
            $status, $itemId,
        ]);

        $_SESSION['flash_success'] = $status === 'published' ? t('admin_ch_rumor_published') : t('admin_ch_rumor_saved');
        header('Location: admin-challenges.php');
        exit;

    } elseif ($action === 'veto_rumor_draft') {
        $itemId = sanitizeString($_POST['item_id'] ?? '');
        $db->prepare("DELETE FROM challenge_items WHERE id = ? AND status = 'draft'")->execute([$itemId]);
        $_SESSION['flash_success'] = t('admin_ch_rumor_vetoed');
        header('Location: admin-challenges.php');
        exit;
    }
}

$success = $_SESSION['flash_success'] ?? '';
$error   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

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

$suppressionCount = (int) $db->query("SELECT COUNT(*) FROM challenge_email_suppressions")->fetchColumn();

// Rumor drafts (REQ-502) — oldest first, so generator batches review in submission order.
$rumorDrafts = $db->query("
    SELECT * FROM challenge_items WHERE status = 'draft' ORDER BY created_at ASC
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<h1 class="mb-3"><i class="fas fa-user-check text-accent"></i> <?= t('admin_ch_title') ?></h1>

<?php if ($success): ?>
    <div class="alert alert-success"><?= escape($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= escape($error) ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <h2 style="margin-bottom:16px;"><?= t('admin_ch_promotion_queue') ?></h2>

        <?php if (empty($pendingRequests)): ?>
            <p class="text-muted"><?= t('admin_ch_queue_empty') ?></p>
        <?php else: ?>
            <?php foreach ($pendingRequests as $req): ?>
                <div class="card mb-1">
                    <div class="card-body admin-user-card-body">
                        <div class="admin-user-info">
                            <div class="user-avatar"><?= escape(strtoupper(substr($req['display_name'] ?: $req['email'], 0, 1))) ?></div>
                            <div>
                                <strong><?= escape($req['display_name'] ?: $req['email']) ?></strong>
                                <br><small class="text-muted"><?= escape($req['email']) ?></small>
                                <br><small class="text-muted">
                                    <?= !empty($req['password_hash']) ? t('admin_ch_permanent') : t('admin_ch_verified_only') ?>
                                    · <?= (int) getChallengeCpTotal($db, $req['id']) ?> CP
                                    · <?= escape(date('d M Y', strtotime($req['promotion_requested_at']))) ?>
                                </small>
                            </div>
                        </div>
                        <div class="flex gap-1">
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="approve_promotion">
                                <input type="hidden" name="participant_id" value="<?= escape($req['id']) ?>">
                                <button type="submit" class="btn btn-primary btn-sm"><?= t('admin_ch_approve') ?></button>
                            </form>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="reject_promotion">
                                <input type="hidden" name="participant_id" value="<?= escape($req['id']) ?>">
                                <button type="submit" class="btn btn-secondary btn-sm"><?= t('admin_ch_reject') ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 style="margin-bottom:16px;"><?= t('admin_ch_converted_guests') ?></h2>

        <?php if (empty($convertedGuests)): ?>
            <p class="text-muted"><?= t('admin_ch_no_converted_guests') ?></p>
        <?php else: ?>
            <?php foreach ($convertedGuests as $guest): ?>
                <div class="card mb-1">
                    <div class="card-body admin-user-card-body">
                        <div class="admin-user-info">
                            <div class="user-avatar"><?= escape(strtoupper(substr($guest['display_name'] ?: $guest['email'], 0, 1))) ?></div>
                            <div>
                                <strong><?= escape($guest['display_name'] ?: $guest['email']) ?></strong>
                                <br><small class="text-muted"><?= escape($guest['email']) ?></small>
                                <br><small class="text-muted">
                                    <?= (int) $guest['points'] ?> pts · <?= (int) getChallengeCpTotal($db, $guest['participant_id']) ?> CP
                                </small>
                            </div>
                        </div>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_guest_competition">
                            <input type="hidden" name="user_id" value="<?= escape($guest['user_id']) ?>">
                            <button type="submit" class="btn btn-sm" style="background: <?= $guest['in_competition'] ? 'var(--f1-red)' : 'var(--bg-secondary)' ?>; color: <?= $guest['in_competition'] ? 'white' : 'var(--text-primary)' ?>; border: none;">
                                <i class="fas fa-<?= $guest['in_competition'] ? 'check-circle' : 'times-circle' ?>"></i>
                                <?= $guest['in_competition'] ? t('in_competition_label') : t('not_in_competition_label') ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 style="margin-bottom:8px;"><?= t('admin_ch_suppressions') ?></h2>
        <p class="text-muted" style="margin:0 0 12px;"><?= sprintf(t('admin_ch_suppressions_count'), $suppressionCount) ?></p>
        <form method="POST" class="flex gap-1 items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="admin_suppress_email">
            <div class="form-group" style="margin:0;flex:1;">
                <input type="email" name="suppress_email" class="form-input" placeholder="friend@example.com" required>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm"><?= t('admin_ch_suppress_add') ?></button>
        </form>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <h2 style="margin-bottom:16px;"><?= t('admin_ch_rumor_drafts') ?></h2>

        <?php if (empty($rumorDrafts)): ?>
            <p class="text-muted"><?= t('admin_ch_rumor_drafts_empty') ?></p>
        <?php else: ?>
            <?php foreach ($rumorDrafts as $draft): ?>
                <div class="card mb-2" data-testid="rumor-draft" data-item-id="<?= escape($draft['id']) ?>">
                    <div class="card-body">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="item_id" value="<?= escape($draft['id']) ?>">

                            <div class="form-group">
                                <label class="form-label"><?= t('admin_ch_rumor_text_da') ?></label>
                                <textarea name="text_da" class="form-input" rows="2"><?= escape($draft['text_da']) ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><?= t('admin_ch_rumor_text_en') ?></label>
                                <textarea name="text_en" class="form-input" rows="2"><?= escape($draft['text_en']) ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><?= t('admin_ch_rumor_context_da') ?></label>
                                <input type="text" name="context_da" class="form-input" value="<?= escape($draft['context_da']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label"><?= t('admin_ch_rumor_context_en') ?></label>
                                <input type="text" name="context_en" class="form-input" value="<?= escape($draft['context_en']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label"><?= t('admin_ch_rumor_explain_da') ?></label>
                                <textarea name="explain_da" class="form-input" rows="2"><?= escape($draft['explain_da']) ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label"><?= t('admin_ch_rumor_explain_en') ?></label>
                                <textarea name="explain_en" class="form-input" rows="2"><?= escape($draft['explain_en']) ?></textarea>
                            </div>
                            <div class="flex gap-1">
                                <div class="form-group" style="flex:1;">
                                    <label class="form-label"><?= t('admin_ch_rumor_is_real') ?></label>
                                    <select name="is_real" class="form-input">
                                        <option value="1" <?= $draft['is_real'] ? 'selected' : '' ?>><?= t('admin_ch_rumor_real') ?></option>
                                        <option value="0" <?= !$draft['is_real'] ? 'selected' : '' ?>><?= t('admin_ch_rumor_rumor') ?></option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label class="form-label"><?= t('admin_ch_rumor_publish_date') ?></label>
                                    <input type="date" name="publish_date" class="form-input" value="<?= escape($draft['publish_date']) ?>">
                                </div>
                            </div>

                            <div class="flex gap-1">
                                <button type="submit" name="action" value="save_rumor_draft" class="btn btn-secondary btn-sm"><?= t('admin_ch_rumor_save') ?></button>
                                <button type="submit" name="action" value="publish_rumor_draft" class="btn btn-primary btn-sm"><?= t('admin_ch_rumor_publish') ?></button>
                                <button type="submit" name="action" value="veto_rumor_draft" class="btn btn-sm" style="background:var(--f1-red);color:#fff;border:none;"><?= t('admin_ch_rumor_veto') ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
