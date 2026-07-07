<?php
// ============================================
// PASSKEYS (WebAuthn)
// Wrapper around the vendored lbuchs/WebAuthn library (includes/webauthn/,
// see VERSION there). The library is required ONLY from this file — the rest
// of the codebase stays library-agnostic.
// Requires functions.php (getDB, generateUUID, logToFile) and mfa.php (passkeyActive).
// ============================================

// Autoload the vendored lbuchs\WebAuthn\* classes from includes/webauthn/.
spl_autoload_register(function ($class) {
    $prefix = 'lbuchs\\WebAuthn\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = __DIR__ . '/webauthn/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

// ── Relying-party id ─────────────────────────────────────────────────────────
// The rpId is a ONE-WAY DOOR: every passkey is cryptographically bound to it,
// and changing it orphans all registered credentials. PASSKEY_RPID must be set
// explicitly in config and match the registrable domain derived from SITE_URL —
// fail loud (like mfaKey()) rather than silently mint credentials for the wrong domain.
function passkeyRpId(): string {
    if (!defined('PASSKEY_RPID') || PASSKEY_RPID === '') {
        throw new RuntimeException('PASSKEY_RPID is missing from config.');
    }
    $expected = preg_replace('/^www\./i', '', SITE_DOMAIN);
    if (PASSKEY_RPID !== $expected) {
        throw new RuntimeException("PASSKEY_RPID (" . PASSKEY_RPID . ") does not match the domain derived from SITE_URL ($expected).");
    }
    return PASSKEY_RPID;
}

// Configured library instance: attestation 'none' only, base64url-encoded JSON
// (so challenges/ids serialize as plain strings for passkey.js).
function passkeyInstance(): \lbuchs\WebAuthn\WebAuthn {
    $rpName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Paddock Picks';
    return new \lbuchs\WebAuthn\WebAuthn($rpName, passkeyRpId(), ['none'], true);
}

// ── Challenge lifecycle (session, single-use, 5-minute TTL) ──────────────────
// One slot: starting a new ceremony invalidates any outstanding challenge.
function passkeyChallengeBegin(\lbuchs\WebAuthn\WebAuthn $wa, string $purpose): void {
    $_SESSION['webauthn_challenge'] = [
        'challenge' => bin2hex($wa->getChallenge()->getBinaryString()),
        'purpose'   => $purpose,
        'exp'       => time() + 300,
    ];
}

// Fetch AND consume the challenge. Consumed on every take — a failed verify
// cannot retry against the same challenge (replay guard).
function passkeyChallengeTake(string $purpose): ?string {
    $c = $_SESSION['webauthn_challenge'] ?? null;
    unset($_SESSION['webauthn_challenge']);
    if (!is_array($c) || ($c['exp'] ?? 0) < time() || ($c['purpose'] ?? '') !== $purpose) {
        return null;
    }
    $bin = hex2bin($c['challenge'] ?? '');
    return $bin === false ? null : $bin;
}

// ── Sign-count policy ────────────────────────────────────────────────────────
// Advisory clone detection only: most platform authenticators always report 0,
// so the check applies only when both counters are non-zero. Never lock the
// account on it — the assertion is just rejected and logged.
function passkeySignCountOk(int $stored, int $new): bool {
    return !($stored > 0 && $new > 0 && $new <= $stored);
}

// ── Registration ─────────────────────────────────────────────────────────────
// Create-args for navigator.credentials.create(): resident key + user verification
// required (passwordless-capable), platform attachment (best-effort steering away
// from hardware security keys), existing credentials excluded.
function passkeyRegisterOptions(PDO $db, array $user): object {
    $wa = passkeyInstance();
    $st = $db->prepare("SELECT credential_id FROM user_passkeys WHERE user_id = ?");
    $st->execute([$user['id']]);
    $exclude = $st->fetchAll(PDO::FETCH_COLUMN);
    $args = $wa->getCreateArgs(
        $user['id'],
        $user['email'],
        $user['display_name'] ?: $user['email'],
        60,      // timeout (s)
        true,    // resident key required (discoverable → passwordless login)
        true,    // user verification required
        false,   // platform attachment (false = platform, not cross-platform)
        $exclude
    );
    passkeyChallengeBegin($wa, 'register');
    return $args;
}

// Verifies the attestation response and stores the credential.
// Returns true on success; all failure detail goes to the log only.
function passkeyRegisterVerify(PDO $db, string $uid, string $clientDataJSON, string $attestationObject, ?string $transports, ?string $label): bool {
    $challenge = passkeyChallengeTake('register');
    if ($challenge === null) return false;

    $wa = passkeyInstance();
    try {
        $data = $wa->processCreate($clientDataJSON, $attestationObject, $challenge, true, true);
    } catch (Throwable $e) {
        logToFile(APP_LOG_FILE, '[PASSKEY] register verify failed for ' . $uid . ': ' . $e->getMessage());
        return false;
    }

    $credId = $data->credentialId;
    // credential_id column is VARBINARY(255); platform authenticator ids are far
    // smaller, so anything oversized is malformed input.
    if (!is_string($credId) || strlen($credId) < 16 || strlen($credId) > 255) {
        logToFile(APP_LOG_FILE, '[PASSKEY] register rejected: credential id length out of range for ' . $uid);
        return false;
    }

    $aaguid = $data->AAGUID instanceof \lbuchs\WebAuthn\Binary\ByteBuffer
        ? $data->AAGUID->getBinaryString() : (string)$data->AAGUID;
    if (strlen($aaguid) !== 16) $aaguid = null;

    $name = trim((string)$label);
    if ($name === '' || mb_strlen($name) > 100) {
        $name = passkeyAaguidLabel($aaguid) ?? passkeyDefaultLabel();
    }
    $transports = $transports !== null ? substr($transports, 0, 255) : null;

    try {
        $db->prepare("INSERT INTO user_passkeys (id, user_id, credential_id, public_key, sign_count, transports, friendly_name, aaguid)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([generateUUID(), $uid, $credId, $data->credentialPublicKey, (int)$data->signatureCounter, $transports, $name, $aaguid]);
    } catch (PDOException $e) {
        // Duplicate credential_id (uniq_credential) or FK failure — never expose which.
        logToFile(APP_LOG_FILE, '[PASSKEY] register insert failed for ' . $uid . ': ' . $e->getMessage());
        return false;
    }
    return true;
}

// ── Assertion (login / challenge) ────────────────────────────────────────────
// Get-args for navigator.credentials.get(). With a uid (second-factor challenge)
// the member's credential ids are listed; without (passwordless) the list is empty
// and the browser offers discoverable credentials.
function passkeyAssertOptions(PDO $db, ?string $uid, string $purpose): object {
    $wa = passkeyInstance();
    $ids = [];
    if ($uid !== null) {
        $st = $db->prepare("SELECT credential_id FROM user_passkeys WHERE user_id = ?");
        $st->execute([$uid]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    // Transports: internal + hybrid only (platform scope — no usb/nfc/ble).
    $args = $wa->getGetArgs($ids, 60, false, false, false, true, true, true);
    passkeyChallengeBegin($wa, $purpose);
    return $args;
}

// Verifies an assertion. $uid binds the credential to the pending user (challenge
// flow); when null (passwordless) the authenticator's userHandle must match the
// credential's owner instead. Returns the owning user_id, or null on any failure.
function passkeyAssertVerify(PDO $db, array $post, ?string $uid, string $purpose): ?string {
    $challenge = passkeyChallengeTake($purpose);
    if ($challenge === null) return null;

    $credId            = base64_decode($post['rawId'] ?? '', true);
    $clientDataJSON    = base64_decode($post['clientDataJSON'] ?? '', true);
    $authenticatorData = base64_decode($post['authenticatorData'] ?? '', true);
    $signature         = base64_decode($post['signature'] ?? '', true);
    if (!$credId || !$clientDataJSON || !$authenticatorData || !$signature) return null;

    $st = $db->prepare("SELECT * FROM user_passkeys WHERE credential_id = ?");
    $st->execute([$credId]);
    $row = $st->fetch();
    if (!$row) return null;

    if ($uid !== null) {
        if (!hash_equals($row['user_id'], $uid)) return null;
    } else {
        $userHandle = base64_decode($post['userHandle'] ?? '', true);
        if (!$userHandle || !hash_equals($row['user_id'], $userHandle)) return null;
    }

    $wa = passkeyInstance();
    try {
        // prevSignatureCnt stays null — the library would reject 0 >= 0, which every
        // counter-less platform authenticator reports. Our advisory policy runs below.
        $wa->processGet($clientDataJSON, $authenticatorData, $signature, $row['public_key'], $challenge, null, true, true);
    } catch (Throwable $e) {
        logToFile(APP_LOG_FILE, '[PASSKEY] assertion failed for ' . $row['user_id'] . ': ' . $e->getMessage());
        return null;
    }

    $newCount = (int)$wa->getSignatureCounter();
    if (!passkeySignCountOk((int)$row['sign_count'], $newCount)) {
        logToFile(APP_LOG_FILE, '[PASSKEY] sign-count regression for credential ' . $row['id'] . ' (stored ' . $row['sign_count'] . ', got ' . $newCount . ') — possible clone');
        return null;
    }

    $db->prepare("UPDATE user_passkeys SET sign_count = ?, last_used_at = NOW() WHERE id = ?")
       ->execute([$newCount, $row['id']]);
    return $row['user_id'];
}

// ── Management (Security tab) ────────────────────────────────────────────────
function passkeyList(PDO $db, string $uid): array {
    $st = $db->prepare("SELECT id, friendly_name, created_at, last_used_at FROM user_passkeys WHERE user_id = ? ORDER BY created_at");
    $st->execute([$uid]);
    return $st->fetchAll();
}

function passkeyRename(PDO $db, string $uid, string $id, string $name): bool {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 100) return false;
    $st = $db->prepare("UPDATE user_passkeys SET friendly_name = ? WHERE id = ? AND user_id = ?");
    $st->execute([$name, $id, $uid]);
    return $st->rowCount() === 1;
}

function passkeyDelete(PDO $db, string $uid, string $id): bool {
    $st = $db->prepare("DELETE FROM user_passkeys WHERE id = ? AND user_id = ?");
    $st->execute([$id, $uid]);
    return $st->rowCount() === 1;
}

// Default label from well-known authenticator AAGUIDs (plan 🟢-3). Passkey
// providers keep their real AAGUID in the attested credential data even with
// attestation 'none', so the big ones are nameable. Keys are lowercase UUIDs
// from the community passkey-AAGUID list (github.com/passkeydeveloper/
// passkey-authenticator-aaguids); unknown or zeroed ids fall back to the UA
// label. Input is the raw 16-byte value as stored in user_passkeys.aaguid.
function passkeyAaguidLabel(?string $aaguidBin): ?string {
    if ($aaguidBin === null || strlen($aaguidBin) !== 16) return null;
    $h = bin2hex($aaguidBin);
    $uuid = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
          . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    $known = [
        'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'iCloud Keychain',
        'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => 'iCloud Keychain',
        'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Google Password Manager',
        '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
        '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => 'Windows Hello',
        '6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => 'Windows Hello',
        'adce0002-35bc-c60a-648b-0b25f1f05503' => 'Chrome (Mac)',
        'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
        'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Bitwarden',
        '531126d6-e717-415c-9320-3d9aa6981239' => 'Dashlane',
        '53414d53-554e-4700-0000-000000000000' => 'Samsung Pass',
        '50726f74-6f6e-5061-7373-50726f746f6e' => 'Proton Pass',
        'fdb141b2-5d84-443e-8a35-4698c205a502' => 'KeePassXC',
        '0ea242b4-43c4-4a1b-8b17-dd6d0b6baec6' => 'Keeper',
    ];
    if (!isset($known[$uuid])) return null;
    return $known[$uuid] . ' · ' . date('d-m-Y');
}

// Coarse default label from the user agent; the member can rename it afterwards.
function passkeyDefaultLabel(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Macintosh' => 'Mac'] as $needle => $label) {
        if (stripos($ua, $needle) !== false) return $label . ' · ' . date('d-m-Y');
    }
    return 'Passkey · ' . date('d-m-Y');
}
