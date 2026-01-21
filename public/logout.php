<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

session_destroy();
header("Location: index.php");
exit;
