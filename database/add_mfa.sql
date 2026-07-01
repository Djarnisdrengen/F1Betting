-- Multi-Factor & Passkey Authentication — additive migration (Phase 0)
-- Safe to run repeatedly. No changes to existing columns except one additive flag.

-- Email-OTP opt-in flag. (Run once; MySQL has no IF NOT EXISTS for ADD COLUMN, so
-- guard manually or ignore the "Duplicate column" error on re-run.)
ALTER TABLE users ADD COLUMN email_otp_enabled TINYINT(1) NOT NULL DEFAULT 0;

-- TOTP authenticator secret (sealed at rest with sodium secretbox under MFA_KEY).
-- A row with confirmed_at IS NULL is a pending enrollment and is NOT an active factor.
CREATE TABLE IF NOT EXISTS user_totp (
    user_id      VARCHAR(36)    CHARACTER SET latin1 COLLATE latin1_swedish_ci PRIMARY KEY,
    secret_enc   VARBINARY(255) NOT NULL,
    confirmed_at DATETIME       NULL,
    created_at   DATETIME       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4;

-- Single-use recovery codes (stored only as password hashes).
CREATE TABLE IF NOT EXISTS user_recovery_codes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    VARCHAR(36)  CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
    code_hash  VARCHAR(255) NOT NULL,
    used_at    DATETIME     NULL,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_recovery_user (user_id)
) DEFAULT CHARSET=utf8mb4;

-- Transient email OTP codes (hashed, short TTL, attempt-capped, single-use).
CREATE TABLE IF NOT EXISTS user_email_otp (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    VARCHAR(36)        CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
    code_hash  VARCHAR(255)       NOT NULL,
    purpose    ENUM('enroll','login') NOT NULL,
    expires_at DATETIME           NOT NULL,
    attempts   TINYINT UNSIGNED   NOT NULL DEFAULT 0,
    used_at    DATETIME           NULL,
    created_at DATETIME           DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_emailotp_user (user_id)
) DEFAULT CHARSET=utf8mb4;

-- Passkeys / WebAuthn credentials (Phase 2 — table created now so the schema is complete).
CREATE TABLE IF NOT EXISTS user_passkeys (
    id            VARCHAR(36)    CHARACTER SET latin1 COLLATE latin1_swedish_ci PRIMARY KEY,
    user_id       VARCHAR(36)    CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
    credential_id VARBINARY(255) NOT NULL,
    public_key    BLOB           NOT NULL,
    sign_count    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    transports    VARCHAR(255)   NULL,
    friendly_name VARCHAR(100)   NULL,
    aaguid        BINARY(16)     NULL,
    created_at    DATETIME       DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME       NULL,
    UNIQUE KEY uniq_credential (credential_id),
    KEY idx_passkey_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4;
