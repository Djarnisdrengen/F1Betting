<?php
// Renders a qualifying or result row (P1/P2/P3 badges with driver names).
// Caller must set before including:
//   $_qd_data         — array with the field values (e.g. $race or $bet)
//   $_qd_keys         — ['quali_p1','quali_p2','quali_p3'] or ['result_p1','result_p2','result_p3']
//   $_qd_label        — display label (already translated)
//   $_qd_style        — (optional) wrapper div CSS; defaults to card-style with background
// $driversById must be in scope from the calling file.
?>
<?php if ($_qd_data[$_qd_keys[0]] ?? null): ?>
    <div style="<?= $_qd_style ?? 'background: var(--bg-secondary); padding: 0.75rem; border-radius: 8px; margin-top: 1rem;' ?>">
        <small class="text-muted"><?= $_qd_label ?></small>
        <div class="quali-row">
            <?php foreach ($_qd_keys as $i => $key): ?>
                <?php $driver = $driversById[$_qd_data[$key]] ?? null; if ($driver): ?>
                    <div class="quali-item">
                        <span class="position-badge position-<?= $i + 1 ?>">P<?= $i + 1 ?></span>
                        <?= escape($driver['name']) ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
<?php unset($_qd_data, $_qd_keys, $_qd_label, $_qd_style); ?>
