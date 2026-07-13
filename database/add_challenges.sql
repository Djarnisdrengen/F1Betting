-- Paddock Challenges — guest access, Challenge Points, and three games (Rumor or Not, Duels, Trivia)
-- Run once on each environment via phpMyAdmin.

-- Challenge participants — guests and core-linked players
CREATE TABLE IF NOT EXISTS challenge_participants (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(255) NULL UNIQUE,
    core_user_id VARCHAR(36) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL UNIQUE,
    display_name VARCHAR(100) NULL,
    language CHAR(2) NOT NULL DEFAULT 'da',
    status ENUM('pending','verified') NOT NULL DEFAULT 'pending',
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
    race_id VARCHAR(36) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
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
    race_id VARCHAR(36) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
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
    p1 VARCHAR(36) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
    p2 VARCHAR(36) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
    p3 VARCHAR(36) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL,
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

-- ============================================================
-- Participant-model refinement (2026-07-12, decisions D11–D14)
-- Feature 1 (invite loop) + Feature 2 (persistent return / password).
-- Additive — safe to run after the original add_challenges.sql above.
-- ============================================================

-- Permanent participant password (D14/B4) + core-promotion request marker (D14/B6).
-- Not idempotent: on a DB that already ran this block, skip these two ALTERs.
ALTER TABLE challenge_participants ADD COLUMN password_hash VARCHAR(255) NULL;
ALTER TABLE challenge_participants ADD COLUMN promotion_requested_at DATETIME NULL;

-- Persistent return (D13/B3) — hashed, rotating device/access tokens, ~90-day expiry.
-- Raw token lives only in the emailed link / ch_access cookie; only its sha256 is stored.
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

-- Beat-my-score invites (D12/B2) — one row per challenge sent to a friend.
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

-- ============================================================
-- Invite guardrails & consent (2026-07-13, Feature 5 / REQ-801-812)
-- Additive — safe to run after the participant-model block above.
-- ============================================================

-- Suppression / opt-out / complaint / bounce list — checked before every friend send (REQ-802).
CREATE TABLE IF NOT EXISTS challenge_email_suppressions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    reason ENUM('opt_out','complaint','bounce','admin') NOT NULL DEFAULT 'opt_out',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Per-sender daily cap on friend invites (REQ-806).
ALTER TABLE settings ADD COLUMN challenge_invite_daily_cap INT NOT NULL DEFAULT 5;
