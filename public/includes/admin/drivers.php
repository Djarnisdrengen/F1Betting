<div class="card mb-2" id="add-driver-form">
    <div class="card-header collapsible-header toggleForm" data-link="driver-form-body" id="driver-form-header">
        <h3><i class="fas fa-plus-circle text-accent"></i> <?= $lang === 'da' ? 'Tilføj Kører' : 'Add Driver' ?></h3>
        <i class="fas fa-chevron-down toggle-icon"></i>
    </div>
    <div id="driver-form-body" class="collapsible-form">
        <div class="card-body">
            <form method="POST" class="grid grid-3" style="align-items: end;">
                <?= csrfField() ?>
                <div class="form-group" style="margin:0;">
                    <label class="form-label"><?= t('name') ?></label>
                    <input type="text" name="driver_name" class="form-input" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label"><?= t('team') ?></label>
                    <input type="text" name="driver_team" class="form-input" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label"><?= t('number') ?></label>
                    <input type="number" name="driver_number" class="form-input" required>
                </div>
                <button type="submit" name="add_driver" class="btn btn-primary">
                    <i class="fas fa-plus"></i> <?= t('add') ?>
                </button>
            </form>
        </div>
    </div>
</div>

<?php foreach ($drivers as $driver): ?>
    <div class="card mb-1 <?= isset($_GET['edit']) && $_GET['edit'] === $driver['id'] ? 'edit-form-active' : '' ?>" id="driver-<?= escape($driver['id']) ?>">
        <div class="card-body flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="text-accent" style="font-weight: bold; font-size: 1.25rem;">#<?= intval($driver['number']) ?></span>
                <div>
                    <strong><?= escape($driver['name']) ?></strong>
                    <br><small class="text-muted"><?= escape($driver['team']) ?></small>
                </div>
            </div>
            <div class="flex gap-1">
                <a href="?tab=drivers&edit=<?= escape($driver['id']) ?>#driver-<?= escape($driver['id']) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
                <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="driver_id" value="<?= escape($driver['id']) ?>">
                    <button type="submit" name="delete_driver" class="btn btn-danger btn-sm btn-delete" data-name="<?= escape($driver['name']) ?>">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </div>
        </div>
        <?php if (isset($_GET['edit']) && $_GET['edit'] === $driver['id']): ?>
            <div class="card-body" style="border-top: 1px solid var(--border-color); background: var(--bg-hover);">
                <form method="POST" class="grid grid-3" style="align-items: end;">
                    <?= csrfField() ?>
                    <input type="hidden" name="driver_id" value="<?= escape($driver['id']) ?>">
                    <div class="form-group" style="margin:0;">
                        <input type="text" name="driver_name" class="form-input" value="<?= escape($driver['name']) ?>" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <input type="text" name="driver_team" class="form-input" value="<?= escape($driver['team']) ?>" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <input type="number" name="driver_number" class="form-input" value="<?= intval($driver['number']) ?>" required>
                    </div>
                    <div class="flex gap-1">
                        <button type="submit" name="update_driver" class="btn btn-primary btn-sm"><?= t('save') ?></button>
                        <a href="?tab=drivers" class="btn btn-secondary btn-sm"><?= t('cancel') ?></a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
