<?php
// CLI harness for public/includes/passkey.php: challenge lifecycle (single-use,
// expiry, purpose binding), sign-count policy, and the PASSKEY_RPID fail-loud
// guard (exercised in a subprocess, since constants are immutable per process).
// Run: php tests/unit/passkey-harness.php    (exit 0 = all green)
// The vendored-lib vector checks land here in Phase 3 (plan 🟢-1).

if (php_sapi_name() !== 'cli') {
    exit(1);
}

$badRpid = in_array('--bad-rpid', $argv, true);

define('SITE_URL', 'https://www.example.dk');
define('SITE_DOMAIN', parse_url(SITE_URL, PHP_URL_HOST));
define('PASSKEY_RPID', $badRpid ? 'wrong.dk' : 'example.dk');
define('SMTP_FROM_NAME', 'Harness');
define('APP_LOG_FILE', sys_get_temp_dir() . '/passkey_harness.log');

// passkey.php only defines functions + an autoloader — safe to load standalone.
require __DIR__ . '/../../public/includes/passkey.php';

// logToFile stub (normally from functions.php) for code paths that log.
if (!function_exists('logToFile')) {
    function logToFile($file, $message) {}
}

$_SESSION = [];

// ── Subprocess mode: PASSKEY_RPID deliberately wrong — must throw ────────────
if ($badRpid) {
    try {
        passkeyRpId();
        fwrite(STDERR, "bad rpId was accepted\n");
        exit(1);
    } catch (RuntimeException $e) {
        echo "RPID_MISMATCH_REJECTED\n";
        exit(0);
    }
}

$fails = 0;
function check(string $name, bool $cond): void {
    global $fails;
    echo ($cond ? 'ok     ' : 'FAILED ') . $name . "\n";
    if (!$cond) $fails++;
}

// ── rpId guard ────────────────────────────────────────────────────────────────
check('rpId: matching PASSKEY_RPID returned', passkeyRpId() === 'example.dk');

$sub = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --bad-rpid 2>/dev/null');
check('rpId: mismatching PASSKEY_RPID throws (subprocess)', str_contains((string)$sub, 'RPID_MISMATCH_REJECTED'));

// ── Challenge lifecycle ──────────────────────────────────────────────────────
$wa = passkeyInstance();
$wa->getGetArgs([], 60, false, false, false, true, true, true); // mints a challenge
$expected = $wa->getChallenge()->getBinaryString();

passkeyChallengeBegin($wa, 'login');
$taken = passkeyChallengeTake('login');
check('challenge: take returns the minted challenge', $taken === $expected && strlen($taken) >= 16);
check('challenge: second take is null (single-use)', passkeyChallengeTake('login') === null);

passkeyChallengeBegin($wa, 'login');
check('challenge: purpose mismatch is null', passkeyChallengeTake('register') === null);
check('challenge: consumed even on purpose mismatch', passkeyChallengeTake('login') === null);

passkeyChallengeBegin($wa, 'challenge');
$_SESSION['webauthn_challenge']['exp'] = time() - 1;
check('challenge: expired is null', passkeyChallengeTake('challenge') === null);

passkeyChallengeBegin($wa, 'register');
passkeyChallengeBegin($wa, 'login'); // one slot: a new ceremony replaces the old
check('challenge: new ceremony invalidates the previous purpose', passkeyChallengeTake('register') === null);

// ── Sign-count policy (advisory clone detection) ─────────────────────────────
check('sign-count: 0/0 ok (counter-less authenticator)', passkeySignCountOk(0, 0) === true);
check('sign-count: 0/5 ok (first counted assertion)',    passkeySignCountOk(0, 5) === true);
check('sign-count: 5/0 ok (authenticator stopped counting)', passkeySignCountOk(5, 0) === true);
check('sign-count: 5/6 ok (normal increment)',            passkeySignCountOk(5, 6) === true);
check('sign-count: 5/5 rejected (replay)',                passkeySignCountOk(5, 5) === false);
check('sign-count: 5/4 rejected (regression → clone)',    passkeySignCountOk(5, 4) === false);

// ── Wrapper option shape (what the browser is asked for) ─────────────────────
$create = $wa->getCreateArgs('user-id', 'user@example.dk', 'User', 60, true, true, false, []);
$sel = $create->publicKey->authenticatorSelection;
check('create-args: resident key required',       ($sel->residentKey ?? '') === 'required');
check('create-args: user verification required',  ($sel->userVerification ?? '') === 'required');
check('create-args: platform attachment',         ($sel->authenticatorAttachment ?? '') === 'platform');
check('create-args: rp id is the registrable domain', ($create->publicKey->rp->id ?? '') === 'example.dk');

echo $fails === 0 ? "\nall green\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
