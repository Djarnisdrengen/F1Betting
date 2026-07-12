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

if (!$participant) {
    $isPublic = true;
} else {
    $isPublic = false;
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
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
