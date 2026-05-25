<div class="card">
    <div class="card-header"><h3><?= t('settings') ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="grid grid-2 mb-2">
                <div class="form-group">
                    <label class="form-label"><?= t('app_title_label') ?></label>
                    <input type="text" name="app_title" class="form-input" value="<?= escape($settings['app_title']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('year') ?></label>
                    <input type="text" name="app_year" class="form-input" value="<?= escape($settings['app_year']) ?>">
                </div>
            </div>
            <div class="grid grid-2 mb-2">
                <div class="form-group">
                    <label class="form-label"><?= t('hero_title_en_label') ?></label>
                    <input type="text" name="hero_title_en" class="form-input" value="<?= escape($settings['hero_title_en']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('hero_title_da_label') ?></label>
                    <input type="text" name="hero_title_da" class="form-input" value="<?= escape($settings['hero_title_da']) ?>">
                </div>
            </div>
            <div class="grid grid-2 mb-2">
                <div class="form-group">
                    <label class="form-label"><?= t('hero_text_en_label') ?></label>
                    <textarea name="hero_text_en" class="form-input" rows="3"><?= escape($settings['hero_text_en']) ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('hero_text_da_label') ?></label>
                    <textarea name="hero_text_da" class="form-input" rows="3"><?= escape($settings['hero_text_da']) ?></textarea>
                </div>
            </div>

            <h4 class="mb-1 mt-2"><i class="fas fa-clock text-accent"></i> <?= t('betting_window_section') ?></h4>
            <p class="text-muted mb-2" style="font-size: 0.875rem;">
                <?= t('betting_window_config') ?>
            </p>
            <div class="grid grid-2 mb-2">
                <div class="form-group">
                    <label class="form-label"><?= t('hours_before_race') ?></label>
                    <input type="number" name="betting_window_hours" class="form-input" value="<?= intval($settings['betting_window_hours'] ?? 48) ?>" min="1" max="168">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <p class="text-muted" style="font-size: 0.875rem; margin-bottom: 0.5rem;">
                        <?= sprintf(t('betting_window_summary'), intval($settings['betting_window_hours'] ?? 48)) ?>
                    </p>
                </div>
            </div>

            <h4 class="mb-1 mt-2"><i class="fas fa-star text-accent"></i> <?= t('points_system_section') ?></h4>
            <p class="text-muted mb-2" style="font-size: 0.875rem;">
                <?= t('points_config') ?>
            </p>
            <div class="grid grid-4 mb-2">
                <div class="form-group">
                    <label class="form-label flex items-center gap-1"><span class="position-badge position-1">P1</span> <?= t('points_label_heading') ?></label>
                    <input type="number" name="points_p1" class="form-input" value="<?= intval($settings['points_p1'] ?? 25) ?>" min="0" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label flex items-center gap-1"><span class="position-badge position-2">P2</span> <?= t('points_label_heading') ?></label>
                    <input type="number" name="points_p2" class="form-input" value="<?= intval($settings['points_p2'] ?? 18) ?>" min="0" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label flex items-center gap-1"><span class="position-badge position-3">P3</span> <?= t('points_label_heading') ?></label>
                    <input type="number" name="points_p3" class="form-input" value="<?= intval($settings['points_p3'] ?? 15) ?>" min="0" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('wrong_position') ?></label>
                    <input type="number" name="points_wrong_pos" class="form-input" value="<?= intval($settings['points_wrong_pos'] ?? 5) ?>" min="0" max="100">
                </div>
            </div>
            <p class="text-muted mb-2" style="font-size: 0.75rem;">
                <i class="fas fa-info-circle"></i>
                <?= t('wrong_pos_desc') ?>
            </p>

            <h4 class="mb-1 mt-3"><i class="fas fa-money-bill-wave text-accent"></i> <?= t('bet_size_section') ?></h4>
            <p class="text-muted mb-2" style="font-size: 0.875rem;">
                <?= t('bet_size_desc') ?>
            </p>
            <div class="form-group mb-2" style="max-width: 200px;">
                <label class="form-label"><?= t('bet_size_label') ?></label>
                <input type="number" name="bet_size" class="form-input" value="<?= intval($settings['bet_size'] ?? 10) ?>" min="1" max="1000">
            </div>

            <button type="submit" name="update_settings" class="btn btn-primary">
                <i class="fas fa-save"></i> <?= t('save') ?>
            </button>
        </form>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h3><i class="fas fa-tools text-accent"></i> <?= t('backfill_snapshots_section') ?></h3></div>
    <div class="card-body">
        <p class="text-muted mb-2" style="font-size:0.875rem;"><?= t('backfill_snapshots_desc') ?></p>
        <form method="POST">
            <?= csrfField() ?>
            <button type="submit" name="backfill_snapshots" class="btn btn-secondary">
                <i class="fas fa-history"></i> <?= t('backfill_snapshots_btn') ?>
            </button>
        </form>
    </div>
</div>
