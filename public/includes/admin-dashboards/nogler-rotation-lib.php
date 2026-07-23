<?php
// Nøgler & Rotation — data layer. See
// epics/Admin settings and dashboards/feature-3-nogler-rotation.md for the full spec and,
// critically, the 2026-07-23 "implementation" revision note explaining why only
// CHALLENGE_INVITE_SECRET is auto-rotatable — every other real secret in this codebase would
// be an active lockout/outage bug if blindly regenerated (MFA_KEY/PASSWORD_PEPPER invalidate
// existing user data; DB_PASS/SMTP_PASS are external credentials; INTEGRATION_SEED_TOKEN/
// CRON_SECRET are paired with a matching GitHub Actions secret).

// ── Static config (display + policy; NOT the per-item state, which is DB-backed below) ──

function nrTokenConfig(): array {
    // Only GITHUB_TOKEN is a real access token this app holds — see feature-3's REQ-305
    // revision. "Anthropic"/"OpenAI" in the original design handoff don't correspond to any
    // credential in config.php.
    return [
        'github_pat' => ['name' => 'GitHub — fine-grained PAT', 'icon' => 'fa-brands fa-github'],
    ];
}

function nrSecretConfig(): array {
    return [
        'db_password' => [
            'name' => 'DB_PASSWORD', 'configConst' => 'DB_PASS', 'icon' => 'fas fa-database',
            'policyDays' => 90, 'mode' => 'record',
        ],
        'smtp_password' => [
            'name' => 'SMTP_PASSWORD', 'configConst' => 'SMTP_PASS', 'icon' => 'fas fa-envelope',
            'policyDays' => 90, 'mode' => 'record',
        ],
        // Record-only like SMTP_PASS above: external credential for the Resend fallback
        // provider (see public/includes/smtp.php sendViaResend()); rotating it here wouldn't
        // change anything at resend.com, so it just tracks when it was last rotated there.
        'resend_api_key' => [
            'name' => 'RESEND_API_KEY', 'configConst' => 'RESEND_API_KEY', 'icon' => 'fas fa-paper-plane',
            'policyDays' => 90, 'mode' => 'record',
        ],
        // Record-only, deliberately not auto (confirmed 2026-07-23): rotating PASSWORD_PEPPER
        // invalidates every stored password hash (mass forced reset); rotating MFA_KEY makes
        // every stored TOTP secret undecryptable (mass forced 2FA re-enrollment). Real rotation
        // needs a dedicated migration (dual-verify window / rehash-on-login), out of scope here.
        'password_pepper' => [
            'name' => 'PASSWORD_PEPPER', 'configConst' => 'PASSWORD_PEPPER', 'icon' => 'fas fa-key',
            'policyDays' => 365, 'mode' => 'record',
        ],
        'mfa_key' => [
            'name' => 'MFA_KEY', 'configConst' => 'MFA_KEY', 'icon' => 'fas fa-shield-halved',
            'policyDays' => 365, 'mode' => 'record',
        ],
        // Auto-rotatable per Djarnis's explicit 2026-07-23 decision (not the original v1
        // default — see feature-3-nogler-rotation.md): rotating either of these breaks CI/cron
        // until the matching GitHub Actions secret (INTEGRATION_SEED_TOKEN_TEST /
        // CRON_SECRET[_TEST]) is updated to match — no user-facing lockout, unlike MFA_KEY/
        // PASSWORD_PEPPER below, which stay record-only because rotating those invalidates
        // every existing user's 2FA/password immediately.
        'integration_seed_token' => [
            'name' => 'INTEGRATION_SEED_TOKEN', 'configConst' => 'INTEGRATION_SEED_TOKEN', 'icon' => 'fas fa-vial',
            'policyDays' => 180, 'mode' => 'auto', 'githubSecretBase' => 'INTEGRATION_SEED_TOKEN',
        ],
        'cron_secret' => [
            'name' => 'CRON_SECRET', 'configConst' => 'CRON_SECRET', 'icon' => 'fas fa-clock',
            'policyDays' => 180, 'mode' => 'auto', 'githubSecretBase' => 'CRON_SECRET',
        ],
        // No 'githubSecretBase': unlike the two above, no CI/cron workflow reads this one —
        // see .github/workflows/*.yml, none reference CHALLENGE_INVITE_SECRET.
        'challenge_invite_secret' => [
            'name' => 'CHALLENGE_INVITE_SECRET', 'configConst' => 'CHALLENGE_INVITE_SECRET', 'icon' => 'fas fa-comments',
            'policyDays' => 180, 'mode' => 'auto',
        ],
    ];
}

