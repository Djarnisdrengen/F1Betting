<?php
// CLI harness for public/includes/passkey.php: challenge lifecycle (single-use,
// expiry, purpose binding), sign-count policy, the PASSKEY_RPID fail-loud
// guard (exercised in a subprocess, since constants are immutable per process),
// AAGUID default labels, and known-answer vectors for the vendored lib (🟢-1):
// synthetic create/get ceremonies from an ext-openssl P-256 key, pushed through
// processCreate/processGet exactly as production calls them. Rerun on every
// version bump of public/includes/webauthn/.
// Run: php tests/unit/passkey-harness.php    (exit 0 = all green)

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

// ── AAGUID default labels (🟢-3) ──────────────────────────────────────────────
check('aaguid: known id maps to a friendly label',
    str_starts_with((string)passkeyAaguidLabel(hex2bin('fbfc3007154e4ecc8c0b6e020557d7bd')), 'iCloud Keychain'));
check('aaguid: zeroed id falls back (null)',  passkeyAaguidLabel(str_repeat("\0", 16)) === null);
check('aaguid: wrong length is null',         passkeyAaguidLabel('short') === null);
check('aaguid: null is null',                 passkeyAaguidLabel(null) === null);

// ── Vendored-lib known-answer vectors (🟢-1) ──────────────────────────────────
// Minimal CBOR encoder — just what a create ceremony needs.
function cborHead(int $major, int $val): string {
    $m = $major << 5;
    if ($val < 24)    return chr($m | $val);
    if ($val < 256)   return chr($m | 24) . chr($val);
    if ($val < 65536) return chr($m | 25) . pack('n', $val);
    return chr($m | 26) . pack('N', $val);
}
function cborInt(int $v): string      { return $v >= 0 ? cborHead(0, $v) : cborHead(1, -1 - $v); }
function cborBytes(string $b): string { return cborHead(2, strlen($b)) . $b; }
function cborText(string $t): string  { return cborHead(3, strlen($t)) . $t; }
function cborMap(array $pairs): string {
    $out = cborHead(5, count($pairs));
    foreach ($pairs as [$k, $v]) $out .= $k . $v;
    return $out;
}
function b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function pemDer(string $pem): string {
    return base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
}

$key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$det = openssl_pkey_get_details($key);
$x = str_pad($det['ec']['x'], 32, "\0", STR_PAD_LEFT);
$y = str_pad($det['ec']['y'], 32, "\0", STR_PAD_LEFT);

$rpIdHash = hash('sha256', 'example.dk', true);
$credId   = random_bytes(32);
$aaguid   = hex2bin('fbfc3007154e4ecc8c0b6e020557d7bd');
$origin   = 'https://www.example.dk';

// COSE_Key: EC2 / ES256 / P-256 with our public point.
$cose = cborMap([
    [cborInt(1),  cborInt(2)],   // kty: EC2
    [cborInt(3),  cborInt(-7)],  // alg: ES256
    [cborInt(-1), cborInt(1)],   // crv: P-256
    [cborInt(-2), cborBytes($x)],
    [cborInt(-3), cborBytes($y)],
]);

// authenticatorData = rpIdHash | flags UP+UV+AT (0x45) | signCount 0 | attested credential data
$authDataCreate = $rpIdHash . chr(0x45) . pack('N', 0)
    . $aaguid . pack('n', strlen($credId)) . $credId . $cose;
$attObj = cborMap([
    [cborText('fmt'),      cborText('none')],
    [cborText('attStmt'),  cborMap([])],
    [cborText('authData'), cborBytes($authDataCreate)],
]);

$challenge    = random_bytes(32);
$clientCreate = json_encode(['type' => 'webauthn.create', 'challenge' => b64u($challenge), 'origin' => $origin]);

$waVec   = passkeyInstance();
$created = null;
try {
    // Same call shape as passkeyRegisterVerify(): UV + UP required.
    $created = $waVec->processCreate($clientCreate, $attObj, $challenge, true, true);
} catch (Throwable $e) {
    check('vector: processCreate accepts the synthetic attestation (' . $e->getMessage() . ')', false);
}
if ($created) {
    check('vector: create returns our credential id', $created->credentialId === $credId);
    check('vector: create extracts the exact public key (DER match)',
        pemDer((string)$created->credentialPublicKey) === pemDer($det['key']));
    $gotAaguid = $created->AAGUID instanceof \lbuchs\WebAuthn\Binary\ByteBuffer
        ? $created->AAGUID->getBinaryString() : (string)$created->AAGUID;
    check('vector: create reports the AAGUID', $gotAaguid === $aaguid);

    // Assertion: flags UP+UV (0x05), counter 42, ES256 over authData || sha256(clientDataJSON).
    $authDataGet = $rpIdHash . chr(0x05) . pack('N', 42);
    $challenge2  = random_bytes(32);
    $clientGet   = json_encode(['type' => 'webauthn.get', 'challenge' => b64u($challenge2), 'origin' => $origin]);
    openssl_sign($authDataGet . hash('sha256', $clientGet, true), $sig, $key, OPENSSL_ALGO_SHA256);

    $ok = false;
    try {
        // Same call shape as passkeyAssertVerify(): prevSignatureCnt null, UV + UP required.
        $ok = $waVec->processGet($clientGet, $authDataGet, $sig, $created->credentialPublicKey, $challenge2, null, true, true);
    } catch (Throwable $e) {}
    check('vector: valid assertion verifies', $ok === true);
    check('vector: signature counter surfaced', (int)$waVec->getSignatureCounter() === 42);

    $tamperRejected = false;
    $bad = $sig;
    $bad[8] = chr(ord($bad[8]) ^ 0x01);
    try {
        $waVec->processGet($clientGet, $authDataGet, $bad, $created->credentialPublicKey, $challenge2, null, true, true);
    } catch (Throwable $e) { $tamperRejected = true; }
    check('vector: tampered signature rejected', $tamperRejected);

    $challengeRejected = false;
    try {
        $waVec->processGet($clientGet, $authDataGet, $sig, $created->credentialPublicKey, random_bytes(32), null, true, true);
    } catch (Throwable $e) { $challengeRejected = true; }
    check('vector: wrong challenge rejected', $challengeRejected);

    // UV required (as production requires) but authenticator only set UP.
    $authDataNoUv = $rpIdHash . chr(0x01) . pack('N', 43);
    openssl_sign($authDataNoUv . hash('sha256', $clientGet, true), $sigNoUv, $key, OPENSSL_ALGO_SHA256);
    $uvRejected = false;
    try {
        $waVec->processGet($clientGet, $authDataNoUv, $sigNoUv, $created->credentialPublicKey, $challenge2, null, true, true);
    } catch (Throwable $e) { $uvRejected = true; }
    check('vector: missing user verification rejected', $uvRejected);
}

echo $fails === 0 ? "\nall green\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
