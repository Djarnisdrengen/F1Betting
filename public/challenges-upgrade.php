<?php
// Superseded by the Feature 3 participant profile (Account tab) — set-password and
// promotion-request now live there alongside change-password, sign-out, and preferences.
// Kept as a redirect since this URL predates that page and may be bookmarked/linked.
require_once __DIR__ . '/../config.php';
header('Location: /challenges-profile.php?tab=tab-account');
exit;
