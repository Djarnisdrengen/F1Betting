<?php
// Shared admin nav renderer — one function for both nav levels (see
// epics/Admin area redesign/plan.md decision 1). Emits an `.admin-shell` container
// holding both a desktop `.admin-tabs` row and a mobile `<details class="admin-dropdown">`;
// CSS (container query on `.admin-shell`, name `admin-vp`) decides which is visible, so no
// JS is needed for the open/close mechanic itself.
//
// $items is a list of ['key' => ..., 'href' => ..., 'icon' => ..., 'label' => ..., 'count' => optional int].
// $groupKey identifies which nav level this is ('area' = Level-1 area switcher, spanning
// admin.php/admin-challenges.php/admin-dashboards.php as separate pages; anything else = a
// Level-2 per-page tab row) — used only to pick the data-testid so tests can keep addressing
// "the three area tabs" vs "this page's tabs" without depending on CSS class names, even
// though both levels share the same visual `.admin-tab` styling. The dropdown's <a> items
// get a `-mobile` suffixed testid, distinct from the always-in-DOM desktop tabs' testid —
// both variants render simultaneously (CSS, not JS, decides which is visible), so a shared
// testid would make e.g. `[data-testid="admin-tab"].active` match two elements and fail
// Playwright's strict-mode single-match requirement.
function renderAdminTabRow(string $groupKey, string $activeKey, array $items, string $ariaLabel = ''): void {
    $testId = $groupKey === 'area' ? 'admin-area-tab' : 'admin-tab';
    $testIdMobile = $testId . '-mobile';

    $activeItem = $items[0] ?? null;
    foreach ($items as $item) {
        if ($item['key'] === $activeKey) {
            $activeItem = $item;
            break;
        }
    }
    if ($activeItem === null) {
        return;
    }
    ?>
    <nav class="admin-shell" aria-label="<?= escape($ariaLabel) ?>">
        <details class="admin-dropdown">
            <summary>
                <span class="label"><i class="<?= escape($activeItem['icon']) ?>"></i><?= escape($activeItem['label']) ?></span>
                <i class="fas fa-chevron-down chev"></i>
            </summary>
            <div class="menu">
                <?php foreach ($items as $item): ?>
                    <a href="<?= escape($item['href']) ?>" class="<?= $item['key'] === $activeKey ? 'active' : '' ?>" data-testid="<?= $testIdMobile ?>">
                        <span class="l"><i class="<?= escape($item['icon']) ?>"></i><?= escape($item['label']) ?></span>
                        <?php if (!empty($item['count'])): ?><span class="tab-count"><?= (int) $item['count'] ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </details>
        <div class="admin-tabs">
            <?php foreach ($items as $item): ?>
                <a href="<?= escape($item['href']) ?>" class="admin-tab <?= $item['key'] === $activeKey ? 'active' : '' ?>" data-testid="<?= $testId ?>">
                    <i class="<?= escape($item['icon']) ?>"></i><?= escape($item['label']) ?>
                    <?php if (!empty($item['count'])): ?><span class="tab-count"><?= (int) $item['count'] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>
    <?php
}

// Level-1 admin area switcher — admin.php, admin-challenges.php and
// admin-dashboards.php are separate pages, not tabs of one page, so this sits a level
// above each page's own Level-2 tab row. Extracted from three near-identical inline
// copies (see epics/Archive/Admin settings and dashboards/plan.md decision 3).
function renderAdminAreaNav(string $activeArea, ?int $challengesPromoCount = null): void {
    if ($challengesPromoCount === null) {
        $challengesPromoCount = (int) getDB()->query("
            SELECT COUNT(*) FROM challenge_participants
            WHERE promotion_requested_at IS NOT NULL AND core_user_id IS NULL
        ")->fetchColumn();
    }

    renderAdminTabRow('area', $activeArea, [
        ['key' => 'dashboards', 'href' => 'admin-dashboards.php', 'icon' => 'fas fa-gauge-high', 'label' => t('admin_area_dashboards')],
        ['key' => 'challenges', 'href' => 'admin-challenges.php', 'icon' => 'fas fa-user-check',  'label' => t('admin_area_challenges'), 'count' => $challengesPromoCount],
        ['key' => 'core',       'href' => 'admin.php',            'icon' => 'fas fa-cog',          'label' => t('admin_area_core')],
    ], t('admin'));
}
