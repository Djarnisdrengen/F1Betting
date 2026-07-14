<h2 style="margin-bottom:16px;"><?= t('admin_ch_trivia_questions') ?></h2>

<?php
    // Shared bilingual field markup for both the always-open "add new" card and an expanded
    // edit row — only the surrounding chrome (plain card vs. compact-row body) differs.
    function renderTriviaFields(array $q): void {
        $optDa = json_decode($q['options_da'], true) ?: [];
        $optEn = json_decode($q['options_en'], true) ?: [];
        ?>
        <div class="form-group">
            <label class="form-label"><?= t('admin_ch_trivia_question_da') ?></label>
            <textarea name="question_da" class="form-input" rows="2"><?= escape($q['question_da']) ?></textarea>
        </div>
        <div class="form-group">
            <label class="form-label"><?= t('admin_ch_trivia_question_en') ?></label>
            <textarea name="question_en" class="form-input" rows="2"><?= escape($q['question_en']) ?></textarea>
        </div>

        <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="flex gap-1">
                <div class="form-group" style="flex:1;">
                    <label class="form-label"><?= sprintf(t('admin_ch_trivia_option_da'), $i + 1) ?></label>
                    <input type="text" name="option<?= $i + 1 ?>_da" class="form-input" value="<?= escape($optDa[$i] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label"><?= sprintf(t('admin_ch_trivia_option_en'), $i + 1) ?></label>
                    <input type="text" name="option<?= $i + 1 ?>_en" class="form-input" value="<?= escape($optEn[$i] ?? '') ?>">
                </div>
            </div>
        <?php endfor; ?>

        <div class="flex gap-1">
            <div class="form-group" style="flex:1;">
                <label class="form-label"><?= t('admin_ch_trivia_correct') ?></label>
                <select name="correct_option" class="form-input">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                        <option value="<?= $i ?>" <?= (int)$q['correct_option'] === $i ? 'selected' : '' ?>><?= chr(65 + $i) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;">
                <label class="form-label"><?= t('admin_ch_trivia_topic') ?></label>
                <input type="text" name="topic" class="form-input" value="<?= escape($q['topic']) ?>">
            </div>
            <div class="form-group" style="flex:1;">
                <label class="form-label"><?= t('admin_ch_trivia_publish_date') ?></label>
                <input type="date" name="publish_date" class="form-input" value="<?= escape($q['publish_date']) ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label"><?= t('admin_ch_trivia_explain_da') ?></label>
            <textarea name="explain_da" class="form-input" rows="2"><?= escape($q['explain_da']) ?></textarea>
        </div>
        <div class="form-group">
            <label class="form-label"><?= t('admin_ch_trivia_explain_en') ?></label>
            <textarea name="explain_en" class="form-input" rows="2"><?= escape($q['explain_en']) ?></textarea>
        </div>
        <?php
    }

    $blankQuestion = [
        'id' => '', 'question_da' => '', 'question_en' => '',
        'options_da' => '[]', 'options_en' => '[]', 'correct_option' => 0,
        'topic' => '', 'explain_da' => '', 'explain_en' => '',
        'publish_date' => date('Y-m-d'), 'status' => 'draft',
    ];
?>

