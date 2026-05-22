<?php
$avatarInitial = $currentUser
    ? mb_strtoupper(mb_substr($currentUser['display_name'], 0, 1))
    : '';
$firstName = $currentUser
    ? mb_strtoupper(explode(' ', trim($currentUser['display_name']))[0])
    : '';
?>
<nav class="hf-bottom">
    <?php if ($currentUser): ?>
    <a href="profile.php" class="hf-bb-item <?= $currentPage === 'profile' ? 'active' : '' ?>">
        <div class="hf-bb-avatar"><?= escape($avatarInitial) ?></div>
        <span><?= escape($firstName) ?></span>
    </a>
    <?php else: ?>
    <a href="login.php" class="hf-bb-item">
        <div class="hf-bb-icon" style="color: var(--f1-red);"><i class="fas fa-sign-in-alt"></i></div>
        <span><?= t('login') ?></span>
    </a>
    <?php endif; ?>
    <a href="?toggle_theme=1" class="hf-bb-item" title="Theme">
        <div class="hf-bb-icon">
            <i class="fas <?= $theme === 'dark' ? 'fa-sun' : 'fa-moon' ?>"></i>
        </div>
        <span><?= strtoupper(t('theme')) ?></span>
    </a>
    <a href="?toggle_lang=1" class="hf-bb-item" title="Language">
        <div class="hf-bb-icon"><i class="fas fa-globe"></i></div>
        <span><?= strtoupper($lang) ?></span>
    </a>
    <a href="?toggle_font=1" class="hf-bb-item">
        <div class="hf-bb-icon"><i class="fas fa-font"></i></div>
        <span><?= $fontStack === 'editorial' ? 'EDIT' : 'SYS' ?></span>
    </a>
</nav>
