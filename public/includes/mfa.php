<?php
// ============================================
// MULTI-FACTOR AUTHENTICATION
// TOTP (RFC 6238), single-use recovery codes, email OTP.
// Requires functions.php (getDB, hashPassword, verifyPassword, t, escape).
// ============================================

// sendEmail() lives in smtp.php and is not otherwise loaded on the auth pages — pull it in
// so email OTP works wherever mfa.php is required.
require_once __DIR__ . '/smtp.php';

// ── Key management & sealing ─────────────────────────────────────────────────
// MFA_KEY seals TOTP secrets at rest. Fail loud rather than silently weaken.
function mfaKey(): string {
    if (!defined('MFA_KEY') || !preg_match('/^[0-9a-fA-F]{64}$/', MFA_KEY)) {
        throw new RuntimeException('MFA_KEY is missing or not 64 hex chars (32 bytes).');
    }
    return hex2bin(MFA_KEY);
}

// secretbox seal; nonce is prepended to the ciphertext.
function mfaSeal(string $plain): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce . sodium_crypto_secretbox($plain, $nonce, mfaKey());
}

// Returns the plaintext, or null if the blob is malformed / tampered.
function mfaOpen(string $blob): ?string {
    $nl = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if (strlen($blob) <= $nl) return null;
    $plain = sodium_crypto_secretbox_open(substr($blob, $nl), substr($blob, 0, $nl), mfaKey());
    return $plain === false ? null : $plain;
}

// ── Base32 (RFC 4648, no padding) ────────────────────────────────────────────
function base32Encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = ''; $bits = 0; $val = 0;
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $val = ($val << 8) | ord($data[$i]); $bits += 8;
        while ($bits >= 5) { $out .= $alphabet[($val >> ($bits - 5)) & 31]; $bits -= 5; }
    }
    if ($bits > 0) $out .= $alphabet[($val << (5 - $bits)) & 31];
    return $out;
}

function base32Decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $out = ''; $bits = 0; $val = 0;
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $val = ($val << 5) | strpos($alphabet, $b32[$i]); $bits += 5;
        if ($bits >= 8) { $out .= chr(($val >> ($bits - 8)) & 0xFF); $bits -= 8; }
    }
    return $out;
}

// ── TOTP (RFC 6238) ──────────────────────────────────────────────────────────
function totpSecret(): string {
    return base32Encode(random_bytes(20)); // 160-bit secret
}

// Generates the 6-digit code for a given counter window.
function totpCode(string $b32secret, ?int $timestamp = null, int $step = 30, int $digits = 6): string {
    $counter = intdiv($timestamp ?? time(), $step);
    $hash = hash_hmac('sha1', pack('J', $counter), base32Decode($b32secret), true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $truncated = ((ord($hash[$offset])     & 0x7F) << 24)
               | ((ord($hash[$offset + 1]) & 0xFF) << 16)
               | ((ord($hash[$offset + 2]) & 0xFF) << 8)
               |  (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string)($truncated % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

// Constant-time verify across a ±$window step skew.
function totpVerify(string $b32secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totpCode($b32secret, $now + $i * 30), $code)) return true;
    }
    return false;
}

// otpauth:// provisioning URI for QR rendering.
function totpUri(string $b32secret, string $account, string $issuer): string {
    return sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        rawurlencode($issuer), rawurlencode($account), $b32secret, rawurlencode($issuer)
    );
}

// ── TOTP persistence ─────────────────────────────────────────────────────────
function totpActive(PDO $db, string $uid): bool {
    $st = $db->prepare("SELECT 1 FROM user_totp WHERE user_id = ? AND confirmed_at IS NOT NULL");
    $st->execute([$uid]);
    return (bool)$st->fetchColumn();
}

// Starts (or restarts) enrollment: stores a fresh, sealed, unconfirmed secret. Returns the base32 secret.
function totpBegin(PDO $db, string $uid): string {
    $secret = totpSecret();
    $db->prepare("REPLACE INTO user_totp (user_id, secret_enc, confirmed_at) VALUES (?, ?, NULL)")
       ->execute([$uid, mfaSeal($secret)]);
    return $secret;
}

