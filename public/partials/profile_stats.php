<?php // public/partials/profile_stats.php ?>
<section class="hf-stats" aria-label="<?= t('your_stats') ?>" data-testid="profile-stats">

    <article class="hf-stats-hero" data-testid="stats-hero">
        <div class="hf-stats-hero-num" data-testid="stats-points">
            <?= (int) $user['points'] ?><span class="hf-stats-hero-unit">pts</span>
        </div>
        <div class="hf-stats-hero-stars <?= $user['stars'] > 0 ? 'has' : 'empty' ?>" data-testid="stats-stars">
            <?php if ($user['stars'] > 0): ?>
                <?= str_repeat('★', $user['stars']) ?>
            <?php else: ?>
                ★ 0
            <?php endif; ?>
        </div>
        <div class="hf-stats-hero-eyebrow"><?= t('season') ?></div>
    </article>

    <?php $statsRankKnown = isset($statsRank) && $statsRank !== null; ?>
    <div class="hf-stats-metrics" data-testid="stats-metrics">
        <article class="hf-stats-metric" data-testid="stats-metric-position">
            <div class="k"><?= t('your_position') ?></div>
            <div class="v">
                <?php if ($statsRankKnown): ?>
                    <?= (int) $statsRank ?><span class="of">/ <?= (int) ($statsTotal ?? 0) ?></span>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
        </article>
        <article class="hf-stats-metric" data-testid="stats-metric-bets">
            <div class="k"><?= t('bets') ?></div>
            <div class="v"><?= (int) ($statsBets ?? 0) ?></div>
        </article>
        <article class="hf-stats-metric" data-testid="stats-metric-rounds">
            <div class="k"><?= t('rounds_played') ?></div>
            <div class="v"><?= (int) ($statsRounds ?? 0) ?></div>
        </article>
    </div>

    <?php if ($statsRankKnown && $statsDelta !== null):
        if ($statsDelta > 0)      $deltaText = '↑ ' . $statsDelta . ' ' . t('rank_delta_places');
        elseif ($statsDelta < 0)  $deltaText = '↓ ' . abs($statsDelta) . ' ' . t('rank_delta_places');
        else                      $deltaText = t('rank_no_change');
    ?>
        <div class="hf-stats-delta" data-testid="stats-delta"><?= escape($deltaText) ?></div>
    <?php endif; ?>

    <div class="hf-stats-chips">
        <article class="hf-stats-chip role-<?= escape($user['role']) ?>" data-testid="stats-chip-role">
            <span class="dot"></span>
            <div class="meta">
                <div class="k"><?= t('role') ?></div>
                <div class="v"><?= escape(ucfirst($user['role'])) ?></div>
            </div>
        </article>
        <article class="hf-stats-chip <?= $user['in_competition'] ? 'in' : 'out' ?>" data-testid="stats-chip-competing">
            <span class="dot"></span>
            <div class="meta">
                <div class="k"><?= t('competing') ?></div>
                <div class="v"><?= $user['in_competition'] ? t('yes') : t('no') ?></div>
            </div>
        </article>
    </div>

</section>
