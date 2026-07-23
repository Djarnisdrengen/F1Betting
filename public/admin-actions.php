<?php
// Compatibility redirect — the GitHub Actions dashboard now lives at
// admin-dashboards.php?tab=actions (Dashboards area, 5th tab) instead of its own
// top-level page. This shim keeps old bookmarks/links working. See
// epics/Admin settings and dashboards/plan.md decision 2.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: admin-dashboards.php?tab=actions' . ($qs !== '' ? '&' . $qs : ''), true, 302);
exit;
