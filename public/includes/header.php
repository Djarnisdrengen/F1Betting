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
        "'sha256-q9FEvsEcv32ce7lbHps7PEYb4/B1N/0+rYZYTTdgF0U='; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
    "img-src 'self' data:; " .
    "connect-src 'self'; " .
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
    setFont(getFont() === 'system' ? 'editorial' : 'system');
    $currentUrl = $_SERVER['REQUEST_URI'];
    $currentUrl = preg_replace('/([&?])toggle_font=1(&|$)/', '$1', $currentUrl);
    $currentUrl = rtrim($currentUrl, '?&');
    header('Location: ' . $currentUrl);
    exit;
}
// Refresh theme/lang after potential toggle
$theme = getTheme();
$lang = getLang();
$fontStack = getFont();

// AC1: write preference cookies on first visit so device storage is always populated
if (!isset($_COOKIE['f1_theme'])) {
    setcookie('f1_theme', $theme,     ['expires' => time() + 31536000, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}
if (!isset($_COOKIE['f1_font'])) {
    setcookie('f1_font',  $fontStack, ['expires' => time() + 31536000, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}
if (!isset($_COOKIE['f1_lang'])) {
    setcookie('f1_lang',  $lang,      ['expires' => time() + 31536000, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

$currentUser = getCurrentUser();
$settings = getSettings();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Challenge identity + CP chip. Resolving here (still pre-output) also re-establishes a
// returning participant's session from the ch_access device cookie app-wide (B3/REQ-121).
$challengeParticipant = null;
$challengeCP = 0;
try {
    require_once __DIR__ . '/challenges.php';
    $challengeParticipant = getChallengeParticipant();
    // Chip shows only for an active identity — core-linked or a verified guest (REQ-005) —
    // never for an anonymous, unverified row.
    if ($challengeParticipant
        && (!empty($challengeParticipant['core_user_id']) || ($challengeParticipant['status'] ?? '') === 'verified')) {
        $challengeCP = getChallengeCpTotal(getDB(), $challengeParticipant['id']);
    } else {
        $challengeParticipant = null;
    }
} catch (Exception $e) {
    // Challenges must never break the core site.
    $challengeParticipant = null;
}
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
    <?php if ($challengeParticipant): ?>
    <a class="hf-cp-chip" href="/challenges.php" data-testid="cp-chip" aria-hidden="true" tabindex="-1">
        <i class="fas fa-bolt" aria-hidden="true"></i><?= intval($challengeCP) ?> CP
    </a>
    <?php endif; ?>
    <button class="hf-hamburger" id="hf-hamburger" aria-label="Menu" aria-expanded="false" aria-controls="hf-drawer">
        <span class="bars"><span></span><span></span><span></span></span>
    </button>
</header>

<?php if (defined('APP_ENV') && APP_ENV === 'test'): ?>
<!-- Test-environment banner — only ever rendered when APP_ENV === 'test' -->
<div class="test-banner">
    <span class="test-banner-plate">
        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
        <?= t('test_site_banner') ?>
    </span>
</div>
<?php endif; ?>

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
    <a href="challenges.php" class="hf-drawer-row <?= $currentPage === 'challenges' ? 'active' : '' ?>" style="position:relative;">
        <i class="fas fa-gamepad" style="color:var(--f1-accent-challenges);"></i><span><?= t('ch_nav_challenges') ?></span>
        <span class="hf-badge open" style="margin-left:auto;padding:2px 8px;font-size:9px;"><?= t('ch_new_badge') ?></span>
    </a>
    <a href="challenges-board.php" class="hf-drawer-row <?= $currentPage === 'challenges-board' ? 'active' : '' ?>">
        <i class="fas fa-bolt" style="color:var(--f1-accent-challenges);"></i><span><?= t('ch_public_board') ?></span>
    </a>
    <?php if ($currentUser && $currentUser['role'] === 'admin'): ?>
    <a href="admin.php" class="hf-drawer-row <?= $currentPage === 'admin' ? 'active' : '' ?>">
        <i class="fas fa-cog"></i><span><?= t('admin') ?></span>
    </a>
    <?php endif; ?>
    <?php if ($currentUser): ?>
    <a href="profile.php" class="hf-drawer-row <?= $currentPage === 'profile' ? 'active' : '' ?>">
        <i class="fas fa-user"></i><span><?= t('profile') ?></span>
    </a>
    <?php elseif ($challengeParticipant): ?>
    <a href="challenges-profile.php" class="hf-drawer-row <?= $currentPage === 'challenges-profile' ? 'active' : '' ?>">
        <i class="fas fa-user"></i><span><?= t('profile') ?></span>
        <?php if (empty($challengeParticipant['core_user_id']) && empty($challengeParticipant['promotion_requested_at'])): ?>
            <span class="hf-badge-dot" style="margin-left:auto;" aria-hidden="true" data-testid="nav-profile-promo-dot"></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if ($currentUser): ?>
    <a href="logout.php" class="hf-drawer-row">
        <i class="fas fa-sign-out-alt"></i><span><?= t('logout') ?></span>
    </a>
    <?php else: ?>
    <a href="login.php" class="hf-drawer-row">
        <i class="fas fa-sign-in-alt"></i><span><?= t('login') ?></span>
    </a>
    <?php endif; ?>

    <div class="hf-divider"></div>
    <div class="hf-toc-title" style="padding:6px 12px 4px;"><?= t('ch_preferences') ?></div>
    <div class="hf-drawer-row" style="justify-content:space-between;padding:9px 12px;">
        <span><i class="fas fa-circle-half-stroke"></i> <?= t('theme') ?></span>
        <div class="hf-seg" style="padding:2px;">
            <a href="?toggle_theme=1" style="padding:6px 10px;" class="<?= $theme === 'light' ? 'active' : '' ?>"><i class="fas fa-sun"></i></a>
            <a href="?toggle_theme=1" style="padding:6px 10px;" class="<?= $theme === 'dark' ? 'active' : '' ?>"><i class="fas fa-moon"></i></a>
        </div>
    </div>
    <div class="hf-drawer-row" style="justify-content:space-between;padding:9px 12px;">
        <span><i class="fas fa-globe"></i> <?= t('language_label') ?></span>
        <div class="hf-seg" style="padding:2px;">
            <a href="?toggle_lang=1" style="padding:6px 12px;font-size:12px;" class="<?= $lang === 'da' ? 'active' : '' ?>">DA</a>
            <a href="?toggle_lang=1" style="padding:6px 12px;font-size:12px;" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
        </div>
    </div>
    <div class="hf-drawer-row" style="justify-content:space-between;padding:9px 12px;">
        <span><i class="fas fa-font"></i> <?= t('font_label') ?></span>
        <div class="hf-seg" style="padding:2px;">
            <a href="?toggle_font=1" style="padding:6px 12px;font-size:12px;" class="<?= $fontStack === 'editorial' ? 'active' : '' ?>"><?= t('ch_font_brand') ?></a>
            <a href="?toggle_font=1" style="padding:6px 12px;font-size:12px;" class="<?= $fontStack === 'system' ? 'active' : '' ?>"><?= t('ch_font_system') ?></a>
        </div>
    </div>
</nav>

<main>
