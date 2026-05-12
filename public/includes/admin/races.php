<div class="card mb-2" id="add-race-form">
    <div class="card-header collapsible-header toggleForm" data-link="race-form-body" id="race-form-header">
        <h3><i class="fas fa-plus-circle text-accent"></i> <?= t('add_race') ?></h3>
        <i class="fas fa-chevron-down toggle-icon"></i>
    </div>
    <div id="race-form-body" class="collapsible-form">
        <div class="card-body">
            <form method="POST">
            <?= csrfField() ?>
                <div class="grid grid-2 mb-2">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label"><?= t('name') ?></label>
                        <input type="text" name="race_name" class="form-input" required placeholder="Monaco Grand Prix">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label"><?= t('location') ?></label>
                        <input type="text" name="race_location" class="form-input" required placeholder="Monte Carlo">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label"><?= t('race_date') ?></label>
                        <input type="date" name="race_date" class="form-input" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label"><?= t('race_time') ?> (CET)</label>
                        <input type="time" name="race_time" class="form-input" required>
                    </div>
                </div>
                <button type="submit" name="add_race" class="btn btn-primary"><i class="fas fa-plus"></i> <?= t('add') ?></button>
            </form>
        </div>
    </div>
</div>

<?php foreach ($races as $race): ?>
    <div class="card mb-1 <?= isset($_GET['edit']) && $_GET['edit'] === $race['id'] ? 'edit-form-active' : '' ?>" id="race-<?= escape($race['id']) ?>">
        <div class="card-body">
            <div class="flex items-center justify-between mb-1">
                <div>
                    <strong><?= escape($race['name']) ?></strong>
                    <br><small class="text-muted"><?= escape($race['location']) ?> - <?= escape($race['race_date']) ?> <?= escape(substr($race['race_time'], 0, 5)) ?> CET</small>
                </div>
                <div class="flex gap-1">
                    <?php if (!$race['result_p1']): ?>
                        <a href="?tab=races&edit=<?= escape($race['id']) ?>#race-<?= escape($race['id']) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
                    <?php endif; ?>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="race_id" value="<?= escape($race['id']) ?>">
                        <button type="submit" name="delete_race" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($race['name']) ?>">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php if ($race['quali_p1']): ?>
                <small class="text-muted"><?= t('qualifying') ?>: <?= escape($driversById[$race['quali_p1']]['name'] ?? '?') ?>, <?= escape($driversById[$race['quali_p2']]['name'] ?? '?') ?>, <?= escape($driversById[$race['quali_p3']]['name'] ?? '?') ?></small>
            <?php endif; ?>
            <?php if ($race['result_p1']): ?>
                <br><small class="text-accent"><?= t('results') ?>: <?= escape($driversById[$race['result_p1']]['name'] ?? '?') ?>, <?= escape($driversById[$race['result_p2']]['name'] ?? '?') ?>, <?= escape($driversById[$race['result_p3']]['name'] ?? '?') ?></small>
            <?php endif; ?>
            <?php if (($lastCompletedRaceId ?? null) === $race['id']): ?>
                <form method="POST" style="display:inline; margin-top: 0.5rem;">
                    <?= csrfField() ?>
                    <input type="hidden" name="race_id" value="<?= escape($race['id']) ?>">
                    <button type="submit" name="reset_race_result" class="btn btn-danger btn-sm btn-reset-result" data-name="<?= escape($race['name']) ?>" style="margin-top: 0.5rem;">
                        <i class="fas fa-undo"></i> <?= t('reset_result') ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (!$race['result_p1'] && isset($_GET['edit']) && $_GET['edit'] === $race['id']): ?>
            <div class="card-body" style="border-top: 1px solid var(--border-color); background: var(--bg-hover);">
                <form method="POST">
                <?= csrfField() ?>
                    <input type="hidden" name="race_id" value="<?= escape($race['id']) ?>">
                    <div class="grid grid-2 mb-2">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label"><?= t('name') ?></label>
                            <input type="text" name="race_name" class="form-input" value="<?= escape($race['name']) ?>" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label"><?= t('location') ?></label>
                            <input type="text" name="race_location" class="form-input" value="<?= escape($race['location']) ?>" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label"><?= t('race_date') ?></label>
                            <input type="date" name="race_date" class="form-input" value="<?= escape($race['race_date']) ?>" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label"><?= t('race_time') ?> (CET)</label>
                            <input type="time" name="race_time" class="form-input" value="<?= escape($race['race_time']) ?>" required>
                        </div>
                    </div>
                    <label class="form-label"><?= t('qualifying') ?></label>
                    <div class="grid grid-3 mb-2">
                        <?php foreach (['quali_p1', 'quali_p2', 'quali_p3'] as $i => $key): ?>
                            <select name="<?= $key ?>" class="form-select">
                                <option value="">P<?= $i + 1 ?></option>
                                <?php foreach ($drivers as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $race[$key] === $d['id'] ? 'selected' : '' ?>><?= driverLabel($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endforeach; ?>
                    </div>
                    <label class="form-label"><?= t('results') ?></label>
                    <div class="grid grid-3 mb-2">
                        <?php foreach (['result_p1', 'result_p2', 'result_p3'] as $i => $key): ?>
                            <select name="<?= $key ?>" class="form-select">
                                <option value="">P<?= $i + 1 ?></option>
                                <?php foreach ($drivers as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $race[$key] === $d['id'] ? 'selected' : '' ?>><?= driverLabel($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex gap-1">
                        <button type="submit" name="update_race" class="btn btn-primary"><?= t('save') ?></button>
                        <a href="?tab=races" class="btn btn-secondary"><?= t('cancel') ?></a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
