<h2 style="margin-bottom:16px;"><?= t('admin_ch_rumor_section_title') ?></h2>

<div class="flex gap-1 mb-2">
    <a href="?tab=rumors&rumor_status=all" class="btn btn-sm <?= $rumorFilter === 'all' ? 'btn-primary' : 'btn-secondary' ?>">
        <?= t('admin_ch_rumor_filter_all') ?> (<?= (int) $rumorTotalCount ?>)
    </a>
    <a href="?tab=rumors&rumor_status=draft" class="btn btn-sm <?= $rumorFilter === 'draft' ? 'btn-primary' : 'btn-secondary' ?>">
        <?= t('admin_ch_rumor_filter_draft') ?> (<?= (int) $rumorDraftCount ?>)
    </a>
    <a href="?tab=rumors&rumor_status=published" class="btn btn-sm <?= $rumorFilter === 'published' ? 'btn-primary' : 'btn-secondary' ?>">
        <?= t('admin_ch_rumor_filter_published') ?> (<?= (int) ($rumorTotalCount - $rumorDraftCount) ?>)
    </a>
</div>

<?php
    // Shared bilingual field markup for both the always-open "add new" card and an expanded
    // edit row — only the surrounding chrome (plain card vs. compact-row body) differs.
    function renderRumorFields(array $item): void {
        ?>
        <div class="form-group">
            <label class="form-label"><?= t('admin_ch_rumor_text_da') ?></label>
            <textarea name="text_da" class="form-input" rows="2"><?= escape($item['text_da']) ?></textarea>
        </div>
        <div class="form-group">
            <label class="form-label"><?= t('admin_ch_rumor_text_en') ?></label>
            <textarea name="text_en" class="form-input" rows="2"><?= escape($item['text_en']) ?></textarea>
        </div>
        <div class="form-group">
            <label class="form-label"><?= t('admin_ch_rumor_context_da') ?></label>
            <input type="text" name="context_da" class="form-input" value="<?= escape($item['context_da']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label"><?= t('admin_ch_rumor_context_en') ?></label>
            <input type="text" name="context_en" class="form-input" value="<?= escape($item['context_en']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label"><?= t('admin_ch_rumor_explain_da') ?></label>
            <textarea name="explain_da" class="form-input" rows="2"><?= escape($item['explain_da']) ?></textarea>
        </div>
        <div class="form-group">
            <label class="form-label"><?= t('admin_ch_rumor_explain_en') ?></label>
            <textarea name="explain_en" class="form-input" rows="2"><?= escape($item['explain_en']) ?></textarea>
        </div>
        <div class="flex gap-1">
            <div class="form-group" style="flex:1;">
                <label class="form-label"><?= t('admin_ch_rumor_is_real') ?></label>
                <select name="is_real" class="form-input">
                    <option value="1" <?= $item['is_real'] ? 'selected' : '' ?>><?= t('admin_ch_rumor_real') ?></option>
                    <option value="0" <?= !$item['is_real'] ? 'selected' : '' ?>><?= t('admin_ch_rumor_rumor') ?></option>
                </select>
            </div>
            <div class="form-group" style="flex:1;">
                <label class="form-label"><?= t('admin_ch_rumor_publish_date') ?></label>
                <input type="date" name="publish_date" class="form-input" value="<?= escape($item['publish_date']) ?>">
            </div>
        </div>
        <?php
    }

    $blankRumor = [
        'id' => '', 'text_da' => '', 'text_en' => '', 'context_da' => '', 'context_en' => '',
        'explain_da' => '', 'explain_en' => '', 'is_real' => 1,
        'publish_date' => date('Y-m-d'), 'status' => 'draft',
    ];
?>

<!-- Add rumor (collapsible, same pattern as includes/admin/races.php's Add Race) -->
<div class="card mb-3" id="add-rumor-form" data-testid="rumor-item" data-item-id="" data-status="draft">
    <div class="card-header collapsible-header toggleForm" data-link="rumor-form-body">
        <h3><i class="fas fa-plus-circle text-accent"></i> <?= t('admin_ch_rumor_add') ?></h3>
        <i class="fas fa-chevron-down toggle-icon"></i>
    </div>
    <div id="rumor-form-body" class="collapsible-form">
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="item_id" value="">
                <input type="hidden" name="rumor_status" value="<?= escape($rumorFilter) ?>">
                <?php renderRumorFields($blankRumor); ?>
                <div class="flex gap-1">
                    <button type="submit" name="action" value="save_rumor_draft" class="btn btn-secondary btn-sm"><?= t('admin_ch_rumor_save') ?></button>
                    <button type="submit" name="action" value="publish_rumor_draft" class="btn btn-primary btn-sm"><?= t('admin_ch_rumor_publish') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($rumorItems)): ?>
