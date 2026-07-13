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
                    <div style="display:flex;gap:8px;">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <div style="width:40px;height:40px;background:rgba(35,35,40,.7);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#f5f5f7;font-weight:600;font-size:12px;">
                                <?= ($i + 1) ?>
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
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
