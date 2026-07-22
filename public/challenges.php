<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';

$section = $_GET['section'] ?? 'overview';
$validSections = ['overview', 'rumors', 'duels', 'trivia', 'board'];

if (!in_array($section, $validSections)) {
    $section = 'overview';
}

$db = getDB();
$participant = getChallengeParticipant();
$lang = getLang();

// Overview is participant-gated (personal CP/streak); Rumor or Not and Trivia are playable by
// any visitor with no participant row at all — one is created on their first answer (REQ-101).
$isPublic = !$participant;

// Next unanswered item in the Rumor or Not deck (published, oldest first). Unanswered items
// roll over — no date window beyond "published by today" (REQ-204).
function nextRumorItem(PDO $db, ?array $participant): ?array {
    if ($participant) {
        $stmt = $db->prepare("
            SELECT * FROM challenge_items
            WHERE status='published' AND publish_date <= CURDATE()
                  AND id NOT IN (SELECT item_id FROM challenge_answers WHERE participant_id = ?)
            ORDER BY publish_date ASC, id ASC LIMIT 1
        ");
        $stmt->execute([$participant['id']]);
    } else {
        $stmt = $db->prepare("
            SELECT * FROM challenge_items
            WHERE status='published' AND publish_date <= CURDATE()
            ORDER BY publish_date ASC, id ASC LIMIT 1
        ");
        $stmt->execute();
    }
    return $stmt->fetch() ?: null;
}

// Next unanswered trivia question in the current ISO week (published, publish_date <= today).
// Unlike rumors, trivia never rolls over past its ISO week (REQ-402) — this query is scoped
// to the current week only; a stale prior-week question_id is rejected in the POST handler.
function nextTriviaQuestion(PDO $db, ?array $participant): ?array {
    if ($participant) {
        $stmt = $db->prepare("
            SELECT * FROM challenge_trivia_questions
            WHERE status='published' AND publish_date <= CURDATE()
                  AND YEARWEEK(publish_date, 3) = YEARWEEK(CURDATE(), 3)
                  AND id NOT IN (SELECT question_id FROM challenge_trivia_answers WHERE participant_id = ?)
            ORDER BY publish_date ASC, id ASC LIMIT 1
        ");
        $stmt->execute([$participant['id']]);
    } else {
        $stmt = $db->prepare("
            SELECT * FROM challenge_trivia_questions
            WHERE status='published' AND publish_date <= CURDATE()
                  AND YEARWEEK(publish_date, 3) = YEARWEEK(CURDATE(), 3)
            ORDER BY publish_date ASC, id ASC LIMIT 1
        ");
        $stmt->execute();
    }
    return $stmt->fetch() ?: null;
}

if ($section === 'rumors' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $itemId    = sanitizeString($_POST['item_id'] ?? '');
    $guessReal = ($_POST['guess_real'] ?? '') === '1' ? 1 : 0;

    $stmt = $db->prepare("SELECT * FROM challenge_items WHERE id = ? AND status='published' AND publish_date <= CURDATE()");
    $stmt->execute([$itemId]);
    $answeredItem = $stmt->fetch();

    if ($answeredItem) {
        $participant = getOrCreateAnonymousParticipant($db);
        $correct = ((int)$guessReal === (int)$answeredItem['is_real']) ? 1 : 0;

        try {
            $db->prepare("
                INSERT INTO challenge_answers (id, participant_id, item_id, guess_real, correct, answered_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ")->execute([generateUUID(), $participant['id'], $itemId, $guessReal, $correct]);

            if ($correct) {
                awardChallengePoints($db, $participant['id'], 'rumor_or_not', 10, "rumor_or_not:$itemId");
            }
        } catch (Exception $e) {
            // UNIQUE(participant_id, item_id) — already answered (double-submit); no-op, just reveal it.
        }

        header('Location: challenges.php?section=rumors&revealed=' . urlencode($itemId));
        exit;
    }

    // Stale/forged item_id (not published, or answered on a previous visit and now missing from
    // the deck) — nothing to record, just fall through to the current unanswered card.
    header('Location: challenges.php?section=rumors');
    exit;
}

if ($section === 'trivia' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $questionId = sanitizeString($_POST['question_id'] ?? '');
    $chosen     = intval($_POST['chosen_option'] ?? -1);

    // Scoped to the current ISO week — a stale prior-week question_id (a form left open over
    // the weekend, or a forged one) is silently rejected, never recorded (REQ-402).
    $stmt = $db->prepare("
        SELECT * FROM challenge_trivia_questions
        WHERE id = ? AND status='published' AND publish_date <= CURDATE()
              AND YEARWEEK(publish_date, 3) = YEARWEEK(CURDATE(), 3)
    ");
    $stmt->execute([$questionId]);
    $answeredQuestion = $stmt->fetch();

    if ($answeredQuestion && $chosen >= 0) {
        $participant = getOrCreateAnonymousParticipant($db);
        $correct = ((int)$chosen === (int)$answeredQuestion['correct_option']) ? 1 : 0;

        try {
            $db->prepare("
                INSERT INTO challenge_trivia_answers (id, participant_id, question_id, chosen_option, correct, answered_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ")->execute([generateUUID(), $participant['id'], $questionId, $chosen, $correct]);

            if ($correct) {
                awardChallengePoints($db, $participant['id'], 'trivia', 5, "trivia:$questionId");
            }
        } catch (Exception $e) {
            // UNIQUE(participant_id, question_id) — already answered (double-submit); no-op, just reveal it.
        }

        header('Location: challenges.php?section=trivia&revealed=' . urlencode($questionId));
        exit;
    }

    header('Location: challenges.php?section=trivia');
    exit;
}

// Duels need a verified identity (an opponent has to be able to find/be notified of you) —
// unlike Rumor or Not / Trivia, anonymous play is not offered here (REQ-301's "guest" means
// a verified guest, not the pending/email-null rows getOrCreateAnonymousParticipant() makes).
if ($section === 'duels' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($participant && $participant['status'] === 'verified') {
        $duelRace = getNextDuelRace($db);

        if ($action === 'quick_match' && $duelRace && !isDuelRaceLocked($duelRace)) {
            $newDuelId = tryQuickMatchPairing($db, $participant['id'], $duelRace['id']);
            header('Location: challenges.php?section=duels' . ($newDuelId ? '&duel=' . urlencode($newDuelId) : '&queued=1'));
            exit;
        }

        if ($action === 'challenge_friend' && $duelRace && !isDuelRaceLocked($duelRace)) {
            $opponentId = sanitizeString($_POST['opponent_id'] ?? '');
            $validOpponent = false;
            if ($opponentId && $opponentId !== $participant['id']) {
                $chk = $db->prepare("SELECT id FROM challenge_participants WHERE id = ?");
                $chk->execute([$opponentId]);
                $validOpponent = (bool) $chk->fetch();
            }
            if ($validOpponent) {
                $newDuelId = createDirectDuel($db, $duelRace['id'], $participant['id'], $opponentId);
                header('Location: challenges.php?section=duels&duel=' . urlencode($newDuelId));
                exit;
            }
            header('Location: challenges.php?section=duels&mode=challenge&error=1');
            exit;
        }

        if ($action === 'submit_pick') {
            $duelId = sanitizeString($_POST['duel_id'] ?? '');
            $dStmt = $db->prepare("
                SELECT d.*, r.race_date, r.race_time FROM duels d JOIN races r ON r.id = d.race_id
                WHERE d.id = ? AND (d.challenger_id = ? OR d.opponent_id = ?)
            ");
            $dStmt->execute([$duelId, $participant['id'], $participant['id']]);
            $pickDuel = $dStmt->fetch();

            if ($pickDuel && !isDuelRaceLocked($pickDuel)) {
                [, $pickDriversById] = fetchDrivers($db);
                $p1 = sanitizeString($_POST['p1'] ?? '');
                $p2 = sanitizeString($_POST['p2'] ?? '');
                $p3 = sanitizeString($_POST['p3'] ?? '');
                $pickError = validateDuelPick($p1, $p2, $p3, array_keys($pickDriversById));

                if (!$pickError) {
                    try {
                        $db->prepare("
                            INSERT INTO duel_predictions (id, duel_id, participant_id, p1, p2, p3, submitted_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ")->execute([generateUUID(), $duelId, $participant['id'], $p1, $p2, $p3]);
                    } catch (Exception $e) {
                        // UNIQUE(duel_id, participant_id) — already picked (double-submit); no-op.
                    }
                    header('Location: challenges.php?section=duels&duel=' . urlencode($duelId) . '&picked=1');
                    exit;
                }
                header('Location: challenges.php?section=duels&duel=' . urlencode($duelId) . '&pickerror=1');
                exit;
            }
        }
    }

    header('Location: challenges.php?section=duels');
    exit;
}

if ($section === 'rumors') {
    $revealedItem = null;
    $revealedId   = $_GET['revealed'] ?? '';
    if ($revealedId && $participant) {
        $stmt = $db->prepare("
            SELECT ci.*, ca.guess_real, ca.correct
            FROM challenge_items ci
            JOIN challenge_answers ca ON ca.item_id = ci.id
            WHERE ci.id = ? AND ca.participant_id = ?
        ");
        $stmt->execute([$revealedId, $participant['id']]);
        $revealedItem = $stmt->fetch() ?: null;
    }

    // Computed regardless of reveal state: on the reveal page it tells us whether another
    // unanswered card remains (Next card vs Finish deck); on the plain page it IS the active card.
    $rumorCurrent = nextRumorItem($db, $participant);
    $rumorDone    = !$revealedItem && !$rumorCurrent;

    $deckSize = (int)(getSettings()['challenge_rumor_deck_size'] ?? 3);
    $answeredToday = 0;
    if ($participant) {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM challenge_answers WHERE participant_id = ? AND DATE(answered_at) = CURDATE()");
        $countStmt->execute([$participant['id']]);
        $answeredToday = (int)$countStmt->fetchColumn();
    }
}

if ($section === 'trivia') {
    $revealedQuestion = null;
    $revealedQId      = $_GET['revealed'] ?? '';
    if ($revealedQId && $participant) {
        $stmt = $db->prepare("
            SELECT tq.*, ta.chosen_option, ta.correct
            FROM challenge_trivia_questions tq
            JOIN challenge_trivia_answers ta ON ta.question_id = tq.id
            WHERE tq.id = ? AND ta.participant_id = ?
        ");
        $stmt->execute([$revealedQId, $participant['id']]);
        $revealedQuestion = $stmt->fetch() ?: null;
    }

    $triviaCurrent = nextTriviaQuestion($db, $participant);
    $triviaDone    = !$revealedQuestion && !$triviaCurrent;

    // This week's published total + this participant's progress against it — drives the deck
    // counter and the done-state title (all-caught-up vs quiz-complete vs Perfect Week, REQ-405/407).
    $weekTotal = (int)$db->query("
        SELECT COUNT(*) FROM challenge_trivia_questions
        WHERE status='published' AND YEARWEEK(publish_date, 3) = YEARWEEK(CURDATE(), 3)
    ")->fetchColumn();

    $weekAnswered = 0;
    $weekCorrect  = 0;
    if ($participant) {
        $progressStmt = $db->prepare("
            SELECT COUNT(*), COALESCE(SUM(ta.correct),0)
            FROM challenge_trivia_answers ta
            JOIN challenge_trivia_questions tq ON tq.id = ta.question_id
            WHERE ta.participant_id = ? AND YEARWEEK(tq.publish_date, 3) = YEARWEEK(CURDATE(), 3)
        ");
        $progressStmt->execute([$participant['id']]);
        [$weekAnswered, $weekCorrect] = $progressStmt->fetch(PDO::FETCH_NUM);
        $weekAnswered = (int)$weekAnswered;
        $weekCorrect  = (int)$weekCorrect;
    }
    // Same guard as the overview's Perfect Week total — never show fewer published than already answered.
    $weekTotal = max($weekTotal, $weekAnswered);
}

if ($section === 'duels') {
    $duelRace = getNextDuelRace($db);
    $duelRaceLocked = $duelRace ? isDuelRaceLocked($duelRace) : false;
    $isVerifiedParticipant = $participant && $participant['status'] === 'verified';

    $duelMode    = $_GET['mode'] ?? '';
    $viewDuelId  = $_GET['duel'] ?? '';
    $friendQuery = trim($_GET['q'] ?? '');
    $friendResults = [];

    $needsPickDuels = [];
    $waitingDuels    = [];
    $settledDuels    = [];
    $viewDuel        = null;

    if ($isVerifiedParticipant) {
        $stmt = $db->prepare("
            SELECT d.*, r.name AS race_name, r.race_date, r.race_time,
                   cp_c.display_name AS challenger_name, cp_o.display_name AS opponent_name
            FROM duels d
            JOIN races r ON r.id = d.race_id
            JOIN challenge_participants cp_c ON cp_c.id = d.challenger_id
            JOIN challenge_participants cp_o ON cp_o.id = d.opponent_id
            WHERE d.challenger_id = ? OR d.opponent_id = ?
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([$participant['id'], $participant['id']]);
        $myDuels = $stmt->fetchAll();

        $picksByDuel = [];
        if ($myDuels) {
            $duelIds = array_column($myDuels, 'id');
            $ph = implode(',', array_fill(0, count($duelIds), '?'));
            $pStmt = $db->prepare("SELECT * FROM duel_predictions WHERE duel_id IN ($ph)");
            $pStmt->execute($duelIds);
            foreach ($pStmt->fetchAll() as $p) {
                $picksByDuel[$p['duel_id']][$p['participant_id']] = $p;
            }
        }

        foreach ($myDuels as $d) {
            $isChallenger = $d['challenger_id'] === $participant['id'];
            $d['other_id']    = $isChallenger ? $d['opponent_id']   : $d['challenger_id'];
            $d['other_name']  = $isChallenger ? $d['opponent_name'] : $d['challenger_name'];
            $d['my_pick']     = $picksByDuel[$d['id']][$participant['id']] ?? null;
            $d['other_pick']  = $picksByDuel[$d['id']][$d['other_id']] ?? null;
            $d['locked']      = isDuelRaceLocked($d);

            if ($d['id'] === $viewDuelId) {
                $viewDuel = $d;
            }

            if (in_array($d['status'], ['resolved', 'void'], true)) {
                $settledDuels[] = $d;
            } elseif (!$d['my_pick'] && !$d['locked']) {
                $needsPickDuels[] = $d;
            } else {
                $waitingDuels[] = $d;
            }
        }

        if ($duelMode === 'challenge' && $friendQuery !== '') {
            $friendResults = searchChallengeParticipants($db, $friendQuery, $participant['id']);
        }
    }

    [$duelDrivers, $duelDriversById] = fetchDrivers($db);
}

if ($section === 'board') {
    // Public leaderboard — no participant gate (REQ-106: guests and full members alike can
    // view it), same query the old standalone challenges-board.php used.
    $boardLeaderboard = getCpLeaderboard($db, 50);
    $boardRank = $participant
        ? getChallengeRank($db, $participant['id'])
        : ['rank' => null, 'total' => count(getCpLeaderboard($db))];
}

if ($section === 'overview' && $participant) {
    // Perfect Week tracker sizes to however many trivia questions actually published this
    // ISO week — content-gen doesn't guarantee a fixed count (a malformed Claude response
    // is skipped per-item, not fatal to the batch), so a hardcoded box count drifts from
    // the real target the weekly cron (challenge_weekly.php) awards the +20 CP bonus against.
    $pwWeekTotal = (int)$db->query("
        SELECT COUNT(*) FROM challenge_trivia_questions
        WHERE status='published' AND YEARWEEK(publish_date, 3) = YEARWEEK(CURDATE(), 3)
    ")->fetchColumn();
    $pwIsoWeek = (int)(new DateTime('today', new DateTimeZone('Europe/Copenhagen')))->format('W');
    $pwCount = getTriviaCorrectThisWeek($db, $participant['id']);
    // A question can be unpublished after already being answered (admin correction, or content
    // pulled mid-week) — the "published now" count must never drop below what was already
    // answered, or the tracker shows a nonsensical N/0.
    $pwWeekTotal = max($pwWeekTotal, $pwCount);

    // Games Live Now — same small per-game numbers the rumors/trivia tabs compute themselves
    // (challenges.php:236-242, 264-282), recomputed here since those setup blocks are gated to
    // their own $section and Overview only needs the counts, not the full deck/question fetch.
    $ovDeckSize = (int)(getSettings()['challenge_rumor_deck_size'] ?? 3);
    $ovRumorsToday = 0;
    $rumorsCountStmt = $db->prepare("SELECT COUNT(*) FROM challenge_answers WHERE participant_id = ? AND DATE(answered_at) = CURDATE()");
    $rumorsCountStmt->execute([$participant['id']]);
    $ovRumorsToday = (int)$rumorsCountStmt->fetchColumn();

    $ovTriviaWeekTotal = $pwWeekTotal;
    $ovTriviaWeekAnswered = 0;
    $triviaProgressStmt = $db->prepare("
        SELECT COUNT(*)
        FROM challenge_trivia_answers ta
        JOIN challenge_trivia_questions tq ON tq.id = ta.question_id
        WHERE ta.participant_id = ? AND YEARWEEK(tq.publish_date, 3) = YEARWEEK(CURDATE(), 3)
    ");
    $triviaProgressStmt->execute([$participant['id']]);
    $ovTriviaWeekAnswered = (int)$triviaProgressStmt->fetchColumn();
    // $pwWeekTotal is already clamped to correct-answer count above, but a wrong answer still
    // counts toward "answered" without counting toward "correct" — guard against that too.
    $ovTriviaWeekTotal = max($ovTriviaWeekTotal, $ovTriviaWeekAnswered);

    $ovPendingDuel = getPendingDuelForOverview($db, $participant['id']);
}

include __DIR__ . '/includes/header.php';
?>

<div class="hf-arena-base" style="min-height:100vh;padding-bottom:80px;">
    <div class="hf-arena-header">
        <div style="display:flex;align-items:center;justify-content:space-between;">
            <h1 style="margin:0;font-size:24px;font-weight:700;color:var(--text-primary);">
                <i class="fas fa-gamepad" style="margin-right:8px;color:var(--f1-accent-challenges);"></i>
                <?= t('ch_nav_challenges') ?>
            </h1>
            <a href="challenges-rules.php" style="color:var(--text-primary);opacity:.75;font-size:13px;text-decoration:none;white-space:nowrap;">
                <i class="fas fa-circle-question" style="margin-right:6px;"></i><?= t('ch_rules_link') ?>
            </a>
        </div>
    </div>

    <div class="hf-container" style="padding:20px;color:var(--text-primary);">
        <div class="hf-ch-tabs">
            <a href="?section=rumors" class="<?= $section === 'rumors' ? 'active' : '' ?>">
                <?= t('ch_rumors') ?>
            </a>
            <a href="?section=duels" class="<?= $section === 'duels' ? 'active' : '' ?>">
                <?= t('ch_duels') ?>
            </a>
            <a href="?section=trivia" class="<?= $section === 'trivia' ? 'active' : '' ?>">
                <?= t('ch_trivia') ?>
            </a>
            <a href="?section=board" class="<?= $section === 'board' ? 'active' : '' ?>">
                <?= t('ch_board') ?>
            </a>
        </div>

        <?php if ($section !== 'overview'): ?>
            <a href="?section=overview" class="hf-ch-back" data-testid="ch-back-to-overview">
                <i class="fas fa-chevron-left"></i> <?= t('ch_back_to_overview') ?>
            </a>
        <?php endif; ?>

        <?php if ($isPublic): ?>
            <p style="font-size:12px;color:var(--text-secondary);margin:-8px 0 16px;text-align:center;" data-testid="ch-public-guest-banner">
                <?= t('ch_public_guest_banner') ?>
            </p>
        <?php endif; ?>

        <?php if ($section === 'rumors'): ?>
            <!-- Guest-playable (REQ-101) — hero shows regardless of $isPublic; $answeredToday
                 defaults to 0 with no participant, so the stat is accurate for a first-time guest. -->
            <div class="hf-ch-hero">
                <span class="hf-ch-hero-eyebrow"><?= t('ch_todays_deck') ?></span>
                <div class="hf-ch-hero-title" style="margin-top:12px;"><?= t('ch_rumors') ?></div>
                <div class="hf-ch-hero-sub"><?= t('ch_rumors_desc') ?></div>
                <div class="hf-ch-hero-stats">
                    <div>
                        <div class="hf-stat-n" style="font-size:22px;"><?= $answeredToday ?> / <?= $deckSize ?></div>
                        <div class="hf-stat-l"><?= t('ch_todays_deck') ?></div>
                    </div>
                </div>
            </div>
        <?php elseif ($section === 'trivia'): ?>
            <!-- Guest-playable (REQ-101) — same reasoning as rumors above. -->
            <div class="hf-ch-hero">
                <span class="hf-ch-hero-eyebrow"><?= t('ch_weekly_quiz') ?></span>
                <div class="hf-ch-hero-title" style="margin-top:12px;"><?= t('ch_trivia') ?></div>
                <div class="hf-ch-hero-sub"><?= t('ch_trivia_desc') ?></div>
                <div class="hf-ch-hero-stats">
                    <div>
                        <div class="hf-stat-n" style="font-size:22px;"><?= $weekAnswered ?> / <?= $weekTotal ?></div>
                        <div class="hf-stat-l"><?= t('ch_weekly_quiz') ?></div>
                    </div>
                </div>
            </div>
        <?php elseif ($section === 'duels'): ?>
            <?php if ($isVerifiedParticipant): ?>
                <!-- Unverified (whether a totally anonymous guest or an existing-but-unverified
                     participant) gets its own unified hero+CTA further down, on the actual
                     duel-verify-prompt block (data-testid="duel-verify-prompt") instead. -->
                <div class="hf-ch-hero">
                    <span class="hf-ch-hero-eyebrow"><?= t('ch_duels') ?></span>
                    <div class="hf-ch-hero-title" style="margin-top:12px;"><?= t('ch_duels') ?></div>
                    <div class="hf-ch-hero-sub"><?= t('ch_duels_desc') ?></div>
                    <div class="hf-ch-hero-stats">
                        <div>
                            <div class="hf-stat-n" style="font-size:22px;"><?= count($needsPickDuels) ?></div>
                            <div class="hf-stat-l"><?= t('ch_your_move') ?></div>
                        </div>
                        <div>
                            <div class="hf-stat-n" style="font-size:22px;"><?= count($waitingDuels) ?></div>
                            <div class="hf-stat-l"><?= t('ch_duel_waiting') ?></div>
                        </div>
                        <div>
                            <div class="hf-stat-n" style="font-size:22px;"><?= count($settledDuels) ?></div>
                            <div class="hf-stat-l"><?= t('ch_settled') ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($section === 'overview'): ?>
            <?php if ($isPublic): ?>
                <div class="hf-ch-hero">
                    <span class="hf-ch-hero-eyebrow"><?= t('ch_hero_eyebrow') ?></span>
                    <div class="hf-ch-hero-title" style="margin-top:12px;"><?= t('ch_nav_challenges') ?></div>
                    <div class="hf-ch-hero-sub"><?= t('ch_hero_sub') ?></div>
                    <a href="challenges-join.php" class="btn btn-primary btn-accent-challenges" style="margin-top:20px;display:inline-block;">
                        <?= t('ch_play_now') ?>
                    </a>
                </div>
            <?php else: ?>
                <?php
                    $ovCpTotal = getChallengeCpTotal($db, $participant['id']);
                    $ovStreak  = getChallengeStreak($db, $participant['id']);
                    $ovRank    = getChallengeRank($db, $participant['id']);
                ?>
                <div class="hf-ch-hero">
                    <div class="hf-ch-hero-top" style="margin-top:0;">
                        <span class="hf-ch-hero-eyebrow"><?= t('ch_your_standing') ?></span>
                        <?php if ($ovRank['rank']): ?>
                            <span class="hf-ch-rank-pill" data-testid="ch-rank-pill">P<?= $ovRank['rank'] ?> / <?= $ovRank['total'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="hf-ch-hero-num" data-testid="ch-cp-total" style="margin-top:12px;"><?= $ovCpTotal ?></div>
                    <div class="hf-ch-hero-sub"><?= t('ch_your_cp') ?><?php if ($ovStreak > 0): ?> &middot; <?= sprintf(t('ch_streak_line'), $ovStreak) ?><?php endif; ?></div>

                    <div class="hf-ch-hero-stats">
                        <div>
                            <div class="hf-stat-n" style="font-size:22px;color:var(--status-success, #10b981);">+<?= getChallengeCpThisWeek($db, $participant['id']) ?></div>
                            <div class="hf-stat-l"><?= t('ch_this_week') ?></div>
                        </div>
                        <div>
                            <div class="hf-stat-n" style="font-size:22px;color:var(--f1-red-light);"><i class="fa-solid fa-fire" style="font-size:16px;"></i> <?= $ovStreak ?></div>
                            <div class="hf-stat-l"><?= t('ch_streak') ?></div>
                        </div>
                        <div>
                            <div class="hf-stat-n" style="font-size:22px;"><?= $pwCount ?> / <?= $pwWeekTotal ?></div>
                            <div class="hf-stat-l"><?= t('ch_perfect_week') ?></div>
                        </div>
                    </div>
                </div>

                <div class="hf-scoreboard">
                    <h2 style="margin:0 0 16px;font-size:16px;font-weight:700;">
                        <?= sprintf(t('ch_perfect_week_heading'), $pwIsoWeek) ?>
                    </h2>
                    <div data-testid="perfect-week-tracker" data-filled="<?= $pwCount ?>" data-total="<?= $pwWeekTotal ?>" style="display:flex;gap:8px;">
                        <?php for ($i = 0; $i < $pwWeekTotal; $i++): ?>
                            <div style="width:40px;height:40px;background:<?= $i < $pwCount ? 'var(--gold, #fbbf24)' : 'var(--bg-card)' ?>;border-radius:8px;display:flex;align-items:center;justify-content:center;color:<?= $i < $pwCount ? '#1a1a1a' : 'var(--text-primary)' ?>;font-weight:700;font-size:12px;">
                                <?php if ($i < $pwCount): ?><i class="fa-solid fa-check"></i><?php else: ?><?= ($i + 1) ?><?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div style="font-size:11px;color:var(--text-secondary);margin-top:10px;line-height:1.4;">
                        <?= t('ch_perfect_week_explainer') ?>
                    </div>
                </div>

                <div class="hf-ch-games-kicker"><?= t('ch_games_live') ?></div>

                <a href="?section=rumors" class="hf-ch-game-row" data-testid="ch-game-row-rumors">
                    <span class="hf-ch-game-icon rumors"><i class="fas fa-circle-question"></i></span>
                    <span class="hf-ch-game-body">
                        <span class="hf-ch-game-name"><?= t('ch_rumors') ?></span>
                        <span class="hf-ch-game-meta"><?= t('ch_todays_deck') ?></span>
                        <span class="hf-ch-game-progress"><span style="width:<?= $ovDeckSize > 0 ? min(100, round($ovRumorsToday / $ovDeckSize * 100)) : 0 ?>%"></span></span>
                    </span>
                    <span class="hf-ch-game-right"><?= $ovRumorsToday ?> / <?= $ovDeckSize ?></span>
                </a>

                <a href="?section=trivia" class="hf-ch-game-row" data-testid="ch-game-row-trivia">
                    <span class="hf-ch-game-icon trivia"><i class="fa-solid fa-brain"></i></span>
                    <span class="hf-ch-game-body">
                        <span class="hf-ch-game-name"><?= t('ch_trivia') ?></span>
                        <span class="hf-ch-game-meta"><?= t('ch_weekly_quiz') ?></span>
                        <span class="hf-ch-game-progress"><span style="width:<?= $ovTriviaWeekTotal > 0 ? min(100, round($ovTriviaWeekAnswered / $ovTriviaWeekTotal * 100)) : 0 ?>%"></span></span>
                    </span>
                    <span class="hf-ch-game-right"><?= $ovTriviaWeekAnswered ?> / <?= $ovTriviaWeekTotal ?></span>
                </a>

                <a href="?section=duels<?= $ovPendingDuel ? '&duel=' . urlencode($ovPendingDuel['id']) : '' ?>" class="hf-ch-game-row<?= $ovPendingDuel ? ' pending' : '' ?>" data-testid="ch-game-row-duels">
                    <span class="hf-ch-game-icon duels"><i class="fa-solid fa-bolt"></i></span>
                    <span class="hf-ch-game-body">
                        <span class="hf-ch-game-name"><?= t('ch_duels') ?></span>
                        <span class="hf-ch-game-meta"><?= $ovPendingDuel ? htmlspecialchars($ovPendingDuel['opponent_name']) : t('ch_duels_desc') ?></span>
                    </span>
                    <?php if ($ovPendingDuel): ?>
                        <span class="hf-ch-game-right cta"><?= t('ch_your_move') ?></span>
                    <?php else: ?>
                        <span class="hf-ch-game-right">&rarr;</span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($section === 'rumors'): ?>
            <?php if ($rumorDone): ?>
                <div data-testid="rumor-done" style="text-align:center;padding:44px 12px;">
                    <div style="font-size:38px;color:var(--gold, #fbbf24);"><i class="fa-solid fa-champagne-glasses"></i></div>
                    <div style="font-family:var(--display, inherit);font-weight:800;font-size:19px;margin-top:12px;color:var(--text-primary);">
                        <?= t('ch_deck_cleared') ?>
                    </div>
                    <div style="font-size:13px;margin-top:6px;color:var(--text-secondary);">
                        <?= t('ch_deck_cleared_sub') ?>
                    </div>
                    <a href="challenges-invite.php?game=rumor_or_not" class="btn btn-primary btn-accent-challenges" style="margin-top:16px;display:inline-block;">
                        <?= t('ch_challenge_a_friend') ?>
                    </a>
                </div>
            <?php else: ?>
                <?php $card = $revealedItem ?: $rumorCurrent; $answered = (bool)$revealedItem; ?>
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:13px;color:var(--text-secondary);font-weight:600;"><?= t('ch_todays_deck') ?></span>
                    <span style="font-size:12px;color:var(--text-secondary);font-family:monospace;"><?= $answeredToday ?> / <?= $deckSize ?></span>
                </div>
                <div data-testid="rumor-card" style="border-radius:16px;background:var(--bg-card);border:1.5px solid <?= $answered ? ($card['correct'] ? 'var(--status-success, #10b981)' : 'var(--f1-red, #e10600)') : 'var(--border-color)' ?>;padding:20px 18px;margin-top:12px;animation:pp-pop .28s ease;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <span class="hf-badge" style="background:rgba(225,6,0,.14);color:var(--f1-red-light, #ff8a80);border:1px solid rgba(225,6,0,.4);padding:4px 10px;border-radius:7px;font-size:11px;font-weight:700;">
                            <?= escape($card['context_' . $lang] ?: $card['context_da']) ?>
                        </span>
                        <?php if ($answered): ?>
                            <span data-testid="rumor-stamp" data-is-real="<?= $card['is_real'] ? '1' : '0' ?>" style="font-weight:800;font-size:12px;padding:4px 10px;border-radius:7px;background:<?= $card['is_real'] ? 'var(--status-success, #10b981)' : 'var(--f1-red, #e10600)' ?>;color:#fff;">
                                <?= $card['is_real'] ? t('ch_stamp_real') : t('ch_stamp_rumor') ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div style="font-weight:800;font-size:18px;line-height:1.32;margin-top:15px;color:var(--text-primary);">
                        <?= escape($card['text_' . $lang] ?: $card['text_da']) ?>
                    </div>
                    <?php if ($answered): ?>
                        <div style="margin-top:14px;padding-top:13px;border-top:1px solid var(--border-color);">
                            <div data-testid="rumor-result" data-correct="<?= $card['correct'] ? '1' : '0' ?>" style="display:flex;align-items:center;gap:8px;font-weight:800;font-size:14px;color:<?= $card['correct'] ? 'var(--status-success, #10b981)' : 'var(--f1-red, #e10600)' ?>;">
                                <i class="fa-solid <?= $card['correct'] ? 'fa-check' : 'fa-xmark' ?>"></i>
                                <?= $card['correct'] ? t('ch_reveal_correct') : t('ch_reveal_missed') ?>
                                <?php if ($card['correct']): ?> · <?= sprintf(t('ch_reveal_cp'), 10) ?><?php endif; ?>
                            </div>
                            <div style="font-size:12.5px;line-height:1.5;margin-top:7px;color:var(--text-secondary);">
                                <?= escape($card['explain_' . $lang] ?: $card['explain_da']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($answered): ?>
                    <script nonce="<?= $nonce ?>">hfToast(<?= json_encode($card['correct'] ? sprintf(t('ch_toast_cp'), 10) : t('ch_toast_miss')) ?>);</script>
                <?php endif; ?>

                <?php if (!$answered): ?>
                    <form method="POST" style="margin-top:16px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="item_id" value="<?= escape($card['id']) ?>">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <button type="submit" name="guess_real" value="0" data-testid="rumor-guess-rumor" style="height:56px;border-radius:14px;border:1.5px solid var(--f1-red, #e10600);background:rgba(225,6,0,.12);color:var(--f1-red-light, #ff8a80);font-weight:800;font-size:15px;cursor:pointer;">
                                <?= t('ch_guess_rumor') ?>
                            </button>
                            <button type="submit" name="guess_real" value="1" data-testid="rumor-guess-real" style="height:56px;border-radius:14px;border:1.5px solid var(--status-success, #10b981);background:rgba(16,185,129,.12);color:var(--status-success, #10b981);font-weight:800;font-size:15px;cursor:pointer;">
                                <?= t('ch_guess_real') ?>
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <a href="?section=rumors" data-testid="rumor-next" class="btn btn-primary btn-accent-challenges" style="width:100%;margin-top:16px;display:block;text-align:center;">
                        <?= $rumorCurrent ? t('ch_next_card') : t('ch_finish_deck') ?> <span aria-hidden="true">&rarr;</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($section === 'trivia'): ?>
            <?php if ($triviaDone): ?>
                <?php
                    $weekFinished = $weekTotal > 0 && $weekAnswered === $weekTotal;
                    $weekPerfect  = $weekFinished && $weekCorrect === $weekTotal;
                ?>
                <div data-testid="trivia-done" data-perfect="<?= $weekPerfect ? '1' : '0' ?>" style="text-align:center;padding:44px 12px;">
                    <div style="font-size:38px;color:var(--gold, #fbbf24);">
                        <i class="fa-solid <?= $weekPerfect ? 'fa-star' : 'fa-clipboard-check' ?>"></i>
                    </div>
                    <div style="font-family:var(--display, inherit);font-weight:800;font-size:19px;margin-top:12px;color:var(--text-primary);">
                        <?php if ($weekPerfect): ?>
                            <?= t('ch_perfect_week') ?>
                        <?php elseif ($weekFinished): ?>
                            <?= t('ch_quiz_complete') ?>
                        <?php else: ?>
                            <?= t('ch_all_caught_up') ?>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:13px;margin-top:6px;color:var(--text-secondary);">
                        <?php if ($weekPerfect): ?>
                            <?= t('ch_perfect_week_sub') ?>
                        <?php elseif ($weekFinished): ?>
                            <?= sprintf(t('ch_quiz_complete_sub'), $weekCorrect, $weekTotal) ?>
                        <?php else: ?>
                            <?= t('ch_all_caught_up_sub') ?>
                        <?php endif; ?>
                    </div>
                    <a href="challenges-invite.php?game=trivia" class="btn btn-primary btn-accent-challenges" style="margin-top:16px;display:inline-block;">
                        <?= t('ch_challenge_a_friend') ?>
                    </a>
                </div>
            <?php else: ?>
                <?php
                    $q = $revealedQuestion ?: $triviaCurrent;
                    $answered = (bool)$revealedQuestion;
                    $options = json_decode($q['options_' . $lang] ?: $q['options_da'], true) ?: [];
                ?>
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:13px;color:var(--text-secondary);font-weight:600;"><?= t('ch_weekly_quiz') ?></span>
                    <span style="font-size:12px;color:var(--text-secondary);font-family:monospace;"><?= $weekAnswered ?> / <?= $weekTotal ?></span>
                </div>
                <div data-testid="trivia-card" style="border-radius:16px;background:var(--bg-card);border:1px solid var(--border-color);padding:20px 18px;margin-top:12px;animation:pp-pop .28s ease;">
                    <span class="hf-badge" style="background:rgba(59,130,246,.14);color:var(--f1-accent-challenges-light, #5b9bff);border:1px solid rgba(59,130,246,.35);padding:4px 10px;border-radius:7px;font-size:11px;font-weight:700;">
                        <?= escape(strtoupper($q['topic'])) ?>
                    </span>
                    <div style="font-weight:800;font-size:18px;line-height:1.3;margin-top:14px;color:var(--text-primary);">
                        <?= escape($q['question_' . $lang] ?: $q['question_da']) ?>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:10px;margin-top:16px;">
                        <?php foreach ($options as $idx => $optionText): ?>
                            <?php
                                $isCorrectOpt  = $answered && $idx === (int)$q['correct_option'];
                                $isChosenWrong = $answered && !$isCorrectOpt && $idx === (int)$q['chosen_option'];
                                $bg = 'var(--bg-hover)'; $border = 'var(--border-color)'; $color = 'var(--text-primary)';
                                if ($isCorrectOpt) { $bg = 'rgba(16,185,129,.14)'; $border = 'var(--status-success, #10b981)'; $color = 'var(--status-success, #10b981)'; }
                                elseif ($isChosenWrong) { $bg = 'rgba(225,6,0,.14)'; $border = 'var(--f1-red, #e10600)'; $color = 'var(--f1-red-light, #ff8a80)'; }
                                elseif ($answered) { $color = 'var(--text-muted)'; }
                            ?>
                            <?php if ($answered): ?>
                                <div data-testid="trivia-option" data-idx="<?= $idx ?>" style="display:flex;align-items:center;gap:11px;text-align:left;padding:13px 14px;border-radius:12px;background:<?= $bg ?>;border:1.5px solid <?= $border ?>;color:<?= $color ?>;font-weight:600;font-size:14px;">
                                    <span style="width:22px;height:22px;flex-shrink:0;border-radius:6px;background:var(--bg-hover);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;"><?= chr(65 + $idx) ?></span>
                                    <span style="flex:1;"><?= escape($optionText) ?></span>
                                    <?php if ($isCorrectOpt): ?><i class="fa-solid fa-circle-check" style="color:var(--status-success, #10b981);"></i><?php endif; ?>
                                    <?php if ($isChosenWrong): ?><i class="fa-solid fa-circle-xmark" style="color:var(--f1-red, #e10600);"></i><?php endif; ?>
                                </div>
                            <?php else: ?>
                                <form method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="question_id" value="<?= escape($q['id']) ?>">
                                    <input type="hidden" name="chosen_option" value="<?= $idx ?>">
                                    <button type="submit" data-testid="trivia-option" data-idx="<?= $idx ?>" style="width:100%;display:flex;align-items:center;gap:11px;text-align:left;padding:13px 14px;border-radius:12px;background:<?= $bg ?>;border:1.5px solid <?= $border ?>;color:<?= $color ?>;font-weight:600;font-size:14px;cursor:pointer;">
                                        <span style="width:22px;height:22px;flex-shrink:0;border-radius:6px;background:var(--bg-hover);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;"><?= chr(65 + $idx) ?></span>
                                        <span style="flex:1;"><?= escape($optionText) ?></span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($answered): ?>
                        <div data-testid="trivia-result" data-correct="<?= $q['correct'] ? '1' : '0' ?>" style="margin-top:14px;padding:13px;border-radius:11px;background:<?= $q['correct'] ? 'rgba(16,185,129,.10)' : 'rgba(225,6,0,.10)' ?>;border:1px solid <?= $q['correct'] ? 'rgba(16,185,129,.4)' : 'rgba(225,6,0,.4)' ?>;">
                            <div style="display:flex;align-items:center;gap:8px;font-weight:800;font-size:14px;color:<?= $q['correct'] ? 'var(--status-success, #10b981)' : 'var(--f1-red, #e10600)' ?>;">
                                <i class="fa-solid <?= $q['correct'] ? 'fa-check' : 'fa-xmark' ?>"></i>
                                <?= $q['correct'] ? t('ch_reveal_correct') : t('ch_trivia_wrong') ?>
                                <?php if ($q['correct']): ?> · <?= sprintf(t('ch_reveal_cp'), 5) ?><?php endif; ?>
                            </div>
                            <div style="font-size:12.5px;line-height:1.5;margin-top:7px;color:var(--text-secondary);">
                                <?= escape($q['explain_' . $lang] ?: $q['explain_da']) ?>
                            </div>
                        </div>
                        <a href="?section=trivia" data-testid="trivia-next" class="btn btn-primary btn-accent-challenges" style="width:100%;margin-top:13px;display:block;text-align:center;">
                            <?= $triviaCurrent ? t('ch_next_question') : t('ch_finish_quiz') ?> <span aria-hidden="true">&rarr;</span>
                        </a>
                        <script nonce="<?= $nonce ?>">hfToast(<?= json_encode($q['correct'] ? sprintf(t('ch_toast_cp'), 5) : t('ch_toast_miss')) ?>);</script>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($section === 'duels'): ?>
            <?php if (($_GET['picked'] ?? '') === '1'): ?>
                <script nonce="<?= $nonce ?>">hfToast(<?= json_encode(t('ch_toast_duel_locked')) ?>);</script>
            <?php endif; ?>
            <?php if (!$isVerifiedParticipant): ?>
                <div class="hf-ch-hero" data-testid="duel-verify-prompt">
                    <span class="hf-ch-hero-eyebrow"><?= t('ch_duels') ?></span>
                    <div class="hf-ch-hero-title" style="margin-top:12px;"><?= t('ch_duels') ?></div>
                    <div class="hf-ch-hero-sub"><?= t('ch_duel_verify_prompt') ?></div>
                    <a href="challenges-join.php" class="btn btn-primary btn-accent-challenges" style="margin-top:20px;display:inline-block;"><?= t('ch_play_now') ?></a>
                </div>

            <?php elseif ($duelMode === 'challenge'): ?>
                <div data-testid="duel-search" style="padding-top:8px;">
                    <form method="GET" style="display:flex;gap:8px;margin-bottom:16px;">
                        <input type="hidden" name="section" value="duels">
                        <input type="hidden" name="mode" value="challenge">
                        <input type="text" name="q" value="<?= escape($friendQuery) ?>" placeholder="<?= t('ch_duel_search_placeholder') ?>" data-testid="duel-search-input" style="flex:1;padding:10px 14px;border-radius:10px;border:1px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);">
                        <button type="submit" class="btn btn-primary btn-accent-challenges"><?= t('ch_duel_search') ?></button>
                    </form>

                    <?php if ($friendQuery !== '' && empty($friendResults)): ?>
                        <p data-testid="duel-no-results" style="color:var(--text-secondary);font-size:13px;"><?= t('ch_duel_no_results') ?></p>
                    <?php endif; ?>

                    <?php foreach ($friendResults as $fr): ?>
                        <div data-testid="duel-search-result" data-participant-id="<?= escape($fr['id']) ?>" style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--bg-card);border-radius:12px;padding:12px 16px;margin-bottom:8px;">
                            <span style="min-width:0;">
                                <span style="display:block;font-weight:700;color:var(--text-primary);"><?= escape($fr['display_name']) ?></span>
                                <?php if (!empty($fr['email_hint'])): ?>
                                    <span data-testid="duel-search-email-hint" style="display:block;font-size:12px;color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= escape($fr['email_hint']) ?></span>
                                <?php endif; ?>
                            </span>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="challenge_friend">
                                <input type="hidden" name="opponent_id" value="<?= escape($fr['id']) ?>">
                                <button type="submit" data-testid="duel-challenge-btn" class="btn btn-primary btn-accent-challenges btn-sm"><?= t('ch_duel_challenge_button') ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>

                    <div style="margin-top:16px;">
                        <a href="?section=duels" style="color:var(--text-secondary);font-size:13px;"><?= t('ch_back') ?></a>
                    </div>
                </div>

            <?php elseif ($viewDuel): ?>
                <?php
                    $showBothPicks = ($viewDuel['locked'] || in_array($viewDuel['status'], ['resolved', 'void'], true)) && $viewDuel['my_pick'];
                    $meInitials    = strtoupper(substr($participant['display_name'] ?: '?', 0, 2));
                    $otherInitials = strtoupper(substr($viewDuel['other_name'] ?: '?', 0, 2));
                ?>
                <div data-testid="duel-detail" data-duel-id="<?= escape($viewDuel['id']) ?>" data-status="<?= escape($viewDuel['status']) ?>" style="padding-top:8px;">
                    <div style="border-radius:16px;background:var(--bg-card);border:1px solid rgba(245,158,11,.4);padding:20px 18px;animation:pp-pop .28s ease;">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <span class="label-mono" style="font-size:11px;color:var(--text-secondary);"><?= escape($viewDuel['race_name']) ?></span>
                            <?php if ($viewDuel['status'] === 'void'): ?>
                                <span class="hf-badge done"><?= t('ch_duel_void') ?></span>
                            <?php elseif ($viewDuel['status'] === 'resolved'): ?>
                                <span class="hf-badge open"><?= t('ch_settled') ?></span>
                            <?php elseif ($viewDuel['locked']): ?>
                                <span class="hf-badge soon"><?= t('ch_race_started') ?></span>
                            <?php else: ?>
                                <span class="hf-badge open"><?= t('ch_duel_waiting') ?></span>
                            <?php endif; ?>
                        </div>

                        <div style="display:flex;align-items:center;gap:12px;margin-top:14px;">
                            <div style="flex:1;text-align:center;">
                                <div style="width:44px;height:44px;border-radius:50%;margin:0 auto;background:var(--f1-red,#e10600);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;"><?= escape($meInitials) ?></div>
                                <div style="font-weight:700;font-size:13px;margin-top:6px;color:var(--text-primary);"><?= t('ch_you') ?></div>
                            </div>
                            <div style="font-weight:900;font-size:15px;color:var(--gold,#fbbf24);">VS</div>
                            <div style="flex:1;text-align:center;">
                                <div style="width:44px;height:44px;border-radius:50%;margin:0 auto;background:var(--bg-hover);color:var(--text-primary);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;"><?= escape($otherInitials) ?></div>
                                <div style="font-weight:700;font-size:13px;margin-top:6px;color:var(--text-primary);"><?= escape($viewDuel['other_name'] ?: '?') ?></div>
                            </div>
                        </div>

                        <?php if ($showBothPicks): ?>
                            <div data-testid="duel-picks-comparison" style="margin-top:16px;display:grid;grid-template-columns:1fr 1fr;gap:10px;border-top:1px solid var(--border-color);padding-top:14px;">
                                <div>
                                    <?php foreach (['p1', 'p2', 'p3'] as $key): ?>
                                        <div data-testid="duel-my-<?= $key ?>" style="font-size:13px;color:var(--text-primary);padding:4px 0;"><?= driverLastName($duelDriversById[$viewDuel['my_pick'][$key]] ?? ['name' => '?']) ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <div>
                                    <?php if ($viewDuel['other_pick']): ?>
                                        <?php foreach (['p1', 'p2', 'p3'] as $key): ?>
                                            <div data-testid="duel-other-<?= $key ?>" style="font-size:13px;color:var(--text-primary);padding:4px 0;"><?= driverLastName($duelDriversById[$viewDuel['other_pick'][$key]] ?? ['name' => '?']) ?></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="font-size:13px;color:var(--text-secondary);"><?= t('ch_duel_void') ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($viewDuel['status'] === 'resolved'): ?>
                            <?php
                                $myScore    = $viewDuel['my_pick']['score'] ?? 0;
                                $otherScore = $viewDuel['other_pick']['score'] ?? 0;
                                $tied       = $viewDuel['winner_id'] === null;
                                $iWon       = !$tied && $viewDuel['winner_id'] === $participant['id'];
                                $cp         = $tied ? 10 : ($iWon ? 15 : 5);
                                $outcome    = $tied ? 'tie' : ($iWon ? 'won' : 'lost');
                            ?>
                            <div data-testid="duel-result" data-outcome="<?= $outcome ?>" style="margin-top:14px;text-align:center;">
                                <div style="font-weight:800;font-size:16px;color:<?= $tied ? 'var(--gold, #fbbf24)' : ($iWon ? 'var(--status-success, #10b981)' : 'var(--f1-red, #e10600)') ?>;">
                                    <?= $tied ? t('ch_duel_tied') : ($iWon ? t('ch_duel_won') : t('ch_duel_lost')) ?> · <?= sprintf(t('ch_reveal_cp'), $cp) ?>
                                </div>
                                <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;"><?= (int)$myScore ?> – <?= (int)$otherScore ?></div>
                            </div>
                        <?php elseif ($viewDuel['status'] === 'void'): ?>
                            <div data-testid="duel-result" data-outcome="void" style="margin-top:14px;text-align:center;color:var(--text-secondary);font-size:13px;">
                                <?= t('ch_duel_void') ?>
                            </div>
                        <?php elseif (!$viewDuel['my_pick']): ?>
                            <?php if ($viewDuel['locked']): ?>
                                <div style="margin-top:14px;text-align:center;color:var(--text-secondary);font-size:13px;"><?= t('ch_race_started') ?></div>
                            <?php else: ?>
                                <form method="POST" data-testid="duel-pick-form" style="margin-top:16px;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="submit_pick">
                                    <input type="hidden" name="duel_id" value="<?= escape($viewDuel['id']) ?>">
                                    <div style="display:flex;flex-direction:column;gap:10px;">
                                        <?php foreach ([1 => 'p1', 2 => 'p2', 3 => 'p3'] as $pos => $key): ?>
                                            <div style="display:flex;align-items:center;gap:10px;">
                                                <span class="hf-badge" style="background:rgba(225,6,0,.14);color:var(--f1-red-light, #ff8a80);border:1px solid rgba(225,6,0,.4);padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;">P<?= $pos ?></span>
                                                <select name="<?= $key ?>" required data-testid="duel-pick-<?= $key ?>" style="flex:1;padding:10px;border-radius:10px;border:1px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);">
                                                    <option value=""><?= t('select_driver') ?></option>
                                                    <?php foreach ($duelDrivers as $drv): ?>
                                                        <option value="<?= escape($drv['id']) ?>"><?= driverLastName($drv) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="submit" data-testid="duel-pick-submit" class="btn btn-primary btn-accent-challenges" style="width:100%;margin-top:14px;"><?= t('ch_accept_lock') ?></button>
                                </form>
                                <?php if (!empty($_GET['pickerror'])): ?>
                                    <div class="alert alert-error" style="margin-top:10px;"><?= t('ch_duel_pick_error') ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <div data-testid="duel-locked-in" style="margin-top:14px;text-align:center;">
                                <span class="hf-badge open"><?= t('ch_locked_in') ?></span>
                                <?php if (!$viewDuel['locked']): ?>
                                    <?php if ($viewDuel['other_pick']): ?>
                                        <div data-testid="duel-waiting-race" style="font-size:12px;color:var(--text-secondary);margin-top:8px;"><?= t('ch_duel_waiting_race') ?></div>
                                    <?php else: ?>
                                        <div data-testid="duel-waiting-opponent" style="font-size:12px;color:var(--text-secondary);margin-top:8px;"><?= sprintf(t('ch_duel_waiting_for'), $viewDuel['other_name']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top:16px;">
                        <a href="?section=duels" style="color:var(--text-secondary);font-size:13px;"><?= t('ch_back') ?></a>
                    </div>
                </div>

            <?php else: ?>
                <div data-testid="duel-list" style="padding-top:8px;">
                    <?php if (!empty($_GET['queued'])): ?>
                        <div class="alert alert-success" data-testid="duel-queued-msg"><?= t('ch_duel_queued') ?></div>
                    <?php endif; ?>

                    <?php if ($duelRace && !$duelRaceLocked): ?>
                        <div style="display:flex;gap:10px;margin-bottom:20px;">
                            <form method="POST" style="flex:1;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="quick_match">
                                <button type="submit" data-testid="duel-quick-match-btn" class="btn btn-primary btn-accent-challenges" style="width:100%;"><?= t('ch_quick_match') ?></button>
                            </form>
                            <a href="?section=duels&mode=challenge" data-testid="duel-challenge-friend-link" class="btn btn-secondary" style="flex:1;text-align:center;"><?= t('ch_challenge_a_friend') ?></a>
                        </div>
                    <?php elseif (!$duelRace): ?>
                        <p style="color:var(--text-secondary);font-size:13px;text-align:center;padding:20px 0;"><?= t('ch_duel_no_race') ?></p>
                    <?php endif; ?>

                    <?php if ($needsPickDuels): ?>
                        <div class="hf-stat-l" style="margin:16px 0 10px;"><?= t('ch_your_move') ?></div>
                        <?php foreach ($needsPickDuels as $d): ?>
                            <a href="?section=duels&duel=<?= urlencode($d['id']) ?>" data-testid="duel-card" data-duel-id="<?= escape($d['id']) ?>" data-bucket="needs-pick" style="display:block;text-decoration:none;border-radius:14px;background:var(--bg-card);border:1px solid rgba(245,158,11,.4);padding:14px;margin-bottom:10px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;">
                                    <span style="font-weight:700;font-size:13px;color:var(--text-primary);">vs <?= escape($d['other_name'] ?: '?') ?></span>
                                    <span class="hf-badge soon" style="font-size:9px;"><?= t('ch_your_move') ?></span>
                                </div>
                                <div style="font-size:11px;color:var(--text-secondary);margin-top:4px;"><?= escape($d['race_name']) ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($waitingDuels): ?>
                        <div class="hf-stat-l" style="margin:16px 0 10px;"><?= t('ch_duel_waiting') ?></div>
                        <?php foreach ($waitingDuels as $d): ?>
                            <a href="?section=duels&duel=<?= urlencode($d['id']) ?>" data-testid="duel-card" data-duel-id="<?= escape($d['id']) ?>" data-bucket="waiting" style="display:block;text-decoration:none;border-radius:14px;background:var(--bg-card);border:1px solid var(--border-color);padding:14px;margin-bottom:10px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;">
                                    <span style="font-weight:700;font-size:13px;color:var(--text-primary);">vs <?= escape($d['other_name'] ?: '?') ?></span>
                                    <span class="hf-badge" style="font-size:9px;background:var(--bg-hover);color:var(--text-secondary);"><?= $d['locked'] ? t('ch_race_started') : t('ch_locked_in') ?></span>
                                </div>
                                <div style="font-size:11px;color:var(--text-secondary);margin-top:4px;"><?= escape($d['race_name']) ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($settledDuels): ?>
                        <div class="hf-stat-l" style="margin:16px 0 10px;"><?= t('ch_settled') ?></div>
                        <?php foreach ($settledDuels as $d): ?>
                            <?php
                                $dTied = $d['status'] === 'resolved' && $d['winner_id'] === null;
                                $dWon  = $d['status'] === 'resolved' && $d['winner_id'] === $participant['id'];
                                $dCp   = $d['status'] === 'void' ? 0 : ($dTied ? 10 : ($dWon ? 15 : 5));
                            ?>
                            <a href="?section=duels&duel=<?= urlencode($d['id']) ?>" data-testid="duel-card" data-duel-id="<?= escape($d['id']) ?>" data-bucket="settled" style="display:flex;align-items:center;justify-content:space-between;text-decoration:none;border-radius:14px;background:var(--bg-card);border:1px solid var(--border-color);padding:14px;margin-bottom:10px;">
                                <div>
                                    <span style="font-weight:700;font-size:13px;color:var(--text-primary);">vs <?= escape($d['other_name'] ?: '?') ?></span>
                                    <div style="font-size:11px;color:var(--text-secondary);margin-top:4px;"><?= escape($d['race_name']) ?></div>
                                </div>
                                <span style="font-weight:800;font-size:13px;color:<?= $d['status'] === 'void' ? 'var(--text-secondary)' : ($dTied ? 'var(--gold, #fbbf24)' : ($dWon ? 'var(--status-success, #10b981)' : 'var(--f1-red, #e10600)')) ?>;">
                                    <?= $d['status'] === 'void' ? t('ch_duel_void') : ($dTied ? t('ch_duel_tied') : ($dWon ? t('ch_duel_won') : t('ch_duel_lost'))) ?>
                                    <?php if ($d['status'] !== 'void'): ?> +<?= $dCp ?><?php endif; ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!$needsPickDuels && !$waitingDuels && !$settledDuels): ?>
                        <p data-testid="duel-empty" style="color:var(--text-secondary);font-size:13px;text-align:center;padding:20px 0;"><?= t('ch_all_caught_up') ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($section === 'board'): ?>
            <div class="hf-ch-hero">
                <div class="hf-ch-hero-top" style="margin-top:0;">
                    <span class="hf-ch-hero-eyebrow"><?= t('ch_board_eyebrow') ?></span>
                    <?php if ($boardRank['rank']): ?>
                        <span class="hf-ch-rank-pill" data-testid="ch-board-rank-pill">P<?= $boardRank['rank'] ?> / <?= $boardRank['total'] ?></span>
                    <?php endif; ?>
                </div>
                <div class="hf-ch-hero-title" style="margin-top:12px;"><?= t('ch_public_board') ?></div>
                <div class="hf-ch-hero-sub"><?= t('ch_board_lede') ?></div>
                <div class="hf-ch-hero-stats">
                    <div>
                        <div class="hf-stat-n" style="font-size:22px;"><?= $boardRank['total'] ?></div>
                        <div class="hf-stat-l"><?= t('ch_board_players') ?></div>
                    </div>
                    <div>
                        <div class="hf-stat-n" style="font-size:22px;"><?= intval($boardLeaderboard[0]['total_cp'] ?? 0) ?></div>
                        <div class="hf-stat-l"><?= t('ch_board_leader') ?></div>
                    </div>
                </div>
            </div>

            <div class="hf-ch-board-kicker"><?= t('ch_full_board') ?></div>

            <?php if (empty($boardLeaderboard)): ?>
                <p style="color:var(--text-secondary);font-size:13px;text-align:center;padding:20px 0;"><?= t('ch_all_caught_up') ?></p>
            <?php else: ?>
                <?php foreach ($boardLeaderboard as $index => $row): ?>
                    <?php $isSelf = $participant && $row['participant_id'] === $participant['id']; ?>
                    <div class="hf-ch-board-row<?= $isSelf ? ' self' : '' ?>" data-testid="board-row">
                        <div class="hf-ch-board-rank <?= $index === 0 ? 'r1' : ($index === 1 ? 'r2' : ($index === 2 ? 'r3' : 'other')) ?>">
                            <?= intval($index + 1) ?>
                        </div>
                        <div class="hf-ch-board-name">
                            <?php if ($row['display_name']): ?>
                                <?= escape($row['display_name']) ?>
                            <?php else: ?>
                                Guest <?= substr($row['id'], -4) ?>
                            <?php endif; ?>
                            <?php if ($isSelf): ?><span class="hf-ch-board-you"><?= mb_strtoupper(t('ch_you')) ?></span><?php endif; ?>
                        </div>
                        <div class="hf-ch-board-cp"><?= intval($row['total_cp']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
