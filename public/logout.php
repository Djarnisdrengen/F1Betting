<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/challenges.php';

// Revoke this device's challenge access token + clear its cookie (B3/REQ-123).
try { revokeAccessToken(getDB()); } catch (Exception $e) {}

$lang = $_SESSION['lang'] ?? 'da';
session_unset();
$_SESSION['lang'] = $lang;
session_regenerate_id(true);
header("Location: index.php");
exit;
