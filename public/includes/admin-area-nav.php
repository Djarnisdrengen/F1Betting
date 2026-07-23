<?php
// Shared Level-1 admin area switcher — admin.php, admin-challenges.php and
// admin-dashboards.php are separate pages, not tabs of one page, so this sits a level
// above each page's own Level-2 tab row. Extracted from three near-identical inline
// copies (see epics/Admin settings and dashboards/plan.md decision 3).
function renderAdminAreaNav(string $activeArea, ?int $challengesPromoCount = null): void {
    if ($challengesPromoCount === null) {
        $challengesPromoCount = (int) getDB()->query("
            SELECT COUNT(*) FROM challenge_participants
            WHERE promotion_requested_at IS NOT NULL AND core_user_id IS NULL
        ")->fetchColumn();
    }
    ?>
    <nav class="admin-area-nav" aria-label="<?= t('admin') ?>">
        <a href="admin.php" class="admin-area-tab <?= $activeArea === 'core' ? 'active' : '' ?>">
            <i class="fas fa-cog"></i>
            <span><?= t('admin_area_core') ?></span>
        </a>
        <a href="admin-challenges.php" class="admin-area-tab <?= $activeArea === 'challenges' ? 'active' : '' ?>">
            <i class="fas fa-user-check"></i>
            <span><?= t('admin_area_challenges') ?></span>
            <?php if ($challengesPromoCount > 0): ?>
                <span class="admin-area-badge"><?= $challengesPromoCount ?></span>
            <?php endif; ?>
        </a>
        <a href="admin-dashboards.php" class="admin-area-tab <?= $activeArea === 'dashboards' ? 'active' : '' ?>">
            <i class="fas fa-gauge-high"></i>
            <span><?= t('admin_area_dashboards') ?></span>
        </a>
    </nav>
    <?php
}