// Env-aware GitHub Actions secret name for an auto-rotatable item that has a CI/cron pairing —
// live workflows read the bare name (e.g. secrets.CRON_SECRET), test workflows read the
// _TEST-suffixed one (e.g. secrets.CRON_SECRET_TEST); see .github/workflows/cron-*.yml,
// nightly-backup.yml, cron-content-topup.yml, e2e-test-orchestrator.yml. Returns null for
// items with no such pairing (challenge_invite_secret) or an unknown key.
function nrGithubSecretName(string $itemKey): ?string {
    $cfg = nrSecretConfig()[$itemKey] ?? null;
    $base = $cfg['githubSecretBase'] ?? null;
    if ($base === null) return null;
    $isLive = defined('APP_ENV') && APP_ENV === 'live';
    return $isLive ? $base : $base . '_TEST';
}

// ── Pure functions (unit-tested in tests/unit/nogler-rotation-harness.php) ──

function nrDaysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int {
    return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 86400);
}

function nrTokenBadge(?int $daysUntilExpiry): string {
    if ($daysUntilExpiry === null) return 'unknown';
    if ($daysUntilExpiry < 0) return 'bad';
    if ($daysUntilExpiry <= 14) return 'warn';
    return 'ok';
}

function nrSecretBadge(?int $ageDays, int $policyDays): string {
    if ($ageDays === null) return 'unknown';
    if ($policyDays <= 0) return 'over'; // misconfigured policy — fail toward "needs attention"
    if ($ageDays >= $policyDays) return 'over';
    if ($ageDays >= $policyDays * 0.8) return 'due';
    return 'ok';
}

function nrSecretProgressPct(?int $ageDays, int $policyDays): int {
    if ($ageDays === null) return 100;
    if ($policyDays <= 0) return 100;
    if ($ageDays < 0) $ageDays = 0; // clock-skew clamp — never a negative bar/percentage
    return min(100, (int) round($ageDays / $policyDays * 100));
}

function nrHealthScore(int $expiredTokens, int $overdueSecrets, int $soonCount): int {
    $score = 100 - ($expiredTokens * 16) - ($overdueSecrets * 11) - ($soonCount * 4);
    return max(0, min(100, $score));
}

// ── Config file location + E2E fixture redirect ──

function nrConfigFilePath(): string {
    // The runtime-loaded config file every page already requires (config.php) — not
    // config.test.php/config.live.php directly. Those are only the pre-deploy source files;
    // see config.example.php's own header comment ("the deploy script uploads the right one
    // as config.php automatically").
    return __DIR__ . '/../../../config.php';
}

function nrRotationFixtureModeActive(): bool {
    // Mirrors actions-dashboard.php's ghFixtureModeActive() gate, plus a hard block on live
    // regardless of token validity — a live/test token mixup must never redirect a live write
    // to a fixture path (or, read the other way, must never let a "test" flag skip the real
    // write on live). See plan.md decision 5 / feature-3's E2E write-path safety note.
    if (defined('APP_ENV') && APP_ENV === 'live') return false;
    if (!defined('INTEGRATION_SEED_TOKEN')) return false;
    $token = $_GET['e2e_token'] ?? $_POST['e2e_token'] ?? '';
    if ($token === '' || !hash_equals(INTEGRATION_SEED_TOKEN, (string) $token)) return false;
    return !empty($_GET['e2e_nr_fixture']) || !empty($_POST['e2e_nr_fixture']);
}

function nrConfigWritePath(): string {
    if (nrRotationFixtureModeActive()) {
        $fixturePath = sys_get_temp_dir() . '/f1betting-nr-fixture-config.php';
        // Self-seeding: E2E has no separate fixture-setup step for this file, so create it
        // with a realistic define() line for every 'auto'-mode secret's configConst the first
        // time it's needed, rather than requiring an out-of-band setup script. One line per
        // nrSecretConfig() 'auto' entry (not just CHALLENGE_INVITE_SECRET) so E2E can exercise
        // the real rotation path for CRON_SECRET/INTEGRATION_SEED_TOKEN too — nrReplaceConfigConst()
        // fails closed (ambiguous_target) if the line it's told to replace isn't present at all.
        // Per-const, not just per-file: this file can already exist from before an 'auto' secret
        // was added to nrSecretConfig() (or from an earlier fixture-file format), so checking
        // only is_file() would leave a newly-added const permanently missing. Never touches the
        // real config.php.
        if (!is_file($fixturePath)) {
            file_put_contents($fixturePath, "<?php\n");
        }
        $content = file_get_contents($fixturePath);
        foreach (nrSecretConfig() as $cfg) {
            if ($cfg['mode'] !== 'auto') continue;
            if (!preg_match("/define\\('" . preg_quote($cfg['configConst'], '/') . "',/", $content)) {
                $line = "define('{$cfg['configConst']}', 'fixture-placeholder-value');\n";
                file_put_contents($fixturePath, $line, FILE_APPEND);
                $content .= $line;
            }
        }
        return $fixturePath;
    }
    return nrConfigFilePath();
}

