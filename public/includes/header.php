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
    "frame-src 'self' https://consent.cookiebot.com https://consentcdn.cookiebot.com; " .
    "script-src 'self' 'nonce-$nonce' 'strict-dynamic'" .
        "'sha256-g7fzz0TV6GRE7YO5Psf4wohzOVdQHxCLJMkJ1eUqZIk=' " .
        "'sha256-5ofhTBu470bVNSfmSODufleilOm4vGBr+Ysw7pxWXsQ=' " .
        "'sha256-q9FEvsEcv32ce7lbHps7PEYb4/B1N/0+rYZYTTdgF0U=' " . 
        "https://www.googletagmanager.com " .
        "https://www.google-analytics.com " .
        "https://consentcdn.cookiebot.com " .
        "https://cookiebot.com " .
        "https://consent.cookiebot.com; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
    "img-src 'self' data: https://www.google-analytics.com https://imgsct.cookiebot.com; " .
    "connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com https://consent.cookiebot.com https://consentcdn.cookiebot.com; " .
    "font-src 'self' https://fonts.gstatic.com;";


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

// Refresh theme/lang after potential toggle
$theme = getTheme();
$lang = getLang();

$currentUser = getCurrentUser();
$settings = getSettings();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <?php if (@SITE_URL === 'https://formula-1.dk'): ?>
            <!-- Cookiebot -->
            <script nonce="<?php echo $nonce; ?>" id="Cookiebot" src="https://consent.cookiebot.com/uc.js" data-cbid="762114b7-e449-4131-af32-d8ad521ade04" data-blockingmode="auto" type="text/javascript"></script>
   <?php endif; ?>

            <!-- Meta Tags -->
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?= escape($settings['app_title']) ?> <?= escape($settings['app_year']) ?></title>
            <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
            <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon.png">
            <link rel="apple-touch-icon" href="assets/favicon.png">
            <link rel="stylesheet" href="assets/css/style.css">
            <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
            
    <?php if (@SITE_URL === 'https://formula-1.dk'): ?>
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
<body class="<?= $theme ?>">
    <header class="header">
        <div class="container">
            <div class="header-content">
                <a href="/" class="logo">
                    <img src="assets/logo_header_dark.png" alt="<?= escape($settings['app_title']) ?>" class="logo-img logo-dark">
                    <img src="assets/logo_header_light.png" alt="<?= escape($settings['app_title']) ?>" class="logo-img logo-light">
                    <span class="logo-text"><?= escape($settings['app_title']) ?></span>
                    <span class="logo-year"><?= escape($settings['app_year']) ?></span>
                </a>
                
                <!-- Mobile menu button -->
                <button class="mobile-menu-btn" data-link="toggleMobileMenu" aria-label="Menu">
                    <i class="fas fa-bars"></i>
                </button>
                
                <nav class="nav" id="main-nav">
                    <a href="/" class="nav-link <?= $currentPage === 'index' ? 'active' : '' ?>">
                        <i class="fas fa-home"></i> <span><?= t('home') ?></span>
                    </a>
                    <a href="leaderboard.php" class="nav-link <?= $currentPage === 'leaderboard' ? 'active' : '' ?>">
                        <i class="fas fa-trophy"></i> <span><?= t('leaderboard') ?></span>
                    </a>
                    <a href="races.php" class="nav-link <?= $currentPage === 'races' ? 'active' : '' ?>">
                        <i class="fas fa-flag"></i> <span><?= t('races') ?></span>
                    </a>
                    <?php if ($currentUser): ?>
                    <a href="rules.php" class="nav-link <?= $currentPage === 'rules' ? 'active' : '' ?>">
                        <i class="fas fa-book"></i> <span><?= $lang === 'da' ? 'Regler' : 'Rules' ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ($currentUser && $currentUser['role'] === 'admin'): ?>
                    <a href="admin.php" class="nav-link <?= $currentPage === 'admin' ? 'active' : '' ?>">
                        <i class="fas fa-cog"></i> <span><?= t('admin') ?></span>
                    </a>
                    <?php endif; ?>
                    
                    <!-- Mobile-only items -->
                    <div class="mobile-nav-extras">
                        <?php if ($currentUser): ?>
                            <a href="profile.php" class="nav-link <?= $currentPage === 'profile' ? 'active' : '' ?>">
                                <i class="fas fa-user"></i> <span><?= t('profile') ?></span>
                            </a>
                            <a href="logout.php" class="nav-link">
                                <i class="fas fa-sign-out-alt"></i> <span><?= t('logout') ?></span>
                            </a>
                        <?php else: ?>
                            <a href="login.php" class="nav-link">
                                <i class="fas fa-sign-in-alt"></i> <span><?= t('login') ?></span>
                            </a>
                        <?php endif; ?>
                        <div class="mobile-controls">
                            <a href="?toggle_theme=1" class="btn btn-ghost btn-icon">
                                <i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i>
                                <span><?= $lang === 'da' ? 'Skift tema' : 'Toggle theme' ?></span>
                            </a>
                            <a href="?toggle_lang=1" class="btn btn-ghost btn-icon">
                                <i class="fas fa-globe"></i>
                                <span><?= $lang === 'da' ? 'English' : 'Dansk' ?></span>
                            </a>
                        </div>
                    </div>
                </nav>
                
                <div class="controls desktop-only">
                    <!-- Theme Toggle -->
                    <a href="?toggle_theme=1" class="btn btn-ghost btn-icon" title="<?= $lang === 'da' ? 'Skift tema' : 'Toggle theme' ?>">
                        <i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i>
                    </a>
                    
                    <!-- Language Toggle -->
                    <a href="?toggle_lang=1" class="btn btn-ghost btn-icon" title="<?= $lang === 'da' ? 'Skift sprog' : 'Change language' ?>">
                        <i class="fas fa-globe"></i>
                    </a>
                    
                    <?php if ($currentUser): ?>
                        <a href="profile.php" class="btn btn-ghost">
                            <div class="user-avatar" style="width:32px;height:32px;font-size:0.875rem;">
                                <?= strtoupper(substr($currentUser['display_name'] ?: $currentUser['email'], 0, 1)) ?>
                            </div>
                            <span class="user-name"><?= escape($currentUser['display_name'] ?: $currentUser['email']) ?></span>
                            <?php if ($currentUser['stars'] > 0): ?>
                                <span class="star">★<?= $currentUser['stars'] ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="logout.php" class="btn btn-secondary btn-sm"><?= t('logout') ?></a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-primary"><?= t('login') ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>
    
    <main class="container" style="padding: 2rem 1rem; min-height: calc(100vh - 200px);">
