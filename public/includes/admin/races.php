<!-- Add Race (collapsible) -->
<div class="card mb-3" id="add-race-form">
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

<!-- Mobile segment control -->
<div class="hf-races-seg-wrap">
    <div class="hf-seg">
        <button class="active" id="seg-upcoming"><?= t('upcoming_races') ?></button>
        <button id="seg-completed"><?= t('results') ?></button>
    </div>
</div>

<!-- Two-column grid (stacked mobile, side-by-side LG+) -->
<div class="hf-races-grid">

    <!-- Upcoming column -->
    <div class="hf-races-col" id="col-upcoming">
        <div class="hf-section-h" style="margin-bottom: 12px;">
            <h2><?= t('upcoming_races') ?></h2>
        </div>

        <?php if (empty($upcomingRaces)): ?>
            <p class="text-muted" style="padding: 12px 0;"><?= t('no_upcoming_races') ?></p>
        <?php else: ?>
            <?php foreach ($upcomingRaces as $race):
                $isEditing = isset($_GET['edit']) && $_GET['edit'] === $race['id'];
            ?>
            <div class="hf-racefull <?= $isEditing ? 'edit-form-active' : '' ?>" id="race-<?= escape($race['id']) ?>">
                <div class="hf-racefull-hd">
                    <div class="hf-racefull-info">
                        <div class="hf-racename"><?= escape($race['name']) ?></div>
                        <div class="hf-racemeta">
                            <?= escape($race['location']) ?> · <?= formatRaceDateTime($race['race_date'], $race['race_time']) ?>
                        </div>
                    </div>
                    <div class="flex gap-1" style="flex-shrink:0;">
                        <a href="?tab=races&edit=<?= escape($race['id']) ?>#race-<?= escape($race['id']) ?>"
                           class="btn btn-secondary btn-sm" title="<?= t('edit') ?>">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="race_id" value="<?= escape($race['id']) ?>">
                            <button type="submit" name="delete_race" class="btn btn-danger btn-sm btn-delete"
                                    data-name="<?= escape($race['name']) ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>

                <?php
                $hasQuali = (bool) $race['quali_p1'];
                if ($hasQuali || $isEditing):
                ?>
                <div class="hf-racefull-body">
                    <?php if ($hasQuali): ?>
                        <?php
                        $_qd_data  = $race;
                        $_qd_keys  = ['quali_p1', 'quali_p2', 'quali_p3'];
                        $_qd_label = t('qualifying');
                        $_qd_style = 'background: var(--bg-secondary); padding: 0.6rem 0.75rem; border-radius: 8px;';
                        include __DIR__ . '/../qualifying-display.php';
                        ?>
                    <?php endif; ?>

                    <?php if ($isEditing): ?>
                    <form method="POST" style="margin-top: <?= $hasQuali ? '8px' : '0' ?>;">
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
                                        <option value="<?= $d['id'] ?>" <?= ($race[$key] ?? null) === $d['id'] ? 'selected' : '' ?>><?= driverLabel($d) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex gap-1">
                            <button type="submit" name="update_race" class="btn btn-primary"><?= t('save') ?></button>
                            <a href="?tab=races" class="btn btn-secondary"><?= t('cancel') ?></a>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Results column (hidden on mobile by default) -->
    <div class="hf-races-col hidden" id="col-completed">
        <div class="hf-section-h" style="margin-bottom: 12px;">
            <h2><?= t('results') ?></h2>
        </div>

        <?php if (empty($completedRaces)): ?>
            <p class="text-muted" style="padding: 12px 0;"><?= t('no_bets') ?></p>
        <?php else: ?>
            <?php foreach ($completedRaces as $race): ?>
            <div class="hf-racefull" id="race-completed-<?= escape($race['id']) ?>">
                <div class="hf-racefull-hd">
                    <div class="hf-racefull-info">
                        <div class="hf-racename"><?= escape($race['name']) ?></div>
                        <div class="hf-racemeta">
                            <?= escape($race['location']) ?> · <?= formatRaceDateTime($race['race_date'], $race['race_time']) ?>
                        </div>
                    </div>
                    <div class="flex gap-1" style="flex-shrink:0;">
                        <?php if (($lastCompletedRaceId ?? null) === $race['id']): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="race_id" value="<?= escape($race['id']) ?>">
                                <button type="submit" name="reset_race_result"
                                        class="btn btn-secondary btn-sm btn-reset-result"
                                        data-name="<?= escape($race['name']) ?>"
                                        title="<?= t('reset_result') ?>">
                                    <i class="fas fa-undo"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="race_id" value="<?= escape($race['id']) ?>">
                            <button type="submit" name="delete_race" class="btn btn-danger btn-sm btn-delete"
                                    data-name="<?= escape($race['name']) ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <div class="hf-racefull-body">
                    <?php
                    $_qd_data  = $race;
                    $_qd_keys  = ['quali_p1', 'quali_p2', 'quali_p3'];
                    $_qd_label = t('qualifying');
                    $_qd_style = 'background: var(--bg-secondary); padding: 0.6rem 0.75rem; border-radius: 8px;';
                    include __DIR__ . '/../qualifying-display.php';
                    ?>
                    <?php if ($race['result_p1']): ?><div data-testid="admin-race-result"><?php endif; ?>
                    <?php
                    $_qd_data  = $race;
                    $_qd_keys  = ['result_p1', 'result_p2', 'result_p3'];
                    $_qd_label = t('result');
                    $_qd_style = 'background: var(--bg-secondary); padding: 0.6rem 0.75rem; border-radius: 8px;';
                    include __DIR__ . '/../qualifying-display.php';
                    ?>
                    <?php if ($race['result_p1']): ?></div><?php endif; ?>
                    ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script nonce="<?= $nonce ?>">
(function () {
    var segBtns = { upcoming: document.getElementById('seg-upcoming'), completed: document.getElementById('seg-completed') };
    var cols    = { upcoming: document.getElementById('col-upcoming'),  completed: document.getElementById('col-completed') };
    function activate(key) {
        Object.keys(segBtns).forEach(function (k) {
            segBtns[k].classList.toggle('active', k === key);
            cols[k].classList.toggle('hidden', k !== key);
        });
    }
    segBtns.upcoming.addEventListener('click',  function () { activate('upcoming'); });
    segBtns.completed.addEventListener('click', function () { activate('completed'); });
})();
</script>
