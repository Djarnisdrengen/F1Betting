<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

$leaderboard = getLeaderboard($db);

include __DIR__ . '/includes/header.php';
?>

<div class="hf-container">
    <header class="hf-pageh">
        <div class="crumb"><?= t('leaderboard') ?> &middot; <?= t('season') ?> <?= escape($settings['app_year']) ?></div>
        <h1><?= t('leaderboard') ?></h1>
    </header>

    <?php if (count($leaderboard) >= 3): ?>
    <!-- Top-3 podium cards (MD+) -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:16px;" class="hf-podium-strip">
        <?php
        $podiumBg = [
            'linear-gradient(135deg, rgba(251,191,36,0.18), var(--bg-card) 60%)',
            'linear-gradient(135deg, rgba(156,163,175,0.14), var(--bg-card) 60%)',
            'linear-gradient(135deg, rgba(205,124,47,0.14), var(--bg-card) 60%)',
        ];
        $podiumBorder = [
            'rgba(251,191,36,0.4)',
            'rgba(156,163,175,0.3)',
            'rgba(205,124,47,0.3)',
        ];
        foreach ([0, 1, 2] as $i):
            $entry = $leaderboard[$i];
        ?>
        <div class="hf-stat" style="align-items:center;text-align:center;background:<?= $podiumBg[$i] ?>;border-color:<?= $podiumBorder[$i] ?>;">
            <div class="hf-rank r<?= $i+1 ?>" style="width:40px;height:40px;margin-bottom:8px;font-size:16px;"><?= $i+1 ?></div>
            <div class="hf-stat-n" style="font-size:36px;"><?= $entry['points'] ?>p</div>
            <div style="font-family:var(--font-display);font-weight:700;font-size:14px;color:var(--text-primary);margin-top:6px;"><?= escape(displayUserName($entry)) ?></div>
            <?php if ($entry['stars'] > 0): ?>
            <div style="color:var(--gold);font-family:var(--font-display);font-size:12px;font-weight:700;margin-top:2px;">★<?= $entry['stars'] ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- XS/SM: current user row pinned above list -->
    <?php if ($currentUser):
        foreach ($leaderboard as $i => $entry):
            if ($entry['id'] === $currentUser['id']):
    ?>
    <div class="hf-row self hf-self-pin" style="margin:16px 0 8px;">
        <div class="hf-rank <?= $i < 3 ? 'r'.($i+1) : '' ?>"><?= $i+1 ?></div>
        <div class="hf-avatar"><?= escape(userInitial($entry)) ?></div>
        <div class="hf-who">
            <div class="hf-who-name"><?= escape(displayUserName($entry)) ?></div>
            <div class="hf-who-sub"><?= $entry['bets_count'] ?> <?= t('bets') ?></div>
        </div>
        <div class="hf-stars"><?= $entry['stars'] > 0 ? '★'.$entry['stars'] : '' ?></div>
        <div class="hf-pts"><?= $entry['points'] ?>p</div>
    </div>
    <?php       break;
            endif;
        endforeach;
    endif; ?>

    <!-- Full standings list -->
    <div style="padding-top:16px;padding-bottom:32px;">
        <?php if (empty($leaderboard)): ?>
            <p style="text-align:center;color:var(--text-muted);padding:3rem 0;"><?= t('no_bets') ?></p>
        <?php else: ?>
            <?php foreach ($leaderboard as $i => $entry):
                $isSelf = $currentUser && $entry['id'] === $currentUser['id'];
                $rankCls = $i < 3 ? 'hf-rank r'.($i+1) : 'hf-rank';
            ?>
            <div class="hf-row<?= $isSelf ? ' self' : '' ?>">
                <div class="<?= $rankCls ?>"><?= $i+1 ?></div>
                <div class="hf-avatar"><?= escape(userInitial($entry)) ?></div>
                <div class="hf-who">
                    <div class="hf-who-name"><?= escape(displayUserName($entry)) ?></div>
                    <div class="hf-who-sub"><?= $entry['bets_count'] ?> <?= t('bets') ?></div>
                </div>
                <div class="hf-stars"><?= $entry['stars'] > 0 ? '★'.$entry['stars'] : '' ?></div>
                <div class="hf-pts"><?= $entry['points'] ?>p</div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