// ── DB-backed state ──

function nrEnsureSeeded(PDO $db): void {
    // First-load bootstrap (test-manager condition): a secret/token with no row yet must not
    // compute an undefined health score — seed a conservative baseline instead of leaving it
    // absent. Tokens get no guessed expiry (unknown until an admin records one — nrTokenBadge
    // returns 'unknown' for that); secrets get the config file's own mtime as their assumed
    // last-rotation point, which is never younger than the truth.
    $existing = array_flip($db->query("SELECT item_key FROM admin_secret_state")->fetchAll(PDO::FETCH_COLUMN));
    $configPath = nrConfigFilePath();
    $configMtime = is_file($configPath) ? date('Y-m-d H:i:s', filemtime($configPath)) : null;

    $insert = $db->prepare("INSERT INTO admin_secret_state (item_key, item_type, rotated_at) VALUES (?, ?, ?)");
    foreach (array_keys(nrTokenConfig()) as $key) {
        if (!isset($existing[$key])) $insert->execute([$key, 'token', null]);
    }
    foreach (array_keys(nrSecretConfig()) as $key) {
        if (!isset($existing[$key])) $insert->execute([$key, 'secret', $configMtime]);
    }
}

function nrRecordState(PDO $db, string $itemKey, string $itemType, string $actor, ?string $expiresAt = null, ?string $rotatedAt = null): void {
    $rotatedAt = $rotatedAt ?? date('Y-m-d H:i:s');
    $stmt = $db->prepare("
        INSERT INTO admin_secret_state (item_key, item_type, rotated_at, rotated_by, expires_at)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE rotated_at = VALUES(rotated_at), rotated_by = VALUES(rotated_by), expires_at = VALUES(expires_at)
    ");
    $stmt->execute([$itemKey, $itemType, $rotatedAt, $actor, $expiresAt]);
}

function nrLogAudit(PDO $db, string $actor, string $action, string $itemKey, ?string $detail = null): void {
    $stmt = $db->prepare("INSERT INTO admin_audit_log (actor, action, item_key, detail) VALUES (?, ?, ?, ?)");
    $stmt->execute([$actor, $action, $itemKey, $detail]);
}

function nrGetTokens(PDO $db): array {
    nrEnsureSeeded($db);
    $states = $db->query("SELECT * FROM admin_secret_state WHERE item_type = 'token'")->fetchAll(PDO::FETCH_ASSOC);
    $byKey  = array_column($states, null, 'item_key');
    $now    = new DateTimeImmutable('now');
    $out = [];
    foreach (nrTokenConfig() as $key => $cfg) {
        $state = $byKey[$key] ?? null;
        $daysUntilExpiry = null;
        if (!empty($state['expires_at'])) {
            $daysUntilExpiry = nrDaysBetween($now, new DateTimeImmutable($state['expires_at']));
        }
        $out[$key] = [
            'key' => $key, 'name' => $cfg['name'], 'icon' => $cfg['icon'],
            'rotatedAt' => $state['rotated_at'] ?? null, 'rotatedBy' => $state['rotated_by'] ?? null,
            'expiresAt' => $state['expires_at'] ?? null, 'daysUntilExpiry' => $daysUntilExpiry,
            'badge' => nrTokenBadge($daysUntilExpiry),
        ];
    }
    return $out;
}

function nrGetSecrets(PDO $db): array {
    nrEnsureSeeded($db);
    $states = $db->query("SELECT * FROM admin_secret_state WHERE item_type = 'secret'")->fetchAll(PDO::FETCH_ASSOC);
    $byKey  = array_column($states, null, 'item_key');
    $now    = new DateTimeImmutable('now');
    $out = [];
    foreach (nrSecretConfig() as $key => $cfg) {
        $state = $byKey[$key] ?? null;
        $ageDays = null;
        if (!empty($state['rotated_at'])) {
            $ageDays = max(0, nrDaysBetween(new DateTimeImmutable($state['rotated_at']), $now));
        }
        $out[$key] = [
            'key' => $key, 'name' => $cfg['name'], 'icon' => $cfg['icon'], 'mode' => $cfg['mode'],
            'configConst' => $cfg['configConst'], 'policyDays' => $cfg['policyDays'],
            'rotatedAt' => $state['rotated_at'] ?? null, 'rotatedBy' => $state['rotated_by'] ?? null,
            'ageDays' => $ageDays,
            'badge' => nrSecretBadge($ageDays, $cfg['policyDays']),
            'progressPct' => nrSecretProgressPct($ageDays, $cfg['policyDays']),
        ];
    }
    return $out;
}

function nrComputeKpis(array $tokens, array $secrets): array {
    $expiredTokens = count(array_filter($tokens, fn($t) => $t['daysUntilExpiry'] !== null && $t['daysUntilExpiry'] < 0));
    $soonTokens    = count(array_filter($tokens, fn($t) => $t['daysUntilExpiry'] !== null && $t['daysUntilExpiry'] >= 0 && $t['daysUntilExpiry'] <= 14));
    $overdueSecrets = count(array_filter($secrets, fn($s) => $s['badge'] === 'over'));
    $dueSecrets     = count(array_filter($secrets, fn($s) => $s['badge'] === 'due'));
    $soonCount = $soonTokens + $dueSecrets;

    $rotationDates = array_filter(array_map(fn($s) => $s['rotatedAt'], $secrets));

    return [
        'expiredTokens'   => $expiredTokens,
        'soonCount'       => $soonCount,
        'overdueSecrets'  => $overdueSecrets,
        'lastRotationDate'=> $rotationDates ? max($rotationDates) : null,
        'secretCount'     => count($secrets),
        'tokenCount'      => count($tokens),
        'health'          => nrHealthScore($expiredTokens, $overdueSecrets, $soonCount),
    ];
}

// Composition point for Dashboards → Oversigt (Feature 2) — read-only, no duplicate computation.
function nrGetHealthSnapshot(PDO $db): array {
    $tokens  = nrGetTokens($db);
    $secrets = nrGetSecrets($db);
    $kpis    = nrComputeKpis($tokens, $secrets);
    return [
        'health'    => $kpis['health'],
        'flagCount' => $kpis['expiredTokens'] + $kpis['overdueSecrets'],
    ];
}

function nrGetAuditLog(PDO $db, int $limit = 8): array {
    $stmt = $db->prepare("SELECT * FROM admin_audit_log ORDER BY created_at DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Write actions ──

function nrRecordTokenExpiry(PDO $db, string $itemKey, string $expiresAtDate, string $actor): array {
    if (!isset(nrTokenConfig()[$itemKey])) {
        return ['success' => false, 'error' => 'unknown_item'];
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $expiresAtDate);
    if (!$dt) {
        return ['success' => false, 'error' => 'invalid_date'];
    }
    nrRecordState($db, $itemKey, 'token', $actor, $dt->format('Y-m-d H:i:s'));
    nrLogAudit($db, $actor, 'record_token_expiry', $itemKey, 'new expiry ' . $dt->format('Y-m-d'));
    return ['success' => true, 'error' => null];
}

// 'record'-mode secrets: the human rotated it via the real channel (MySQL, Proton, the paired
// GitHub secret, or a dedicated pepper/key migration) — this just logs that it happened.
// $rotatedAtDate defaults to today but can be backdated (e.g. rotated yesterday, only
// remembered to log it now) rather than always claiming age = 0.
function nrRecordSecretRotation(PDO $db, string $itemKey, string $actor, ?string $rotatedAtDate = null): array {
    $secrets = nrSecretConfig();
    if (!isset($secrets[$itemKey]) || $secrets[$itemKey]['mode'] !== 'record') {
        return ['success' => false, 'error' => 'not_record_mode'];
    }
    $rotatedAt = null;
    if ($rotatedAtDate) {
        $dt = DateTime::createFromFormat('!Y-m-d', $rotatedAtDate);
        if (!$dt || $dt > new DateTime()) {
            return ['success' => false, 'error' => 'invalid_date'];
        }
        $rotatedAt = $dt->format('Y-m-d H:i:s');
    }
    nrRecordState($db, $itemKey, 'secret', $actor, null, $rotatedAt);
    nrLogAudit($db, $actor, 'record_secret_rotation', $itemKey, 'recorded (rotated externally)');
    return ['success' => true, 'error' => null];
}

function nrGenerateSecretValue(): string {
    // 64 hex chars — the same shape config.example.php's own generation comments specify for
    // every secret in this app ("php -r echo bin2hex(random_bytes(32))").
    return bin2hex(random_bytes(32));
}

// Pure, DB-free, file-free — unit-tested directly in tests/unit/nogler-rotation-harness.php.
// Replaces exactly one `define('CONST', '...');` line with a new value. Returns null (fails
// closed) if the constant appears zero times (missing) or 2+ times (ambiguous, e.g. a prior
// botched manual edit) — never guesses which occurrence to touch, and every other line is
// left byte-identical.
function nrReplaceConfigConst(string $content, string $constName, string $newValue): ?string {
    $pattern = "/define\\('" . preg_quote($constName, '/') . "',\\s*'[^']*'\\);/";
    if (preg_match_all($pattern, $content) !== 1) {
        return null;
    }
    return preg_replace($pattern, "define('{$constName}', '{$newValue}');", $content, 1);
}

// 'auto'-mode secrets only (v1: CHALLENGE_INVITE_SECRET). Backup -> targeted single-line
// replace -> atomic rename -> opcache invalidate, guarded by a non-blocking file lock so two
// concurrent requests for the same secret can't both regenerate+write (NFR-301).
function nrRotateSecret(PDO $db, string $itemKey, string $actor): array {
    $secrets = nrSecretConfig();
    if (!isset($secrets[$itemKey]) || $secrets[$itemKey]['mode'] !== 'auto') {
        return ['success' => false, 'error' => 'not_auto_rotatable', 'newValue' => null];
    }
    $cfg = $secrets[$itemKey];
    $configPath = nrConfigWritePath();

    $lockPath = $configPath . '.rotate.lock';
    $lockFp = @fopen($lockPath, 'c');
    if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        if ($lockFp) fclose($lockFp);
        return ['success' => false, 'error' => 'rotation_in_progress', 'newValue' => null];
    }

    try {
        if (!is_file($configPath) || !is_readable($configPath)) {
            nrLogAudit($db, $actor, 'rotate_secret_failed', $itemKey, 'config file not readable');
            return ['success' => false, 'error' => 'config_unreadable', 'newValue' => null];
        }
        $content = file_get_contents($configPath);
        if ($content === false) {
            nrLogAudit($db, $actor, 'rotate_secret_failed', $itemKey, 'read failed');
            return ['success' => false, 'error' => 'read_failed', 'newValue' => null];
        }

        $newValue = nrGenerateSecretValue();
        $newContent = nrReplaceConfigConst($content, $cfg['configConst'], $newValue);
        if ($newContent === null) {
            nrLogAudit($db, $actor, 'rotate_secret_failed', $itemKey,
                "could not unambiguously find exactly 1 define() for {$cfg['configConst']}");
            return ['success' => false, 'error' => 'ambiguous_target', 'newValue' => null];
        }

        // Backup is a hard precondition (test-manager condition) — no replace/rename is
        // attempted if it fails, and failure here is logged distinctly from a replace/rename
        // failure so the two modes aren't conflated in the audit trail.
        $backupPath = $configPath . '.bak.' . time();
        if (@copy($configPath, $backupPath) === false) {
            nrLogAudit($db, $actor, 'rotate_secret_failed', $itemKey, 'backup copy failed');
            return ['success' => false, 'error' => 'backup_failed', 'newValue' => null];
        }
        @chmod($backupPath, 0600);

        $tmpPath = $configPath . '.tmp.' . getmypid();
        if (file_put_contents($tmpPath, $newContent) === false) {
            nrLogAudit($db, $actor, 'rotate_secret_failed', $itemKey, 'write to temp file failed');
            return ['success' => false, 'error' => 'write_failed', 'newValue' => null];
        }
        if (!rename($tmpPath, $configPath)) {
            @unlink($tmpPath);
            nrLogAudit($db, $actor, 'rotate_secret_failed', $itemKey, 'atomic rename failed');
            return ['success' => false, 'error' => 'rename_failed', 'newValue' => null];
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configPath, true);
        }

        nrRecordState($db, $itemKey, 'secret', $actor);
        nrLogAudit($db, $actor, 'rotate_secret', $itemKey, 'auto-rotated');
        // newValue is returned only to the caller for one-time display (session flash) —
        // never logged (nrLogAudit detail above stays "auto-rotated") and never persisted to
        // admin_secret_state, so there is exactly one moment this value is readable in the app.
        return ['success' => true, 'error' => null, 'newValue' => $newValue];
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        @unlink($lockPath);
    }
}
