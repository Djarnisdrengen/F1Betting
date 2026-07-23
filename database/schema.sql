-- F1 Betting Database Schema for MySQL
-- Kør dette script i phpMyAdmin på Simply.com
-- Select the target database in phpMyAdmin before running — do not add CREATE DATABASE or USE here.

-- Brugere
CREATE TABLE users (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    display_name VARCHAR(100),
    role ENUM('user', 'admin') DEFAULT 'user',
    points INT DEFAULT 0,
    stars INT DEFAULT 0,
    in_competition TINYINT(1) DEFAULT 1,
    language   VARCHAR(2) NOT NULL DEFAULT 'da',
    theme      ENUM('dark','light')       NULL DEFAULT NULL,
    font_stack ENUM('system','editorial') NULL DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL,
    email_otp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    mfa_default_method VARCHAR(16) DEFAULT NULL,
    password_changed_at DATETIME NULL DEFAULT NULL
);

-- Multi-factor authentication (see database/add_mfa.sql for the migration applied to existing DBs).
-- NOTE: user_id columns pin latin1_swedish_ci to match users.id (legacy collation) for the FK.
CREATE TABLE user_totp (
    user_id      VARCHAR(36)    CHARACTER SET latin1 COLLATE latin1_swedish_ci PRIMARY KEY,
    secret_enc   VARBINARY(255) NOT NULL,
    confirmed_at DATETIME       NULL,
    created_at   DATETIME       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE user_recovery_codes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    VARCHAR(36)  CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
    code_hash  VARCHAR(255) NOT NULL,
    used_at    DATETIME     NULL,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_recovery_user (user_id)
);

CREATE TABLE user_email_otp (
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
);

-- WebAuthn credentials (public/includes/passkey.php). credential_id/public_key
-- come from the authenticator; a row existing = the member is passkey-enrolled
-- and their login is two-step (see docs/gotchas.md #20 on PASSKEY_RPID binding).
CREATE TABLE user_passkeys (
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
);

 
-- Kørere
CREATE TABLE drivers (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    team VARCHAR(100) NOT NULL,
    number INT NOT NULL
);

-- Løb
CREATE TABLE races (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(100) NOT NULL,
    race_date DATE NOT NULL,
    race_time TIME NOT NULL,
    quali_date DATE NULL,
    quali_time TIME NULL,
    quali_p1 VARCHAR(36),
    quali_p2 VARCHAR(36),
    quali_p3 VARCHAR(36),
    result_p1 VARCHAR(36),
    result_p2 VARCHAR(36),
    result_p3 VARCHAR(36),
    bettingpool_won tinyint(1),
    bettingpool_size INT,
    FOREIGN KEY (quali_p1) REFERENCES drivers(id) ON DELETE SET NULL,
    FOREIGN KEY (quali_p2) REFERENCES drivers(id) ON DELETE SET NULL,
    FOREIGN KEY (quali_p3) REFERENCES drivers(id) ON DELETE SET NULL,
    FOREIGN KEY (result_p1) REFERENCES drivers(id) ON DELETE SET NULL,
    FOREIGN KEY (result_p2) REFERENCES drivers(id) ON DELETE SET NULL,
    FOREIGN KEY (result_p3) REFERENCES drivers(id) ON DELETE SET NULL
);

-- Migration v2.3.0: qualifying timing (run once on each environment via phpMyAdmin)
-- ALTER TABLE races
--   ADD COLUMN quali_date DATE NULL AFTER race_time,
--   ADD COLUMN quali_time TIME NULL AFTER quali_date;

-- Bets
CREATE TABLE bets (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    race_id VARCHAR(36) NOT NULL,
    p1 VARCHAR(36) NOT NULL,
    p2 VARCHAR(36) NOT NULL,
    p3 VARCHAR(36) NOT NULL,
    points INT DEFAULT 0,
    is_perfect TINYINT(1) DEFAULT 0,
    placed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (race_id) REFERENCES races(id) ON DELETE CASCADE,
    FOREIGN KEY (p1) REFERENCES drivers(id),
    FOREIGN KEY (p2) REFERENCES drivers(id),
    FOREIGN KEY (p3) REFERENCES drivers(id),
    UNIQUE KEY unique_user_race (user_id, race_id)
);

-- Indstillinger
CREATE TABLE settings (
    id INT PRIMARY KEY DEFAULT 1,
    app_title VARCHAR(100) DEFAULT 'F1 Betting',
    app_year VARCHAR(10) DEFAULT '2025',
    hero_title_en VARCHAR(200) DEFAULT 'Predict the Podium',
    hero_title_da VARCHAR(200) DEFAULT 'Forudsig Podiet',
    hero_text_en TEXT,
    hero_text_da TEXT,
    points_p1 INT DEFAULT 25,
    points_p2 INT DEFAULT 18,
    points_p3 INT DEFAULT 15,
    points_wrong_pos INT DEFAULT 5,
    betting_window_hours INT DEFAULT 48,
    bet_size INT DEFAULT 10,
    challenge_rumor_deck_size INT DEFAULT 3,
    challenge_invite_daily_cap INT NOT NULL DEFAULT 5
);

-- Indsæt standard indstillinger
INSERT INTO settings (id, app_title, app_year, hero_title_en, hero_title_da, hero_text_en, hero_text_da, points_p1, points_p2, points_p3, points_wrong_pos, betting_window_hours, bet_size, challenge_rumor_deck_size, challenge_invite_daily_cap) VALUES
(1, 'F1 Betting', '2025', 'Predict the Podium', 'Forudsig Podiet',
'Compete with friends by predicting top 3 for each Grand Prix. Earn points for correct predictions.',
'Konkurrér med venner ved at forudsige top 3 for hvert Grand Prix. Optjen point for korrekte forudsigelser.',
25, 18, 15, 5, 48, 10, 3, 5);

-- Password reset tokens
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Rate limiting: tracks failed login/MFA attempts per IP and per account (sliding
-- 15-minute window). scope separates the password/passkey login step from the MFA
-- challenge step so exhausting one budget never blocks the other. account is the
-- submitted email (login scope) or the authenticated user id (mfa scope); NULL when
-- the target account isn't known yet (e.g. a failed passwordless passkey attempt).
CREATE TABLE login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)  NOT NULL,
    scope        VARCHAR(10)  NOT NULL DEFAULT 'login',
    account      VARCHAR(255) NULL,
    attempted_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_scope_time (ip, scope, attempted_at),
    INDEX idx_account_scope_time (account, scope, attempted_at)
);

