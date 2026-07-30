<form method="POST" id="settings-main-form">
    <?= csrfField() ?>

    <div class="admin-savebar" id="settings-savebar" data-testid="settings-savebar">
        <div class="admin-savebar-status">
            <span class="admin-savebar-saved-only"><i class="fas fa-circle-check"></i> <?= t('settings_all_saved') ?></span>
            <span class="admin-savebar-dirty-only"><i class="fas fa-circle"></i> <?= t('settings_unsaved') ?></span>
        </div>
        <div class="flex gap-1 admin-savebar-actions">
            <button type="button" class="btn btn-secondary" id="settings-discard-btn" data-testid="settings-discard-btn"><?= t('discard') ?></button>
            <button type="submit" name="update_settings" class="btn btn-primary" data-testid="settings-save-btn">
                <i class="fas fa-floppy-disk"></i> <?= t('save_changes') ?>
            </button>
        </div>
    </div>

    <div class="section-card">
        <button type="button" class="admin-section-toggle" data-target="settings-section-general" data-testid="settings-toggle-general">
            <span><i class="fas fa-id-badge text-accent"></i> <?= t('settings_general_section') ?></span>
            <i class="fas fa-chevron-down admin-section-chevron"></i>
        </button>
        <div id="settings-section-general" class="admin-section-body hidden">
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
        </div>
    </div>

    <div class="section-card">
        <button type="button" class="admin-section-toggle" data-target="settings-section-hero" data-testid="settings-toggle-hero">
            <span><i class="fas fa-house text-accent"></i> <?= t('settings_hero_section') ?></span>
            <i class="fas fa-chevron-down admin-section-chevron"></i>
        </button>
        <div id="settings-section-hero" class="admin-section-body hidden">
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

            <h4 class="mb-1 mt-3"><i class="fas fa-images text-accent"></i> <?= t('home_recap_section') ?></h4>
            <p class="text-muted mb-2" style="font-size: 0.875rem;"><?= t('home_recap_config') ?></p>
            <div class="form-group mb-2" style="max-width: 200px;">
                <label class="form-label"><?= t('home_recap_count_label') ?></label>
                <input type="number" name="home_recap_count" class="form-input"
                       value="<?= intval($settings['home_recap_count'] ?? 5) ?>" min="3" max="10">
            </div>

            <h4 class="mb-1 mt-3"><i class="fas fa-arrows-rotate text-accent"></i> <?= t('content_batch_section') ?></h4>
            <p class="text-muted mb-2" style="font-size: 0.875rem;"><?= t('content_batch_config') ?></p>
            <div class="grid grid-2 mb-2">
                <div class="form-group" style="max-width: 200px;">
                    <label class="form-label"><?= t('rumor_batch_size_label') ?></label>
                    <input type="number" name="rumor_batch_size" class="form-input"
                           value="<?= intval($settings['rumor_batch_size'] ?? 6) ?>" min="1" max="20">
                </div>
                <div class="form-group" style="max-width: 200px;">
                    <label class="form-label"><?= t('trivia_batch_size_label') ?></label>
                    <input type="number" name="trivia_batch_size" class="form-input"
                           value="<?= intval($settings['trivia_batch_size'] ?? 6) ?>" min="1" max="20">
                </div>
            </div>
        </div>
    </div>

    <div class="section-card">
        <button type="button" class="admin-section-toggle" data-target="settings-section-betting" data-testid="settings-toggle-betting">
            <span><i class="fas fa-flag-checkered text-accent"></i> <?= t('settings_betting_rules_section') ?></span>
            <i class="fas fa-chevron-down admin-section-chevron"></i>
        </button>
        <div id="settings-section-betting" class="admin-section-body hidden">
            <h4 class="mb-1 mt-2"><i class="fas fa-clock text-accent"></i> <?= t('betting_window_section') ?></h4>
            <p class="text-muted mb-2" style="font-size: 0.875rem;">
                <?= t('betting_window_config') ?>
            </p>
            <div class="grid grid-2 mb-2">
                <div class="form-group form-group-inline">
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
                <div class="form-group form-group-inline">
                    <label class="form-label flex items-center gap-1"><span class="position-badge position-1">P1</span> <?= t('points_label_heading') ?></label>
                    <input type="number" name="points_p1" class="form-input" value="<?= intval($settings['points_p1'] ?? 25) ?>" min="0" max="100">
                </div>
                <div class="form-group form-group-inline">
                    <label class="form-label flex items-center gap-1"><span class="position-badge position-2">P2</span> <?= t('points_label_heading') ?></label>
                    <input type="number" name="points_p2" class="form-input" value="<?= intval($settings['points_p2'] ?? 18) ?>" min="0" max="100">
                </div>
                <div class="form-group form-group-inline">
                    <label class="form-label flex items-center gap-1"><span class="position-badge position-3">P3</span> <?= t('points_label_heading') ?></label>
                    <input type="number" name="points_p3" class="form-input" value="<?= intval($settings['points_p3'] ?? 15) ?>" min="0" max="100">
                </div>
                <div class="form-group form-group-inline">
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
            <div class="form-group form-group-inline mb-2">
                <label class="form-label"><?= t('bet_size_label') ?></label>
                <input type="number" name="bet_size" class="form-input" value="<?= intval($settings['bet_size'] ?? 10) ?>" min="1" max="1000">
            </div>
        </div>
    </div>

    <div class="section-card">
        <div class="admin-section-static-header"><i class="fas fa-sliders text-accent"></i> <?= t('features_section') ?></div>
        <div class="admin-section-static-body">
            <p class="text-muted mb-2" style="font-size: 0.875rem;"><?= t('features_desc') ?></p>
            <div class="admin-toggle-row">
                <div>
                    <div class="admin-toggle-row-title">
                        <?= t('email_delivery_status') ?>:
                        <strong data-testid="challenges-enabled-status">
                            <?= !empty($settings['challenges_enabled']) ? t('challenges_status_on') : t('challenges_status_off') ?>
                        </strong>
                    </div>
                    <div class="admin-toggle-row-desc"><?= t('challenges_visibility_desc') ?></div>
                </div>
                <label class="btn btn-secondary admin-toggle-btn"
                       data-on-text="<?= escape(t('challenges_toggle_off')) ?>"
                       data-off-text="<?= escape(t('challenges_toggle_on')) ?>"
                       data-on-status="<?= escape(t('challenges_status_on')) ?>"
                       data-off-status="<?= escape(t('challenges_status_off')) ?>">
                    <input type="checkbox" name="challenges_enabled" value="1" class="admin-toggle-input" data-testid="challenges-enabled-toggle" <?= !empty($settings['challenges_enabled']) ? 'checked' : '' ?>>
                    <i class="fas fa-<?= !empty($settings['challenges_enabled']) ? 'eye-slash' : 'eye' ?>"></i>
                    <span class="admin-toggle-btn-text"><?= !empty($settings['challenges_enabled']) ? t('challenges_toggle_off') : t('challenges_toggle_on') ?></span>
                </label>
            </div>
        </div>
    </div>
