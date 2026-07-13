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
    <a class="hf-cp-chip" href="/challenges.php" data-testid="cp-chip" title="<?= t('ch_challenge_points') ?>"
       style="display:inline-flex;align-items:center;gap:6px;margin-left:auto;margin-right:12px;padding:4px 10px;border-radius:999px;background:rgba(230,6,0,.14);color:var(--f1-red-light);font-family:var(--mono,monospace);font-size:12px;font-weight:700;text-decoration:none;">
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
</nav>

<main>