-- Invitations (kun admin kan invitere nye brugere)
CREATE TABLE invites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    created_by VARCHAR(36) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Leaderboard rank snapshots — written after each race scoring; used to compute rank delta on leaderboard
CREATE TABLE IF NOT EXISTS leaderboard_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    race_id VARCHAR(36) NOT NULL,
    `rank` INT NOT NULL,
    points INT NOT NULL,
    scored_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_race (user_id, race_id)
) DEFAULT CHARSET=utf8mb4;

-- Service admin account — same credentials in test and live (set F1_ADMIN_EMAIL + F1_ADMIN_PASSWORD in config.php)
-- Password: UKT@Qhs!fbUH@UX0pnjLpv@yt$GHfwTyCzF3Jl1GuwS#yjIQtJaaJ6V9icV8xU2R
INSERT INTO users (id, email, password, display_name, role, in_competition, points, stars) VALUES
(UUID(), 'f1_admin@helvegpovlsen.dk', 'change-me-to-a-real-password', 'F1 Admin', 'admin', 0, 0, 0);

-- Indsæt F1 kørere 2025
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'Max Verstappen', 'Red Bull Racing', 1),
(UUID(), 'Sergio Perez', 'Red Bull Racing', 11),
(UUID(), 'Lewis Hamilton', 'Ferrari', 44),
(UUID(), 'Charles Leclerc', 'Ferrari', 16),
(UUID(), 'Lando Norris', 'McLaren', 4),
(UUID(), 'Oscar Piastri', 'McLaren', 81),
(UUID(), 'George Russell', 'Mercedes', 63),
(UUID(), 'Andrea Kimi Antonelli', 'Mercedes', 12),
(UUID(), 'Fernando Alonso', 'Aston Martin', 14),
(UUID(), 'Lance Stroll', 'Aston Martin', 18);

-- Paddock Challenges — guest access, Challenge Points, and three games (Rumor or Not, Duels, Trivia)

