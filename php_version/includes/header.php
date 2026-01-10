<?php
require_once __DIR__ . '/../config.php';

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
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="apple-touch-icon" href="assets/favicon.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="<?= $theme ?>">
    <header class="header">
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="logo">
                    <img src="assets/logo.svg" alt="<?= escape($settings['app_title']) ?>" class="logo-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="logo-icon" style="display: none;"><i class="fas fa-flag-checkered"></i></div>
                    <span class="logo-text"><?= escape($settings['app_title']) ?></span>
                    <span class="logo-year"><?= escape($settings['app_year']) ?></span>
                </a>
                
                <nav class="nav">
                    <a href="index.php" class="nav-link <?= $currentPage === 'index' ? 'active' : '' ?>">
                        <i class="fas fa-home"></i> <?= t('home') ?>
                    </a>
                    <a href="races.php" class="nav-link <?= $currentPage === 'races' ? 'active' : '' ?>">
                        <i class="fas fa-flag"></i> <?= t('races') ?>
                    </a>
                    <a href="leaderboard.php" class="nav-link <?= $currentPage === 'leaderboard' ? 'active' : '' ?>">
                        <i class="fas fa-trophy"></i> <?= t('leaderboard') ?>
                    </a>
                    <?php if ($currentUser && $currentUser['role'] === 'admin'): ?>
                    <a href="admin.php" class="nav-link <?= $currentPage === 'admin' ? 'active' : '' ?>">
                        <i class="fas fa-cog"></i> <?= t('admin') ?>
                    </a>
                    <?php endif; ?>
                </nav>
                
                <div class="controls">
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
                            <span><?= escape($currentUser['display_name'] ?: $currentUser['email']) ?></span>
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
