<?php
require_once __DIR__ . '/../config.php';

$db = getDB();

$leaderboard = $db->query("
    SELECT u.id, u.email, u.display_name, u.points, u.stars, COUNT(b.id) as bets_count 
    FROM users u 
    LEFT JOIN bets b ON u.id = b.user_id 
    GROUP BY u.id 
    ORDER BY u.points DESC, u.stars DESC
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<h1 class="mb-3"><i class="fas fa-trophy text-accent"></i> <?= t('leaderboard') ?></h1>

<!-- Top 3 Podium (hidden on mobile via inline media query) -->
<?php if (count($leaderboard) >= 3): ?>
<style>
@media (max-width: 768px) {
    .podium-section { display: none !important; }
}
</style>
<div class="podium-section mb-3" style="display: grid; grid-template-columns: repeat(3, 1fr); align-items: end;">
    <?php 
    $podiumOrder = [1, 0, 2]; // P2, P1, P3
    $heights = ['8rem', '10rem', '6rem'];
    $positions = ['P2', 'P1', 'P3'];
    foreach ($podiumOrder as $idx => $pos): 
        $entry = $leaderboard[$pos];
    ?>
        <div class="text-center">
            <p style="font-weight: bold;"><?= escape($entry['display_name'] ?: $entry['email']) ?></p>
            <p class="text-accent" style="font-size: 1.5rem; font-weight: bold;"><?= $entry['points'] ?> pts</p>
            <?php if ($entry['stars'] > 0): ?>
                <p class="star"><?= str_repeat('★', $entry['stars']) ?></p>
            <?php endif; ?>
            <div class="position-<?= $pos + 1 ?>" style="height: <?= $heights[$idx] ?>; border-radius: 8px 8px 0 0; display: flex; align-items: flex-start; justify-content: center; padding-top: 1rem; margin-top: 0.5rem;">
                <span style="font-size: 1.5rem; font-weight: bold;"><?= $positions[$idx] ?></span>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Full Leaderboard Table -->
<div class="card">
    <table class="leaderboard-table">
        <thead>
            <tr>
                <th><?= t('rank') ?></th>
                <th><?= t('user') ?></th>
                <th class="text-center">Bets</th>
                <th class="text-center"><?= t('stars') ?></th>
                <th class="text-right"><?= t('points') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($leaderboard as $i => $entry): ?>
                <tr class="<?= $i < 3 ? 'top-3' : '' ?>">
                    <td>
                        <span class="position-badge <?= $i < 3 ? 'position-' . ($i + 1) : '' ?>" <?= $i >= 3 ? 'style="background: var(--bg-secondary);"' : '' ?>>
                            <?= $i + 1 ?>
                        </span>
                    </td>
                    <td>
                        <div class="flex items-center gap-2">
                            <div class="user-avatar" style="<?= $i >= 3 ? 'background: var(--bg-secondary); color: var(--text-primary);' : '' ?>">
                                <?= strtoupper(substr($entry['display_name'] ?: $entry['email'], 0, 1)) ?>
                            </div>
                            <?= escape($entry['display_name'] ?: $entry['email']) ?>
                        </div>
                    </td>
                    <td class="text-center text-muted"><?= $entry['bets_count'] ?></td>
                    <td class="text-center">
                        <?php if ($entry['stars'] > 0): ?>
                            <span class="star">★<?= $entry['stars'] ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <span class="text-accent" style="font-size: 1.125rem; font-weight: bold;"><?= $entry['points'] ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (empty($leaderboard)): ?>
        <p class="text-center text-muted" style="padding: 3rem;"><?= t('no_bets') ?></p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