<!-- Add question (collapsible, same pattern as includes/admin/races.php's Add Race) -->
<div class="card mb-3" id="add-trivia-form" data-testid="trivia-question" data-question-id="" data-status="draft">
    <div class="card-header collapsible-header toggleForm" data-link="trivia-form-body">
        <h3><i class="fas fa-plus-circle text-accent"></i> <?= t('admin_ch_trivia_add') ?></h3>
        <i class="fas fa-chevron-down toggle-icon"></i>
    </div>
    <div id="trivia-form-body" class="collapsible-form">
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="question_id" value="">
                <?php renderTriviaFields($blankQuestion); ?>
                <div class="flex gap-1">
                    <button type="submit" name="action" value="save_trivia_question" class="btn btn-secondary btn-sm"><?= t('admin_ch_trivia_save') ?></button>
                    <button type="submit" name="action" value="publish_trivia_question" class="btn btn-primary btn-sm"><?= t('admin_ch_trivia_publish') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
    if (empty($triviaQuestions)) {
        echo '<p class="text-muted">' . escape(t('admin_ch_trivia_list_empty')) . '</p>';
    }

    // Group the (already publish_date DESC-ordered) list under a per-ISO-week header so
    // gaps in the 6/week schedule are visible at a glance without cross-referencing dates.
    $weekCounts = [];
    foreach ($triviaQuestions as $tq) {
        $wk = isoWeekKey(new DateTime($tq['publish_date'] ?: 'now'));
        $weekCounts[$wk] = ($weekCounts[$wk] ?? 0) + 1;
    }
    $currentWeek = null;
    foreach ($triviaQuestions as $q):
        $wk = isoWeekKey(new DateTime($q['publish_date'] ?: 'now'));
        if ($wk !== $currentWeek):
            $currentWeek = $wk;
            echo '<h3 style="font-size:13px;margin:20px 0 8px;color:var(--text-muted);text-transform:uppercase;">'
                . escape(sprintf(t('admin_ch_trivia_week'), $wk, $weekCounts[$wk])) . '</h3>';
        endif;
        $isEditing = isset($_GET['edit']) && $_GET['edit'] === $q['id'];
        $label = mb_strimwidth($q['question_da'] ?: $q['question_en'], 0, 60, '…');
    ?>
    <div class="hf-racefull <?= $isEditing ? 'edit-form-active' : '' ?>"
         id="trivia-<?= escape($q['id']) ?>"
         data-testid="trivia-question" data-question-id="<?= escape($q['id']) ?>" data-status="<?= escape($q['status']) ?>">
        <div class="hf-racefull-hd">
            <div class="hf-racefull-info">
                <div class="hf-racename"><?= escape($label) ?></div>
                <div class="hf-racemeta">
                    <?= $q['topic'] !== '' ? escape($q['topic']) : '—' ?>
                    · <?= escape($q['publish_date']) ?>
                    · <span class="hf-badge <?= $q['status'] === 'published' ? 'open' : 'soon' ?>">
                          <?= $q['status'] === 'published' ? t('admin_ch_trivia_status_published') : t('admin_ch_trivia_status_draft') ?>
                      </span>
                </div>
            </div>
            <div class="flex gap-1" style="flex-shrink:0;flex-wrap:wrap;">
                <a href="?tab=trivia&edit=<?= escape($q['id']) ?>#trivia-<?= escape($q['id']) ?>"
                   class="btn btn-secondary btn-sm" title="<?= t('edit') ?>"><i class="fas fa-edit"></i></a>

                <?php if ($q['status'] !== 'published'): ?>
                <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="question_id" value="<?= escape($q['id']) ?>">
                    <button type="submit" name="action" value="quick_publish_trivia_question" class="btn btn-primary btn-sm"><?= t('admin_ch_trivia_publish') ?></button>
                </form>
                <?php endif; ?>

                <form method="POST" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="question_id" value="<?= escape($q['id']) ?>">
                    <button type="submit" name="action" value="delete_trivia_question" class="btn btn-sm btn-delete" data-name="<?= escape($label) ?>" style="background:var(--f1-red);color:#fff;border:none;"><?= t('admin_ch_trivia_delete') ?></button>
                </form>
            </div>
        </div>

        <?php if ($isEditing): ?>
        <div class="hf-racefull-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="question_id" value="<?= escape($q['id']) ?>">
                <?php renderTriviaFields($q); ?>
                <div class="flex gap-1 items-center">
                    <button type="submit" name="action" value="save_trivia_question" class="btn btn-secondary btn-sm"><?= t('admin_ch_trivia_save') ?></button>
                    <?php if ($q['status'] !== 'published'): ?>
                        <button type="submit" name="action" value="publish_trivia_question" class="btn btn-primary btn-sm"><?= t('admin_ch_trivia_publish') ?></button>
                    <?php endif; ?>
                    <a href="?tab=trivia" class="btn btn-secondary btn-sm"><?= t('cancel') ?></a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