<!-- Bulk action bar — checkboxes on each row below post ids[] here via the HTML5 form= attribute. -->
<form method="POST" id="bulk-rumor" class="flex gap-1 items-center mb-2" style="flex-wrap:wrap;" data-bulk-group="rumor">
    <?= csrfField() ?>
    <input type="hidden" name="rumor_status" value="<?= escape($rumorFilter) ?>">
    <label class="flex items-center gap-1" style="margin:0;cursor:pointer;">
        <input type="checkbox" data-bulk-toggle="rumor"> <?= t('admin_ch_bulk_select_all') ?>
    </label>
    <span class="text-muted" data-bulk-count="rumor" data-tpl="<?= escape(str_replace('%d', '{n}', t('admin_ch_bulk_selected'))) ?>" style="font-size:13px;"></span>
    <span style="flex:1;"></span>
    <button type="submit" name="action" value="bulk_publish_rumor" class="btn btn-primary btn-sm" data-bulk-action disabled><?= t('admin_ch_bulk_publish') ?></button>
    <button type="submit" name="action" value="bulk_unpublish_rumor" class="btn btn-secondary btn-sm" data-bulk-action disabled><?= t('admin_ch_bulk_unpublish') ?></button>
    <button type="submit" name="action" value="bulk_delete_rumor" class="btn btn-sm" data-bulk-action data-confirm="<?= escape(t('admin_ch_bulk_delete_confirm')) ?>" style="background:var(--f1-red);color:#fff;border:none;" disabled><?= t('admin_ch_bulk_delete') ?></button>
</form>
<?php endif; ?>

<?php if (empty($rumorItems)): ?>
    <p class="text-muted"><?= t('admin_ch_rumor_list_empty') ?></p>
<?php endif; ?>

<?php foreach ($rumorItems as $item):
    $isEditing = isset($_GET['edit']) && $_GET['edit'] === $item['id'];
    $label = mb_strimwidth($item['text_da'] ?: $item['text_en'], 0, 60, '…');
?>
<div class="hf-racefull <?= $isEditing ? 'edit-form-active' : '' ?>"
     id="rumor-<?= escape($item['id']) ?>"
     data-testid="rumor-item" data-item-id="<?= escape($item['id']) ?>" data-status="<?= escape($item['status']) ?>">
    <div class="hf-racefull-hd">
        <label class="hf-bulk-check" style="margin:0;padding-top:2px;cursor:pointer;" title="<?= t('admin_ch_bulk_select_all') ?>">
            <input type="checkbox" name="ids[]" value="<?= escape($item['id']) ?>" form="bulk-rumor" data-bulk-item="rumor">
        </label>
        <div class="hf-racefull-info">
            <div class="hf-racename"><?= escape($label) ?></div>
            <div class="hf-racemeta">
                <?= $item['is_real'] ? t('admin_ch_rumor_real') : t('admin_ch_rumor_rumor') ?>
                · <?= escape($item['publish_date']) ?>
                · <span class="hf-badge <?= $item['status'] === 'published' ? 'open' : 'soon' ?>">
                      <?= $item['status'] === 'published' ? t('admin_ch_rumor_status_published') : t('admin_ch_rumor_status_draft') ?>
                  </span>
                · <?= (int) $item['answer_count'] > 0
                        ? sprintf(t('admin_ch_answers_count'), (int) $item['answer_count'], (int) round($item['correct_count'] / $item['answer_count'] * 100))
                        : t('admin_ch_answers_none') ?>
            </div>
        </div>
        <div class="flex gap-1" style="flex-shrink:0;flex-wrap:wrap;">
            <a href="?tab=rumors&rumor_status=<?= escape($rumorFilter) ?>&edit=<?= escape($item['id']) ?>#rumor-<?= escape($item['id']) ?>"
               class="btn btn-secondary btn-sm" title="<?= t('edit') ?>"><i class="fas fa-edit"></i></a>

            <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="item_id" value="<?= escape($item['id']) ?>">
                <input type="hidden" name="rumor_status" value="<?= escape($rumorFilter) ?>">
                <?php if ($item['status'] !== 'published'): ?>
                    <button type="submit" name="action" value="quick_publish_rumor_item" class="btn btn-primary btn-sm"><?= t('admin_ch_rumor_publish') ?></button>
                <?php else: ?>
                    <button type="submit" name="action" value="unpublish_rumor_item" class="btn btn-secondary btn-sm"><?= t('admin_ch_rumor_unpublish') ?></button>
                <?php endif; ?>
            </form>

            <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="item_id" value="<?= escape($item['id']) ?>">
                <input type="hidden" name="rumor_status" value="<?= escape($rumorFilter) ?>">
                <?php if ($item['status'] === 'draft'): ?>
                    <button type="submit" name="action" value="veto_rumor_draft" class="btn btn-sm btn-delete" data-name="<?= escape($label) ?>" style="background:var(--f1-red);color:#fff;border:none;"><?= t('admin_ch_rumor_veto') ?></button>
                <?php else: ?>
                    <button type="submit" name="action" value="delete_rumor_item" class="btn btn-sm btn-delete" data-name="<?= escape($label) ?>" style="background:var(--f1-red);color:#fff;border:none;"><?= t('admin_ch_rumor_delete') ?></button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($isEditing): ?>
    <div class="hf-racefull-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="item_id" value="<?= escape($item['id']) ?>">
            <input type="hidden" name="rumor_status" value="<?= escape($rumorFilter) ?>">
            <?php renderRumorFields($item); ?>
            <div class="flex gap-1 items-center">
                <button type="submit" name="action" value="save_rumor_draft" class="btn btn-secondary btn-sm"><?= t('admin_ch_rumor_save') ?></button>
                <?php if ($item['status'] !== 'published'): ?>
                    <button type="submit" name="action" value="publish_rumor_draft" class="btn btn-primary btn-sm"><?= t('admin_ch_rumor_publish') ?></button>
                <?php endif; ?>
                <a href="?tab=rumors&rumor_status=<?= escape($rumorFilter) ?>" class="btn btn-secondary btn-sm"><?= t('cancel') ?></a>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
