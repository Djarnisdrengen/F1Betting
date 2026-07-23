-- Admin Settings & Operations Dashboards — Nøgler & Rotation (Feature 3).
-- Additive, safe to run once per environment. No `env` column: each environment's own
-- database only ever holds that environment's own rows (see
-- epics/Admin settings and dashboards/plan.md decision 4/6).

-- One row per tracked token/secret. Static display config (name, icon, policy days, mode)
-- lives in code (public/includes/admin-dashboards/nogler-rotation-lib.php), not here — this
-- table only holds the state that actually changes over time.
CREATE TABLE IF NOT EXISTS admin_secret_state (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    item_key     VARCHAR(64) NOT NULL UNIQUE,
    item_type    ENUM('secret','token') NOT NULL,
    rotated_at   DATETIME NULL,
    rotated_by   VARCHAR(100) NULL,
    expires_at   DATETIME NULL
);

-- Append-only audit trail for token-record / secret-record / secret-rotate actions.
CREATE TABLE IF NOT EXISTS admin_audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    actor       VARCHAR(100) NOT NULL,
    action      VARCHAR(40) NOT NULL,
    item_key    VARCHAR(64) NOT NULL,
    detail      TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created (created_at)
);
