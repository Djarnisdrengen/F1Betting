<div class="card">
    <div class="card-header"><h3><?= t('settings') ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="grid grid-2 mb-2">
                <div class="form-group">
                    <label class="form-label">App Title</label>
                    <input type="text" name="app_title" class="form-input" value="<?= escape($settings['app_title']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $lang === 'da' ? 'År' : 'Year' ?></label>
                    <input type="text" name="app_year" class="form-input" value="<?= escape($settings['app_year']) ?>">
                </div>
            </div>
            <div class="grid grid-2 mb-2">
                <div class="form-group">
                    <label class="form-label">Hero Title (English)</label>
                    <input type="text" name="hero_title_en" class="form-input" value="<?= escape($settings['hero_title_en']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Hero Title (Dansk)</label>
                    <input type="text" name="hero_title_da" class="form-input" value="<?= escape($settings['hero_title_da']) ?>">
                </div>
            </div>
            <div class="grid grid-2 mb-2">
                <div class="form-group">
                    <label class="form-label">Hero Text (English)</label>
                    <textarea name="hero_text_en" class="form-input" rows="3"><?= escape($settings['hero_text_en']) ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Hero Text (Dansk)</label>
                    <textarea name="hero_text_da" class="form-input" rows="3"><?= escape($settings['hero_text_da']) ?></textarea>
                </div>
            </div>

            <h4 class="mb-1 mt-2"><i class="fas fa-clock text-accent"></i> <?= $lang === 'da' ? 'Betting Vindue' : 'Betting Window' ?></h4>
            <p class="text-muted mb-2" style="font-size: 0.875rem;">
                <?= $lang === 'da' ? 'Konfigurer hvornår betting åbner før løbsstart.' : 'Configure when betting opens before race start.' ?>
            </p>
            <div class="grid grid-2 mb-2">
                <div class="form-group">
                    <label class="form-label"><?= $lang === 'da' ? 'Timer før løb' : 'Hours before race' ?></label>
                    <input type="number" name="betting_window_hours" class="form-input" value="<?= intval($settings['betting_window_hours'] ?? 48) ?>" min="1" max="168">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <p class="text-muted" style="font-size: 0.875rem; margin-bottom: 0.5rem;">
                        <?= $lang === 'da'
                            ? 'Betting åbner ' . intval($settings['betting_window_hours'] ?? 48) . ' timer før løbsstart og lukker ved løbsstart.'
                            : 'Betting opens ' . intval($settings['betting_window_hours'] ?? 48) . ' hours before race start and closes at race start.' ?>
                    </p>
                </div>
            </div>

            <h4 class="mb-1 mt-2"><i class="fas fa-star text-accent"></i> <?= $lang === 'da' ? 'Point System' : 'Points System' ?></h4>
            <p class="text-muted mb-2" style="font-size: 0.875rem;">
                <?= $lang === 'da' ? 'Konfigurer hvor mange point der gives for korrekte forudsigelser.' : 'Configure how many points are awarded for correct predictions.' ?>
            </p>
            <div class="grid grid-4 mb-2">
                <div class="form-group">
                    <label class="form-label flex items-center gap-1"><span class="position-badge position-1">P1</span> <?= $lang === 'da' ? 'Point' : 'Points' ?></label>
                    <input type="number" name="points_p1" class="form-input" value="<?= intval($settings['points_p1'] ?? 25) ?>" min="0" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label flex items-center gap-1"><span class="position-badge position-2">P2</span> <?= $lang === 'da' ? 'Point' : 'Points' ?></label>
                    <input type="number" name="points_p2" class="form-input" value="<?= intval($settings['points_p2'] ?? 18) ?>" min="0" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label flex items-center gap-1"><span class="position-badge position-3">P3</span> <?= $lang === 'da' ? 'Point' : 'Points' ?></label>
                    <input type="number" name="points_p3" class="form-input" value="<?= intval($settings['points_p3'] ?? 15) ?>" min="0" max="100">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= $lang === 'da' ? 'Forkert position' : 'Wrong position' ?></label>
                    <input type="number" name="points_wrong_pos" class="form-input" value="<?= intval($settings['points_wrong_pos'] ?? 5) ?>" min="0" max="100">
                </div>
            </div>
            <p class="text-muted mb-2" style="font-size: 0.75rem;">
                <i class="fas fa-info-circle"></i>
                <?= $lang === 'da'
                    ? '"Forkert position" point gives når en kører er i top 3, men på forkert position.'
                    : '"Wrong position" points are awarded when a driver is in top 3 but in wrong position.' ?>
            </p>

            <h4 class="mb-1 mt-3"><i class="fas fa-money-bill-wave text-accent"></i> <?= $lang === 'da' ? 'Betting Størrelse' : 'Bet Size' ?></h4>
            <p class="text-muted mb-2" style="font-size: 0.875rem;">
                <?= $lang === 'da' ? 'Standardstørrelse for hver indsats.' : 'Default size for each bet.' ?>
            </p>
            <div class="form-group mb-2" style="max-width: 200px;">
                <label class="form-label"><?= $lang === 'da' ? 'Indsatsstørrelse' : 'Bet Size' ?></label>
                <input type="number" name="bet_size" class="form-input" value="<?= intval($settings['bet_size'] ?? 10) ?>" min="1" max="1000">
            </div>

            <button type="submit" name="update_settings" class="btn btn-primary">
                <i class="fas fa-save"></i> <?= t('save') ?>
            </button>
        </form>
    </div>
</div>