</form>

<?php
// Email delivery toggle — only relevant on the test environment (SMTP_INTERCEPT on).
require_once __DIR__ . '/../smtp.php';
if (defined('SMTP_INTERCEPT') && SMTP_INTERCEPT):
    $emailLive = !emailIntercepted();
?>
<div class="section-card">
    <div class="admin-section-static-header"><i class="fas fa-envelope text-accent"></i> <?= t('email_delivery_section') ?></div>
    <div class="admin-section-static-body">
        <p class="text-muted mb-2" style="font-size:0.875rem;"><?= t('email_delivery_desc') ?></p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="toggle_smtp_live" value="1">
            <div class="admin-toggle-row">
                <div>
                    <div class="admin-toggle-row-title">
                        <?= t('email_delivery_status') ?>:
                        <strong data-testid="email-delivery-status">
                            <?= $emailLive ? t('email_delivery_live') : t('email_delivery_intercept') ?>
                        </strong>
                    </div>
                    <div class="admin-toggle-row-desc">
                        <?= $emailLive ? t('email_delivery_row_desc_live') : t('email_delivery_row_desc_intercept') ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-secondary admin-toggle-btn" data-testid="email-delivery-toggle">
                    <i class="fas fa-envelope<?= $emailLive ? '' : '-open' ?>"></i>
                    <?= $emailLive ? t('email_delivery_switch_intercept') : t('email_delivery_switch_live') ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="section-card">
    <div class="admin-section-static-header"><i class="fas fa-clock-rotate-left text-accent"></i> <?= t('backfill_snapshots_section') ?></div>
    <div class="admin-section-static-body">
        <p class="text-muted mb-2" style="font-size:0.875rem;"><?= t('backfill_snapshots_desc') ?></p>
        <form method="POST">
            <?= csrfField() ?>
            <div class="admin-toggle-row">
                <div>
                    <div class="admin-toggle-row-title"><?= t('backfill_snapshots_title') ?></div>
                    <div class="admin-toggle-row-desc"><?= t('backfill_snapshots_row_desc') ?></div>
                </div>
                <button type="submit" name="backfill_snapshots" class="btn btn-secondary admin-toggle-btn">
                    <i class="fas fa-rotate"></i> <?= t('backfill_snapshots_btn') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= $nonce ?>">
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.admin-section-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target  = document.getElementById(this.dataset.target);
            var chevron = this.querySelector('.admin-section-chevron');
            var isHidden = target.classList.toggle('hidden');
            chevron.classList.toggle('fa-chevron-down', isHidden);
            chevron.classList.toggle('fa-chevron-up', !isHidden);
        });
    });

    var form = document.getElementById('settings-main-form');
    var savebar = document.getElementById('settings-savebar');
    if (form && savebar) {
        form.querySelectorAll('input, textarea').forEach(function (el) {
            el.addEventListener('input', function () { savebar.classList.add('dirty'); });
            el.addEventListener('change', function () { savebar.classList.add('dirty'); });
        });

        // The Features toggle is a checkbox styled as a button (kept inside the main form so
        // it stays Save/Discard-governed, not an immediate action like Ranking maintenance /
        // Email delivery) — its icon/label/status text need a manual refresh on toggle and on
        // Discard, since neither "change" nor form.reset() updates them on their own.
        var challengesToggle = document.querySelector('[data-testid="challenges-enabled-toggle"]');
        var challengesBtn = challengesToggle ? challengesToggle.closest('.admin-toggle-btn') : null;
        function syncChallengesToggle() {
            if (!challengesToggle || !challengesBtn) return;
            var checked = challengesToggle.checked;
            var icon = challengesBtn.querySelector('i');
            var text = challengesBtn.querySelector('.admin-toggle-btn-text');
            var status = document.querySelector('[data-testid="challenges-enabled-status"]');
            icon.classList.toggle('fa-eye-slash', checked);
            icon.classList.toggle('fa-eye', !checked);
            text.textContent = checked ? challengesBtn.dataset.onText : challengesBtn.dataset.offText;
            if (status) status.textContent = checked ? challengesBtn.dataset.onStatus : challengesBtn.dataset.offStatus;
        }
        if (challengesToggle) {
            challengesToggle.addEventListener('change', syncChallengesToggle);
        }

        var discardBtn = document.getElementById('settings-discard-btn');
        if (discardBtn) {
            discardBtn.addEventListener('click', function () {
                form.reset();
                savebar.classList.remove('dirty');
                syncChallengesToggle();
            });
        }
    }
});
</script>
