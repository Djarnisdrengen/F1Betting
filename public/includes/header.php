<?php
require_once __DIR__ . '/../../config.php';

//******************************************************** */
// 1. Generate a secure 128-bit (32 hex characters) nonce
//******************************************************** */
$nonce = bin2hex(random_bytes(16));

// 2. Define the CSP policy using the generated nonce
$csp_policy =
    "frame-ancestors 'none'; " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-$nonce' " .
        "'sha256-g7fzz0TV6GRE7YO5Psf4wohzOVdQHxCLJMkJ1eUqZIk=' " .
        "'sha256-5ofhTBu470bVNSfmSODufleilOm4vGBr+Ysw7pxWXsQ=' " .
        "'sha256-q9FEvsEcv32ce7lbHps7PEYb4/B1N/0+rYZYTTdgF0U=' " .
        "https://www.googletagmanager.com " .
        "https://www.google-analytics.com; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
    "img-src 'self' data: https://www.google-analytics.com https://www.googletagmanager.com; " .
    "connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com; " .
    "font-src 'self' https://fonts.gstatic.com; " .
    "report-uri /csp-report.php";


// 3. Send the CSP header to the browser
header("Content-Security-Policy: $csp_policy");
//******************************************************** */
// 1. Generate a secure 128-bit (32 hex characters) nonce
//******************************************************** */

// Handle toggle requests BEFORE any output
$theme = getTheme();
$lang = getLang();

if (isset($_GET['toggle_theme'])) {
    setTheme($theme === 'dark' ? 'light' : 'dark');
    // Preserve query parameters (like tab) when toggling
    $currentUrl = $_SERVER['REQUEST_URI'];
    $currentUrl = preg_replace('/([&?])toggle_theme=1(&|$)/', '$1', $currentUrl);
    $currentUrl = rtrim($currentUrl, '?&');
    header("Location: " . $currentUrl);
    exit;
}
if (isset($_GET['toggle_lang'])) {
    setLang($lang === 'da' ? 'en' : 'da');
    // Preserve query parameters (like tab) when toggling
    $currentUrl = $_SERVER['REQUEST_URI'];
    $currentUrl = preg_replace('/([&?])toggle_lang=1(&|$)/', '$1', $currentUrl);
    $currentUrl = rtrim($currentUrl, '?&');
    header("Location: " . $currentUrl);
    exit;
}
if (isset($_GET['toggle_font'])) {
    $_SESSION['font_stack'] = ($_SESSION['font_stack'] ?? 'editorial') === 'editorial' ? 'system' : 'editorial';
    $currentUrl = $_SERVER['REQUEST_URI'];
    $currentUrl = preg_replace('/([&?])toggle_font=1(&|$)/', '$1', $currentUrl);
    $currentUrl = rtrim($currentUrl, '?&');
    header('Location: ' . $currentUrl);
    exit;
}
// Refresh theme/lang after potential toggle
$theme = getTheme();
$lang = getLang();
$fontStack = $_SESSION['font_stack'] ?? 'editorial';

$currentUser = getCurrentUser();
$settings = getSettings();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
            <!-- Meta Tags -->
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?= escape($settings['app_title']) ?> <?= escape($settings['app_year']) ?></title>
            <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
            <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon.png">
            <link rel="apple-touch-icon" href="assets/favicon.png">
            <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
            <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
            
    <?php if (defined('APP_ENV') && APP_ENV === 'live'): ?>
            <!-- Google tag (gtag.js) -->
            <script  nonce="<?php echo $nonce; ?>" async src="https://www.googletagmanager.com/gtag/js?id=G-BFRVL7RX1N"></script>
            <script  nonce="<?php echo $nonce; ?>">
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                gtag('js', new Date());

                gtag('config', 'G-BFRVL7RX1N');

            </script>
    <?php endif; ?>
</head>
<body class="<?= escape($theme) ?> font-<?= escape($fontStack) ?>">
<header class="hf-top">
    <a class="hf-logo" href="/">
        <span class="hf-logo-mark">F1</span>
        <span class="hf-logo-text">
            <?= escape($settings['app_title']) ?>
            <span class="yr"><?= escape($settings['app_year']) ?></span>
        </span>
    </a>
    <button class="hf-hamburger" id="hf-hamburger" aria-label="Menu" aria-expanded="false" aria-controls="hf-drawer">
        <span class="bars"><span></span><span></span><span></span></span>
    </button>
</header>

<nav class="hf-drawer" id="hf-drawer">
    <a href="/" class="hf-drawer-row <?= $currentPage === 'index' ? 'active' : '' ?>">
        <i class="fas fa-home"></i><span><?= t('home') ?></span>
    </a>
    <a href="races.php" class="hf-drawer-row <?= $currentPage === 'races' ? 'active' : '' ?>">
        <i class="fas fa-flag"></i><span><?= t('races') ?></span>
    </a>
    <a href="leaderboard.php" class="hf-drawer-row <?= $currentPage === 'leaderboard' ? 'active' : '' ?>">
        <i class="fas fa-trophy"></i><span><?= t('leaderboard') ?></span>
    </a>
    <?php if ($currentUser): ?>
    <a href="rules.php" class="hf-drawer-row <?= $currentPage === 'rules' ? 'active' : '' ?>">
        <i class="fas fa-book"></i><span><?= t('rules') ?></span>
    </a>
    <?php endif; ?>
    <?php if ($currentUser && $currentUser['role'] === 'admin'): ?>
    <a href="admin.php" class="hf-drawer-row <?= $currentPage === 'admin' ? 'active' : '' ?>">
        <i class="fas fa-cog"></i><span><?= t('admin') ?></span>
    </a>
    <?php endif; ?>
    <?php if ($currentUser): ?>
    <a href="profile.php" class="hf-drawer-row <?= $currentPage === 'profile' ? 'active' : '' ?>">
        <i class="fas fa-user"></i><span><?= t('profile') ?></span>
    </a>
    <a href="logout.php" class="hf-drawer-row">
        <i class="fas fa-sign-out-alt"></i><span><?= t('logout') ?></span>
    </a>
    <?php else: ?>
    <a href="login.php" class="hf-drawer-row">
        <i class="fas fa-sign-in-alt"></i><span><?= t('login') ?></span>
    </a>
    <?php endif; ?>
</nav>

<main>
