<?php
require_once __DIR__ . '/../../config.php';

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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($settings['app_title']) ?> <?= escape($settings['app_year']) ?></title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon.png">
    <link rel="apple-touch-icon" href="assets/favicon.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
</head>
<body class="<?= $theme ?>">
    <header class="header">
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="logo">
                    <img src="assets/logo_header_dark.png" alt="<?= escape($settings['app_title']) ?>" class="logo-img logo-dark">
                    <img src="assets/logo_header_light.png" alt="<?= escape($settings['app_title']) ?>" class="logo-img logo-light">
                    <span class="logo-text"><?= escape($settings['app_title']) ?></span>
                    <span class="logo-year"><?= escape($settings['app_year']) ?></span>
                </a>
                
                <!-- Mobile menu button -->
                <button class="mobile-menu-btn" onclick="toggleMobileMenu()" aria-label="Menu">
                    <i class="fas fa-bars"></i>
                </button>
                
                <nav class="nav" id="main-nav">
                    <a href="index.php" class="nav-link <?= $currentPage === 'index' ? 'active' : '' ?>">
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
