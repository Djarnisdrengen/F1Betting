<?php
$arenaTint = $currentPage === 'challenges' ? 'background: rgba(13,13,16,.95);' : '';
?>
<nav class="hf-bottom" style="<?= $arenaTint ?>">
    <a href="/" class="hf-bb-item <?= $currentPage === 'index' ? 'active' : '' ?>">
        <div class="hf-bb-icon"><i class="fas fa-home"></i></div>
        <span><?= t('home') ?></span>
    </a>
    <a href="races.php" class="hf-bb-item <?= $currentPage === 'races' ? 'active' : '' ?>">
        <div class="hf-bb-icon"><i class="fas fa-flag"></i></div>
        <span><?= t('races') ?></span>
    </a>
    <a href="leaderboard.php" class="hf-bb-item <?= $currentPage === 'leaderboard' ? 'active' : '' ?>">
        <div class="hf-bb-icon"><i class="fas fa-trophy"></i></div>
        <span><?= t('ch_nav_board') ?></span>
    </a>
    <a href="challenges.php" class="hf-bb-item <?= $currentPage === 'challenges' ? 'active' : '' ?>">
        <div class="hf-bb-icon" style="background:var(--f1-accent-challenges);color:#fff;border-radius:9px;width:30px;height:30px;box-shadow:0 3px 10px rgba(36,114,232,.5);">
            <i class="fas fa-gamepad"></i>
        </div>
        <span><?= t('ch_nav_short') ?></span>
    </a>
</nav>