// Returns the stored secret (confirmed or pending), or null.
function totpStoredSecret(PDO $db, string $uid): ?string {
    $st = $db->prepare("SELECT secret_enc FROM user_totp WHERE user_id = ?");
    $st->execute([$uid]);
    $blob = $st->fetchColumn();
    return $blob === false ? null : mfaOpen($blob);
}

// Confirms a pending enrollment with a code from the user's app.
function totpConfirm(PDO $db, string $uid, string $code): bool {
    $secret = totpStoredSecret($db, $uid);
    if ($secret === null || !totpVerify($secret, $code)) return false;
    $db->prepare("UPDATE user_totp SET confirmed_at = NOW() WHERE user_id = ?")->execute([$uid]);
    return true;
}

// Verifies a login-time code against an ACTIVE (confirmed) authenticator.
function totpVerifyForUser(PDO $db, string $uid, string $code): bool {
    if (!totpActive($db, $uid)) return false;
    $secret = totpStoredSecret($db, $uid);
    return $secret !== null && totpVerify($secret, $code);
}

function totpDisable(PDO $db, string $uid): void {
    $db->prepare("DELETE FROM user_totp WHERE user_id = ?")->execute([$uid]);
}

// Cancels an in-progress enrollment (removes the unconfirmed row only — never an active authenticator).
function totpCancelPending(PDO $db, string $uid): void {
    $db->prepare("DELETE FROM user_totp WHERE user_id = ? AND confirmed_at IS NULL")->execute([$uid]);
}

// ── Recovery codes ───────────────────────────────────────────────────────────
function genRecoveryCodes(int $count = 10): array {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = bin2hex(random_bytes(5)); // 10 hex chars
        $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }
    return $codes;
}

// Replaces any existing set with the given codes (stored hashed).
function storeRecoveryCodes(PDO $db, string $uid, array $codes): void {
    $db->prepare("DELETE FROM user_recovery_codes WHERE user_id = ?")->execute([$uid]);
    $ins = $db->prepare("INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (?, ?)");
    foreach ($codes as $c) {
        $ins->execute([$uid, hashPassword(strtolower(trim($c)))]);
    }
}

// Single-use verification. Atomic consume guards against double-spend under concurrency.
function verifyRecoveryCode(PDO $db, string $uid, string $code): bool {
    $code = strtolower(trim($code));
    if ($code === '') return false;
    $st = $db->prepare("SELECT id, code_hash FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL");
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $row) {
        if (verifyPassword($code, $row['code_hash'])) {
            $upd = $db->prepare("UPDATE user_recovery_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
            $upd->execute([$row['id']]);
            return $upd->rowCount() === 1;
        }
    }
    return false;
}

function countRecoveryCodes(PDO $db, string $uid): int {
    $st = $db->prepare("SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL");
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

// Generates a recovery set only if the user has none (i.e. on enabling their first factor).
function ensureRecoveryCodes(PDO $db, string $uid): ?array {
    $st = $db->prepare("SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ?");
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() > 0) return null;
    $codes = genRecoveryCodes();
    storeRecoveryCodes($db, $uid, $codes);
    return $codes;
}

// ── Email OTP ────────────────────────────────────────────────────────────────
function emailOtpActive(PDO $db, string $uid): bool {
    $st = $db->prepare("SELECT email_otp_enabled FROM users WHERE id = ?");
    $st->execute([$uid]);
    return (bool)$st->fetchColumn();
}

function setEmailOtpEnabled(PDO $db, string $uid, bool $on): void {
    $db->prepare("UPDATE users SET email_otp_enabled = ? WHERE id = ?")->execute([$on ? 1 : 0, $uid]);
}