-- Challenge participants — guests and core-linked players
CREATE TABLE IF NOT EXISTS challenge_participants (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(255) NULL UNIQUE,
    core_user_id VARCHAR(36) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL UNIQUE,
    display_name VARCHAR(100) NULL,
    language CHAR(2) NOT NULL DEFAULT 'da',
    status ENUM('pending','verified') NOT NULL DEFAULT 'pending',
    password_hash VARCHAR(255) NULL,
    promotion_requested_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    verified_at DATETIME NULL,
    FOREIGN KEY (core_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Challenge Points ledger — append-only, idempotent via UNIQUE(participant_id, source_ref)
CREATE TABLE IF NOT EXISTS challenge_points (
    id VARCHAR(36) PRIMARY KEY,
    participant_id VARCHAR(36) NOT NULL,
    game ENUM('rumor_or_not','duel','trivia') NOT NULL,
    points INT NOT NULL,
    source_ref VARCHAR(64) NOT NULL,
    awarded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_participant_source (participant_id, source_ref),
    KEY idx_participant (participant_id),
    FOREIGN KEY (participant_id) REFERENCES challenge_participants(id) ON DELETE CASCADE
);

-- Magic link tokens — single-use, 30-minute expiry (like password_resets)
CREATE TABLE IF NOT EXISTS challenge_magic_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id VARCHAR(36) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (participant_id) REFERENCES challenge_participants(id) ON DELETE CASCADE
);

-- Access tokens — persistent return (D13): hashed, rotating, ~90-day device/link tokens
CREATE TABLE IF NOT EXISTS challenge_access_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id VARCHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_participant (participant_id),
    FOREIGN KEY (participant_id) REFERENCES challenge_participants(id) ON DELETE CASCADE
);

-- Beat-my-score invites (D12) — one row per challenge sent to a friend
CREATE TABLE IF NOT EXISTS challenge_invites (
    id VARCHAR(36) PRIMARY KEY,
    challenger_id VARCHAR(36) NOT NULL,
    game ENUM('rumor_or_not','trivia') NOT NULL,
    item_ids JSON NOT NULL,
    challenger_score INT NOT NULL,
    friend_email VARCHAR(255) NOT NULL,
    friend_token VARCHAR(64) NOT NULL UNIQUE,
    friend_participant_id VARCHAR(36) NULL,
    friend_score INT NULL,
    status ENUM('sent','accepted','completed','expired') NOT NULL DEFAULT 'sent',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME NULL,
    completed_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    KEY idx_friend_email (friend_email),
    KEY idx_challenger (challenger_id),
    FOREIGN KEY (challenger_id) REFERENCES challenge_participants(id) ON DELETE CASCADE,
    FOREIGN KEY (friend_participant_id) REFERENCES challenge_participants(id) ON DELETE SET NULL
);

-- Suppression / opt-out / complaint / bounce list — checked before every friend send (REQ-802).
CREATE TABLE IF NOT EXISTS challenge_email_suppressions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    reason ENUM('opt_out','complaint','bounce','admin') NOT NULL DEFAULT 'opt_out',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Rumor or Not — items (real facts or synthetic rumors)
CREATE TABLE IF NOT EXISTS challenge_items (
    id VARCHAR(36) PRIMARY KEY,
    text_da TEXT NOT NULL,
    text_en TEXT NOT NULL,
    context_da VARCHAR(64) NOT NULL,
    context_en VARCHAR(64) NOT NULL,
    explain_da TEXT NOT NULL,
    explain_en TEXT NOT NULL,
    is_real TINYINT(1) NOT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    publish_date DATE NULL,
    source_ref VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status_date (status, publish_date)
);

