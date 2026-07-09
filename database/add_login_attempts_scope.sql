-- F7: split the login-attempts rate limit into scopes (login vs mfa) and add a
-- per-account bucket alongside the existing per-IP one. Safe to run repeatedly except
-- the two additive ALTERs, which error harmlessly ("Duplicate column") on re-run.
-- MySQL has no ADD COLUMN IF NOT EXISTS, so guard manually or ignore that error.

ALTER TABLE login_attempts ADD COLUMN scope VARCHAR(10) NOT NULL DEFAULT 'login';
ALTER TABLE login_attempts ADD COLUMN account VARCHAR(255) NULL;

CREATE INDEX idx_ip_scope_time ON login_attempts (ip, scope, attempted_at);
CREATE INDEX idx_account_scope_time ON login_attempts (account, scope, attempted_at);
