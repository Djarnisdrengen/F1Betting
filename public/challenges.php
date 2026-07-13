<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';

$section = $_GET['section'] ?? 'overview';
$validSections = ['overview', 'rumors', 'duels', 'trivia'];

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
}

include __DIR__ . '/includes/header.php';
?>

<style>
.hf-arena-base { background-color: #0b0b0d; }
.hf-arena-header {
    background: linear-gradient(90deg, #17171b, #0d0d10);
    padding: 16px;
    border-bottom: 1px solid rgba(245, 245, 247, 0.1);
}
.hf-arena-band {
    background: rgba(13, 13, 16, 0.95);
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.1em;
    color: #f5f5f7;
}
.hf-arena-strip {
    background: repeating-conic-gradient(#f5f5f7 0 25%, #0b0b0d 0 50%) 0 0/14px 14px;
    height: 8px;
}
.hf-seg {
    display: flex;
    gap: 8px;
    background: rgba(35, 35, 40, 0.62);
    padding: 6px;
    border-radius: 8px;
    margin: 16px 0;
}
.hf-seg button {
    flex: 1;
    padding: 8px 12px;
    background: transparent;
    border: none;
    color: #f5f5f7;
    cursor: pointer;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
}
.hf-seg button.active {
    background: rgba(35, 35, 40, 0.7);
    color: #ff6b35;
}
.hf-scoreboard {
    background: rgba(35, 35, 40, 0.62);
    border-radius: 12px;
    padding: 20px;
    margin: 16px 0;
    box-shadow: 0 0 34px rgba(225, 6, 0, .16);
}
</style>

<div class="hf-arena-base" style="min-height:100vh;padding-bottom:80px;">
    <div class="hf-arena-header">
        <div style="display:flex;align-items:center;justify-content:space-between;">
            <h1 style="margin:0;font-size:24px;font-weight:700;color:#f5f5f7;">
                <i class="fas fa-gamepad" style="margin-right:8px;color:#ff6b35;"></i>
                <?= t('ch_nav_challenges') ?>
            </h1>
        </div>
    </div>

    <div class="hf-arena-strip"></div>

    <div class="hf-arena-band">
        <?= t('ch_games_zone') ?>
    </div>

    <div class="hf-container" style="padding:20px;color:#f5f5f7;">
        <div class="hf-seg">
            <button class="<?= $section === 'overview' ? 'active' : '' ?>" onclick="window.location.href='?section=overview'">
                <?= t('ch_overview') ?>
            </button>
            <button class="<?= $section === 'rumors' ? 'active' : '' ?>" onclick="window.location.href='?section=rumors'">
                <?= t('ch_rumors') ?>
            </button>
            <button class="<?= $section === 'duels' ? 'active' : '' ?>" onclick="window.location.href='?section=duels'">
                <?= t('ch_duels') ?>
            </button>
            <button class="<?= $section === 'trivia' ? 'active' : '' ?>" onclick="window.location.href='?section=trivia'">
                <?= t('ch_trivia') ?>
            </button>
        </div>

        <?php if ($section === 'overview'): ?>
            <?php if ($isPublic): ?>
                <div style="text-align:center;padding:40px 20px;">
                    <p style="font-size:18px;margin-bottom:20px;">
                        <?= t('ch_hero_eyebrow') ?>
                    </p>
                    <p style="font-size:14px;color:#f5f5f7;margin-bottom:30px;">
                        <?= t('ch_hero_sub') ?>
                    </p>
                    <a href="challenges-join.php" class="btn btn-primary">
                        <?= t('ch_play_now') ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="hf-scoreboard">
                    <h2 style="margin:0 0 20px;font-size:18px;font-weight:700;">
                        <?= t('ch_your_standing') ?>
                    </h2>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                        <div style="background:rgba(35,35,40,.7);padding:16px;border-radius:8px;">
                            <div style="font-size:12px;color:#f5f5f7;opacity:0.7;margin-bottom:4px;">
                                <?= t('ch_your_cp') ?>
                            </div>
                            <div style="font-size:28px;font-weight:700;color:#f5f5f7;text-shadow:0 0 24px rgba(251,191,36,.4);">
                                <?= intval($participant['total_cp'] ?? 0) ?>
                            </div>
                        </div>

                        <div style="background:rgba(35,35,40,.7);padding:16px;border-radius:8px;">
                            <div style="font-size:12px;color:#f5f5f7;opacity:0.7;margin-bottom:4px;">
                                <?= t('ch_streak') ?>
                            </div>
                            <div style="font-size:28px;font-weight:700;color:#34d399;">
                                0
                            </div>
                        </div>
                    </div>

                    <div style="font-size:13px;color:#f5f5f7;opacity:0.8;padding:12px;background:rgba(35,35,40,.4);border-radius:6px;">
                        <?= t('ch_games_live') ?>
                    </div>
                </div>

                <div class="hf-scoreboard">
                    <h2 style="margin:0 0 16px;font-size:16px;font-weight:700;">
                        <?= t('ch_perfect_week') ?>
                    </h2>
                    <?php $pwCount = getTriviaCorrectThisWeek($db, $participant['id']); ?>
                    <div data-testid="perfect-week-tracker" data-filled="<?= $pwCount ?>" style="display:flex;gap:8px;">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <div style="width:40px;height:40px;background:<?= $i < $pwCount ? 'var(--gold, #fbbf24)' : 'rgba(35,35,40,.7)' ?>;border-radius:8px;display:flex;align-items:center;justify-content:center;color:<?= $i < $pwCount ? '#1a1a1a' : '#f5f5f7' ?>;font-weight:700;font-size:12px;">
                                <?php if ($i < $pwCount): ?><i class="fa-solid fa-check"></i><?php else: ?><?= ($i + 1) ?><?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($section === 'rumors'): ?>
            <?php if ($rumorDone): ?>
                <div data-testid="rumor-done" style="text-align:center;padding:44px 12px;">
                    <div style="font-size:38px;color:var(--gold, #fbbf24);"><i class="fa-solid fa-champagne-glasses"></i></div>
                    <div style="font-family:var(--display, inherit);font-weight:800;font-size:19px;margin-top:12px;color:#f5f5f7;">
                        <?= t('ch_deck_cleared') ?>
                    </div>
                    <div style="font-size:13px;margin-top:6px;color:#a1a1aa;">
                        <?= t('ch_deck_cleared_sub') ?>
                    </div>
                    <a href="challenges-invite.php?game=rumor_or_not" class="btn btn-primary" style="margin-top:16px;display:inline-block;">
                        <?= t('ch_challenge_a_friend') ?>
                    </a>
                    <div style="margin-top:12px;">
                        <a href="?section=overview" style="color:#a1a1aa;font-size:13px;"><?= t('ch_back_to_overview') ?></a>
                    </div>
                </div>
            <?php else: ?>
                <?php $card = $revealedItem ?: $rumorCurrent; $answered = (bool)$revealedItem; ?>
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:13px;color:#a1a1aa;font-weight:600;"><?= t('ch_todays_deck') ?></span>
                    <span style="font-size:12px;color:#a1a1aa;font-family:monospace;"><?= $answeredToday ?> / <?= $deckSize ?></span>
                </div>
                <div data-testid="rumor-card" style="border-radius:16px;background:rgba(35,35,40,.7);border:1.5px solid <?= $answered ? ($card['correct'] ? 'var(--status-success, #10b981)' : 'var(--f1-red, #e10600)') : 'rgba(245,245,247,.15)' ?>;padding:20px 18px;margin-top:12px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <span class="hf-badge" style="background:rgba(225,6,0,.14);color:#ff8a80;border:1px solid rgba(225,6,0,.4);padding:4px 10px;border-radius:7px;font-size:11px;font-weight:700;">
                            <?= escape($card['context_' . $lang] ?: $card['context_da']) ?>
                        </span>
                        <?php if ($answered): ?>
                            <span data-testid="rumor-stamp" data-is-real="<?= $card['is_real'] ? '1' : '0' ?>" style="font-weight:800;font-size:12px;padding:4px 10px;border-radius:7px;background:<?= $card['is_real'] ? 'var(--status-success, #10b981)' : 'var(--f1-red, #e10600)' ?>;color:#fff;">
                                <?= $card['is_real'] ? t('ch_stamp_real') : t('ch_stamp_rumor') ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div style="font-weight:800;font-size:18px;line-height:1.32;margin-top:15px;color:#f5f5f7;">
                        <?= escape($card['text_' . $lang] ?: $card['text_da']) ?>
                    </div>
                    <?php if ($answered): ?>
                        <div style="margin-top:14px;padding-top:13px;border-top:1px solid rgba(255,255,255,.08);">
                            <div data-testid="rumor-result" data-correct="<?= $card['correct'] ? '1' : '0' ?>" style="display:flex;align-items:center;gap:8px;font-weight:800;font-size:14px;color:<?= $card['correct'] ? 'var(--status-success, #10b981)' : 'var(--f1-red, #e10600)' ?>;">
                                <i class="fa-solid <?= $card['correct'] ? 'fa-check' : 'fa-xmark' ?>"></i>
                                <?= $card['correct'] ? t('ch_reveal_correct') : t('ch_reveal_missed') ?>
                                <?php if ($card['correct']): ?> · <?= sprintf(t('ch_reveal_cp'), 10) ?><?php endif; ?>
                            </div>
                            <div style="font-size:12.5px;line-height:1.5;margin-top:7px;color:#a1a1aa;">
                                <?= escape($card['explain_' . $lang] ?: $card['explain_da']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!$answered): ?>
                    <form method="POST" style="margin-top:16px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="item_id" value="<?= escape($card['id']) ?>">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                            <button type="submit" name="guess_real" value="0" data-testid="rumor-guess-rumor" style="height:56px;border-radius:14px;border:1.5px solid var(--f1-red, #e10600);background:rgba(225,6,0,.12);color:#ff8a80;font-weight:800;font-size:15px;cursor:pointer;">
                                <?= t('ch_guess_rumor') ?>
                            </button>
                            <button type="submit" name="guess_real" value="1" data-testid="rumor-guess-real" style="height:56px;border-radius:14px;border:1.5px solid var(--status-success, #10b981);background:rgba(16,185,129,.12);color:#34d399;font-weight:800;font-size:15px;cursor:pointer;">
                                <?= t('ch_guess_real') ?>
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <a href="?section=rumors" data-testid="rumor-next" class="btn btn-primary" style="width:100%;margin-top:16px;display:block;text-align:center;">
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
                    <div style="font-family:var(--display, inherit);font-weight:800;font-size:19px;margin-top:12px;color:#f5f5f7;">
                        <?php if ($weekPerfect): ?>
                            <?= t('ch_perfect_week') ?>
                        <?php elseif ($weekFinished): ?>
                            <?= t('ch_quiz_complete') ?>
                        <?php else: ?>
                            <?= t('ch_all_caught_up') ?>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:13px;margin-top:6px;color:#a1a1aa;">
                        <?php if ($weekPerfect): ?>
                            <?= t('ch_perfect_week_sub') ?>
                        <?php elseif ($weekFinished): ?>
                            <?= sprintf(t('ch_quiz_complete_sub'), $weekCorrect, $weekTotal) ?>
                        <?php else: ?>
                            <?= t('ch_all_caught_up_sub') ?>
                        <?php endif; ?>
                    </div>
                    <a href="challenges-invite.php?game=trivia" class="btn btn-primary" style="margin-top:16px;display:inline-block;">
                        <?= t('ch_challenge_a_friend') ?>
                    </a>
                    <div style="margin-top:12px;">
                        <a href="?section=overview" style="color:#a1a1aa;font-size:13px;"><?= t('ch_back_to_overview') ?></a>
                    </div>
                </div>
            <?php else: ?>
                <?php
                    $q = $revealedQuestion ?: $triviaCurrent;
                    $answered = (bool)$revealedQuestion;
                    $options = json_decode($q['options_' . $lang] ?: $q['options_da'], true) ?: [];
                ?>
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-size:13px;color:#a1a1aa;font-weight:600;"><?= t('ch_weekly_quiz') ?></span>
                    <span style="font-size:12px;color:#a1a1aa;font-family:monospace;"><?= $weekAnswered ?> / <?= $weekTotal ?></span>
                </div>
                <div data-testid="trivia-card" style="border-radius:16px;background:rgba(35,35,40,.7);border:1px solid rgba(245,245,247,.15);padding:20px 18px;margin-top:12px;">
                    <span class="hf-badge" style="background:rgba(59,130,246,.14);color:#7fb2ff;border:1px solid rgba(59,130,246,.35);padding:4px 10px;border-radius:7px;font-size:11px;font-weight:700;">
                        <?= escape(strtoupper($q['topic'])) ?>
                    </span>
                    <div style="font-weight:800;font-size:18px;line-height:1.3;margin-top:14px;color:#f5f5f7;">
                        <?= escape($q['question_' . $lang] ?: $q['question_da']) ?>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:10px;margin-top:16px;">
                        <?php foreach ($options as $idx => $optionText): ?>
                            <?php
                                $isCorrectOpt  = $answered && $idx === (int)$q['correct_option'];
                                $isChosenWrong = $answered && !$isCorrectOpt && $idx === (int)$q['chosen_option'];
                                $bg = 'rgba(255,255,255,.03)'; $border = 'rgba(245,245,247,.15)'; $color = '#f5f5f7';
                                if ($isCorrectOpt) { $bg = 'rgba(16,185,129,.14)'; $border = 'var(--status-success, #10b981)'; $color = '#34d399'; }
                                elseif ($isChosenWrong) { $bg = 'rgba(225,6,0,.14)'; $border = 'var(--f1-red, #e10600)'; $color = '#ff8a80'; }
                                elseif ($answered) { $color = '#71717a'; }
                            ?>
                            <?php if ($answered): ?>
                                <div data-testid="trivia-option" data-idx="<?= $idx ?>" style="display:flex;align-items:center;gap:11px;text-align:left;padding:13px 14px;border-radius:12px;background:<?= $bg ?>;border:1.5px solid <?= $border ?>;color:<?= $color ?>;font-weight:600;font-size:14px;">
                                    <span style="width:22px;height:22px;flex-shrink:0;border-radius:6px;background:rgba(255,255,255,.06);color:#a1a1aa;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;"><?= chr(65 + $idx) ?></span>
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
                                        <span style="width:22px;height:22px;flex-shrink:0;border-radius:6px;background:rgba(255,255,255,.06);color:#a1a1aa;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;"><?= chr(65 + $idx) ?></span>
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
                            <div style="font-size:12.5px;line-height:1.5;margin-top:7px;color:#a1a1aa;">
                                <?= escape($q['explain_' . $lang] ?: $q['explain_da']) ?>
                            </div>
                        </div>
                        <a href="?section=trivia" data-testid="trivia-next" class="btn btn-primary" style="width:100%;margin-top:13px;display:block;text-align:center;">
                            <?= $triviaCurrent ? t('ch_next_question') : t('ch_finish_quiz') ?> <span aria-hidden="true">&rarr;</span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