-- Rumor or Not answers — UNIQUE enforces one answer per participant per item
CREATE TABLE IF NOT EXISTS challenge_answers (
    id VARCHAR(36) PRIMARY KEY,
    participant_id VARCHAR(36) NOT NULL,
    item_id VARCHAR(36) NOT NULL,
    guess_real TINYINT(1) NOT NULL,
    correct TINYINT(1) NOT NULL,
    answered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_participant_item (participant_id, item_id),
    KEY idx_participant (participant_id),
    FOREIGN KEY (participant_id) REFERENCES challenge_participants(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES challenge_items(id) ON DELETE CASCADE
);

-- Prediction Duels
CREATE TABLE IF NOT EXISTS duels (
    id VARCHAR(36) PRIMARY KEY,
    race_id VARCHAR(36) NOT NULL,
    challenger_id VARCHAR(36) NOT NULL,
    opponent_id VARCHAR(36) NOT NULL,
    is_quick_match TINYINT(1) DEFAULT 0,
    status ENUM('pending','active','resolved','void') NOT NULL DEFAULT 'pending',
    winner_id VARCHAR(36) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    KEY idx_race_status (race_id, status),
    FOREIGN KEY (race_id) REFERENCES races(id) ON DELETE CASCADE,
    FOREIGN KEY (challenger_id) REFERENCES challenge_participants(id) ON DELETE CASCADE,
    FOREIGN KEY (opponent_id) REFERENCES challenge_participants(id) ON DELETE CASCADE,
    FOREIGN KEY (winner_id) REFERENCES challenge_participants(id) ON DELETE SET NULL
);

-- Quick Match waiting room — one open request per participant per race
CREATE TABLE IF NOT EXISTS duel_quickmatch (
    race_id VARCHAR(36) NOT NULL,
    participant_id VARCHAR(36) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_race_participant (race_id, participant_id),
    FOREIGN KEY (race_id) REFERENCES races(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES challenge_participants(id) ON DELETE CASCADE
);

-- Duel predictions — UNIQUE enforces one pick per participant per duel
CREATE TABLE IF NOT EXISTS duel_predictions (
    id VARCHAR(36) PRIMARY KEY,
    duel_id VARCHAR(36) NOT NULL,
    participant_id VARCHAR(36) NOT NULL,
    p1 VARCHAR(36) NULL,
    p2 VARCHAR(36) NULL,
    p3 VARCHAR(36) NULL,
    score INT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_duel_participant (duel_id, participant_id),
    KEY idx_participant (participant_id),
    FOREIGN KEY (duel_id) REFERENCES duels(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES challenge_participants(id) ON DELETE CASCADE,
    FOREIGN KEY (p1) REFERENCES drivers(id) ON DELETE SET NULL,
    FOREIGN KEY (p2) REFERENCES drivers(id) ON DELETE SET NULL,
    FOREIGN KEY (p3) REFERENCES drivers(id) ON DELETE SET NULL
);

-- Trivia questions — 6 per ISO week (Mon–Sat)
CREATE TABLE IF NOT EXISTS challenge_trivia_questions (
    id VARCHAR(36) PRIMARY KEY,
    question_da TEXT NOT NULL,
    question_en TEXT NOT NULL,
    options_da JSON NOT NULL,
    options_en JSON NOT NULL,
    correct_option TINYINT NOT NULL,
    topic VARCHAR(32) NOT NULL,
    explain_da TEXT NOT NULL,
    explain_en TEXT NOT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    publish_date DATE NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status_date (status, publish_date)
);

-- Trivia answers — UNIQUE enforces one answer per participant per question
CREATE TABLE IF NOT EXISTS challenge_trivia_answers (
    id VARCHAR(36) PRIMARY KEY,
    participant_id VARCHAR(36) NOT NULL,
    question_id VARCHAR(36) NOT NULL,
    chosen_option TINYINT NOT NULL,
    correct TINYINT(1) NOT NULL,
    answered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_participant_question (participant_id, question_id),
    KEY idx_participant (participant_id),
    FOREIGN KEY (participant_id) REFERENCES challenge_participants(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES challenge_trivia_questions(id) ON DELETE CASCADE
);

-- Admin Settings & Operations Dashboards — Nøgler & Rotation (see add_admin_dashboards.sql)
CREATE TABLE IF NOT EXISTS admin_secret_state (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    item_key     VARCHAR(64) NOT NULL UNIQUE,
    item_type    ENUM('secret','token') NOT NULL,
    rotated_at   DATETIME NULL,
    rotated_by   VARCHAR(100) NULL,
    expires_at   DATETIME NULL
);

CREATE TABLE IF NOT EXISTS admin_audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    actor       VARCHAR(100) NOT NULL,
    action      VARCHAR(40) NOT NULL,
    item_key    VARCHAR(64) NOT NULL,
    detail      TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created (created_at)
);