// Generates, stores (hashed, 10-min TTL) and emails a 6-digit code. $purpose: 'enroll' | 'login'.
function issueEmailOtp(PDO $db, string $uid, string $purpose): bool {
    $st = $db->prepare("SELECT email, display_name, language FROM users WHERE id = ?");
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u) return false;

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Invalidate prior unused codes of the same purpose so only the newest is valid.
    $db->prepare("UPDATE user_email_otp SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL")
       ->execute([$uid, $purpose]);
    $db->prepare("INSERT INTO user_email_otp (user_id, code_hash, purpose, expires_at)
                  VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
       ->execute([$uid, hashPassword($code), $purpose]);

    $lang    = $u['language'] ?: 'da';
    $name    = $u['display_name'] ?: $u['email'];
    $appName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'F1 Betting';

    // Standard branded email template. An empty button link makes getEmailTemplate render
    // the code as a read-only code box rather than a clickable CTA.
    $subject  = sprintf(t('email_otp_subject', $lang), $code);
    $greeting = sprintf(t('email_otp_greeting', $lang), escape($name));
    $intro    = t('email_otp_intro', $lang);
    $expiry   = t('email_otp_expiry', $lang);
    $ignore   = t('email_otp_ignore', $lang);
    $footer   = sprintf(t('email_footer', $lang), escape($appName));

    $html = getEmailTemplate($greeting, $intro, $code, '', $expiry, $ignore, $footer, $appName);
    $text = sprintf(t('email_otp_greeting', $lang), $name) . "\n\n"
          . t('email_otp_intro', $lang) . "\n\n"
          . $code . "\n\n" . $expiry . "\n\n" . t('email_otp_ignore', $lang);

    $res = sendEmail($u['email'], $subject, $html, $text); // returns ['success' => bool, 'message' => ...]
    return is_array($res) ? !empty($res['success']) : (bool)$res;
}

// Verifies a code; enforces TTL, an attempt cap, and single use. $purpose must match the issued code.
function verifyEmailOtp(PDO $db, string $uid, string $code, string $purpose): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;

    $st = $db->prepare("SELECT id, code_hash, attempts FROM user_email_otp
                        WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
                        ORDER BY id DESC LIMIT 1");
    $st->execute([$uid, $purpose]);
    $row = $st->fetch();
    if (!$row) return false;

    if ((int)$row['attempts'] >= 5) {
        $db->prepare("UPDATE user_email_otp SET used_at = NOW() WHERE id = ?")->execute([$row['id']]);
        return false;
    }
    if (verifyPassword($code, $row['code_hash'])) {
        $upd = $db->prepare("UPDATE user_email_otp SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
        $upd->execute([$row['id']]);
        return $upd->rowCount() === 1;
    }
    $db->prepare("UPDATE user_email_otp SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
    return false;
}

// ── Passkeys (Phase 2 placeholder) ───────────────────────────────────────────
function passkeyActive(PDO $db, string $uid): bool {
    $st = $db->prepare("SELECT 1 FROM user_passkeys WHERE user_id = ? LIMIT 1");
    $st->execute([$uid]);
    return (bool)$st->fetchColumn();
}

// ── Aggregate ────────────────────────────────────────────────────────────────
// True if the member has opted into ANY second factor — the trigger for the two-step login.
function userHasActiveFactor(PDO $db, string $uid): bool {
    return totpActive($db, $uid) || emailOtpActive($db, $uid) || passkeyActive($db, $uid);
}

// The active challenge methods, in fixed fallback priority. Recovery codes are always
// available as a last resort and are intentionally NOT listed here.
function activeMfaMethods(PDO $db, string $uid): array {
    $methods = [];
    if (totpActive($db, $uid))     $methods[] = 'totp';
    if (emailOtpActive($db, $uid)) $methods[] = 'email';
    return $methods;
}

// The method to show first on the challenge screen: the member's stored preference if that
// factor is still active, otherwise the first available by fallback priority (totp → email).
function getMfaDefaultMethod(PDO $db, string $uid): ?string {
    $active = activeMfaMethods($db, $uid);
    if (!$active) return null;
    $st = $db->prepare("SELECT mfa_default_method FROM users WHERE id = ?");
    $st->execute([$uid]);
    $pref = $st->fetchColumn();
    if ($pref && in_array($pref, $active, true)) return $pref;
    return $active[0];
}

// Persists the preferred method. Only 'totp' | 'email' are storable; anything else clears it.
function setMfaDefaultMethod(PDO $db, string $uid, ?string $method): void {
    $method = in_array($method, ['totp', 'email'], true) ? $method : null;
    $db->prepare("UPDATE users SET mfa_default_method = ? WHERE id = ?")->execute([$method, $uid]);
}
