-- DB-backed PHP sessions (replaces file-based sessions, which weren't reliably
-- shared/persistent on live hosting). See public/includes/session-handler.php.
CREATE TABLE IF NOT EXISTS sessions (
    id            VARCHAR(128) PRIMARY KEY,
    data          MEDIUMTEXT   NOT NULL DEFAULT '',
    last_activity INT UNSIGNED NOT NULL,
    INDEX idx_last_activity (last_activity)
);
