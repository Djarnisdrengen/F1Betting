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
    // Carried through trivia / duel redirects so a POST returns to the same filtered/sorted view.
    $triviaStatusFilter = in_array($_POST['trivia_status'] ?? '', ['all', 'draft', 'published'], true)
        ? $_POST['trivia_status'] : 'all';
    $duelSort = in_array($_POST['duel_sort'] ?? '', ['newest', 'oldest'], true)
        ? $_POST['duel_sort'] : 'newest';

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

    } elseif ($action === 'delete_participant') {
        // Delete a single participant row. All challenge child rows (CP ledger, answers, duels,
        // predictions, quickmatch, magic links, access tokens, invites) cascade via their FKs.
        // A promoted participant's core users account is untouched (users-side FK is SET NULL).
        $participantId = sanitizeString($_POST['participant_id'] ?? '');
        $stmt = $db->prepare("DELETE FROM challenge_participants WHERE id = ?");
        $stmt->execute([$participantId]);
        $_SESSION['flash_success'] = $stmt->rowCount() > 0
            ? t('admin_ch_participant_deleted') : t('admin_ch_bulk_none');
        header('Location: admin-challenges.php?tab=members');
        exit;

    } elseif ($action === 'bulk_delete_participants') {
        // Multiselect bulk delete — row checkboxes post ids[] via the HTML5 form= attribute,
        // same wiring as the rumor/trivia bulk handlers. Only ids are user-supplied and always
        // go through placeholders. Cascade + core-account safety are identical to the single delete.
        $ids = array_values(array_filter(
            array_map('sanitizeString', (array) ($_POST['ids'] ?? [])),
            fn($v) => $v !== ''
        ));
        $count = 0;
        if ($ids) {
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM challenge_participants WHERE id IN ($ph)");
            $stmt->execute($ids);
            $count = $stmt->rowCount();
        }
        $_SESSION['flash_success'] = $count > 0
            ? sprintf(t('admin_ch_bulk_updated'), $count)
            : t('admin_ch_bulk_none');
        header('Location: admin-challenges.php?tab=members');
        exit;

    } elseif ($action === 'delete_duel') {
        // Delete a duel. duel_predictions cascade via their FK; the CP ledger has no FK to duels,
        // so the duel's awarded CP (source_ref "duel:<id>", shared by both sides) is removed
        // explicitly first — the same cleanup resetDuelsForRace() does on a race-result reset.
        $duelId = sanitizeString($_POST['duel_id'] ?? '');
        $db->prepare("DELETE FROM challenge_points WHERE source_ref = ?")->execute(["duel:$duelId"]);
        $stmt = $db->prepare("DELETE FROM duels WHERE id = ?");
        $stmt->execute([$duelId]);
        $_SESSION['flash_success'] = $stmt->rowCount() > 0
            ? t('admin_ch_duel_deleted') : t('admin_ch_bulk_none');
        header('Location: admin-challenges.php?tab=duels&duel_sort=' . $duelSort);
        exit;

    } elseif ($action === 'bulk_delete_duels') {
        // Multiselect bulk delete — ids[] via the HTML5 form= attribute, placeholder-bound. Each
        // duel's CP rows (source_ref "duel:<id>") are cleared alongside the duel rows themselves.
        $ids = array_values(array_filter(
            array_map('sanitizeString', (array) ($_POST['ids'] ?? [])),
            fn($v) => $v !== ''
        ));
        $count = 0;
        if ($ids) {
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $refs = array_map(fn($id) => "duel:$id", $ids);
            $db->prepare("DELETE FROM challenge_points WHERE source_ref IN ($ph)")->execute($refs);
            $stmt = $db->prepare("DELETE FROM duels WHERE id IN ($ph)");
            $stmt->execute($ids);
            $count = $stmt->rowCount();
        }
        $_SESSION['flash_success'] = $count > 0
            ? sprintf(t('admin_ch_bulk_updated'), $count)
            : t('admin_ch_bulk_none');
        header('Location: admin-challenges.php?tab=duels&duel_sort=' . $duelSort);
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
        // A NULL publish_date (e.g. a row imported before the generator/import endpoint set
        // one) would leave the item published-but-invisible forever, since nextRumorItem()
        // requires publish_date <= CURDATE() — backfill to today so Publish always means visible now.
        $itemId = sanitizeString($_POST['item_id'] ?? '');
        $db->prepare("
            UPDATE challenge_items
            SET status = 'published', publish_date = COALESCE(publish_date, CURDATE())
            WHERE id = ?
        ")->execute([$itemId]);
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
        header('Location: admin-challenges.php?tab=trivia&trivia_status=' . $triviaStatusFilter
            . ($wasExisting ? '&edit=' . urlencode($questionId) : ''));
        exit;

    } elseif ($action === 'quick_publish_trivia_question') {
        // Compact-row Publish — mirrors quick_publish_rumor_item above, but trivia never rolls
        // over past its ISO week (nextTriviaQuestion() requires the current YEARWEEK), so a NULL
        // or stale (e.g. last week's) publish_date would leave it published-but-invisible until
        // manually re-dated — backfill to today whenever the existing date can't show it this week.
        $questionId = sanitizeString($_POST['question_id'] ?? '');
        $db->prepare("
            UPDATE challenge_trivia_questions
            SET status = 'published',
                publish_date = IF(publish_date IS NULL OR YEARWEEK(publish_date, 3) != YEARWEEK(CURDATE(), 3), CURDATE(), publish_date)
            WHERE id = ?
        ")->execute([$questionId]);
        $_SESSION['flash_success'] = t('admin_ch_trivia_published');
        header('Location: admin-challenges.php?tab=trivia&trivia_status=' . $triviaStatusFilter);
        exit;

    } elseif ($action === 'delete_trivia_question') {
        // challenge_trivia_answers.question_id is ON DELETE CASCADE — no separate cleanup needed.
        $questionId = sanitizeString($_POST['question_id'] ?? '');
        $db->prepare("DELETE FROM challenge_trivia_questions WHERE id = ?")->execute([$questionId]);
        $_SESSION['flash_success'] = t('admin_ch_trivia_deleted');
        header('Location: admin-challenges.php?tab=trivia&trivia_status=' . $triviaStatusFilter);
        exit;

    } elseif (in_array($action, [
        'bulk_publish_trivia', 'bulk_unpublish_trivia', 'bulk_delete_trivia',
        'bulk_publish_rumor',  'bulk_unpublish_rumor',  'bulk_delete_rumor',
    ], true)) {
        // Multiselect bulk update — row checkboxes post ids[] (associated to the per-tab bulk
        // form via the HTML5 form= attribute). Table name is a hardcoded literal per branch;
        // only the ids are user-supplied and always go through placeholders. cascade deletes
        // (challenge_answers / challenge_trivia_answers) handle child rows, same as the
        // single-row delete handlers above.
        $ids = array_values(array_filter(
            array_map('sanitizeString', (array) ($_POST['ids'] ?? [])),
            fn($v) => $v !== ''
        ));
        $isTrivia = str_ends_with($action, '_trivia');
        $table    = $isTrivia ? 'challenge_trivia_questions' : 'challenge_items';
        $count    = 0;
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if (str_starts_with($action, 'bulk_delete_')) {
                $stmt = $db->prepare("DELETE FROM $table WHERE id IN ($ph)");
                $stmt->execute($ids);
            } else {
                $status = str_starts_with($action, 'bulk_publish_') ? 'published' : 'draft';
                if ($status === 'published') {
                    // Same backfill as the quick-publish handlers above — a NULL (rumor) or
                    // stale-week (trivia) publish_date would leave the bulk-published rows
                    // invisible despite status='published'.
                    $dateFix = $isTrivia
                        ? "publish_date = IF(publish_date IS NULL OR YEARWEEK(publish_date, 3) != YEARWEEK(CURDATE(), 3), CURDATE(), publish_date)"
                        : "publish_date = COALESCE(publish_date, CURDATE())";
                    $stmt = $db->prepare("UPDATE $table SET status = ?, $dateFix WHERE id IN ($ph)");
                } else {
                    $stmt = $db->prepare("UPDATE $table SET status = ? WHERE id IN ($ph)");
                }
                $stmt->execute(array_merge([$status], $ids));
            }
            $count = $stmt->rowCount();
        }
        $_SESSION['flash_success'] = $count > 0
            ? sprintf(t('admin_ch_bulk_updated'), $count)
            : t('admin_ch_bulk_none');
        header('Location: admin-challenges.php?tab=' . ($isTrivia ? 'trivia' : 'rumors')
            . ($isTrivia ? '&trivia_status=' . $triviaStatusFilter : '&rumor_status=' . $rumorStatusFilter));
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

        // Full participant roster (all rows: guests, native-core, promoted) with a delete surface.
        // core_user_id is carried only to badge promoted/native rows — deleting a participant here
        // never removes a linked users account (the FK is ON DELETE SET NULL on the users side; we
        // delete the participant, not the user). Child challenge rows cascade via their own FKs.
        $allParticipants = $db->query("
            SELECT id, email, display_name, language, status,
                   created_at, promotion_requested_at, core_user_id
            FROM challenge_participants
            ORDER BY created_at DESC
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
        // session (both drafts and already-published) sits at the top. ?trivia_status= narrows to
        // just drafts or published, mirroring the Rumors tab's filter.
        $triviaFilter = in_array($_GET['trivia_status'] ?? '', ['all', 'draft', 'published'], true)
            ? $_GET['trivia_status'] : 'all';
        $triviaTotalCount = $tabCounts['trivia'];
        $triviaDraftCount = (int) $db->query("SELECT COUNT(*) FROM challenge_trivia_questions WHERE status = 'draft'")->fetchColumn();
        if ($triviaFilter === 'all') {
            $triviaQuestions = $db->query("
                SELECT * FROM challenge_trivia_questions ORDER BY publish_date DESC, created_at DESC
            ")->fetchAll();
        } else {
            $stmt = $db->prepare("SELECT * FROM challenge_trivia_questions WHERE status = ? ORDER BY publish_date DESC, created_at DESC");
            $stmt->execute([$triviaFilter]);
            $triviaQuestions = $stmt->fetchAll();
        }
        break;

    case 'duels':
        // Duel oversight (REQ-504) — resolution is automatic (off race results), the tab only adds
        // delete for cleanup. ?duel_sort= toggles created-date order (newest default / oldest). No
        // pick contents before lock (REQ-303); after lock, both sides' picks are shown for support.
        $duelSort  = in_array($_GET['duel_sort'] ?? '', ['newest', 'oldest'], true) ? $_GET['duel_sort'] : 'newest';
        $duelOrder = $duelSort === 'oldest' ? 'ASC' : 'DESC'; // hardcoded literal — never user text
        $duelsOversight = $db->query("
            SELECT d.*, r.name AS race_name, r.race_date, r.race_time,
                   cp_c.display_name AS challenger_name, cp_c.email AS challenger_email,
                   cp_o.display_name AS opponent_name, cp_o.email AS opponent_email
            FROM duels d
            JOIN races r ON r.id = d.race_id
            JOIN challenge_participants cp_c ON cp_c.id = d.challenger_id
            JOIN challenge_participants cp_o ON cp_o.id = d.opponent_id
            ORDER BY d.created_at $duelOrder
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

<div class="hf-container">
<h1 class="mb-3"><i class="fas fa-user-check text-accent"></i> <?= t('admin_ch_title') ?></h1>

<!-- Admin area switcher — mirrors the one on admin.php; this page is a separate top-level
     area (not a tab of admin.php), so it gets a way back up there too. -->
<nav class="admin-area-nav" aria-label="<?= t('admin') ?>">
    <a href="admin.php" class="admin-area-tab">
        <i class="fas fa-cog"></i>
        <span><?= t('admin_area_core') ?></span>
    </a>
    <a href="admin-challenges.php" class="admin-area-tab active">
        <i class="fas fa-user-check"></i>
        <span><?= t('admin_area_challenges') ?></span>
        <?php if ($tabCounts['members'] > 0): ?>
            <span class="admin-area-badge"><?= $tabCounts['members'] ?></span>
        <?php endif; ?>
    </a>
</nav>

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
</div>

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
