<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/functions.php';

$lang = $_SESSION['lang'] ?? 'da';
session_unset();
$_SESSION['lang'] = $lang;
session_regenerate_id(true);
header("Location: index.php");
exit;
