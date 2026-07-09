-- F12: track when a user's password last changed so an active session established
-- before that moment can be told apart from one established after it. getCurrentUser()
-- compares this against a per-session stamp and logs out any session that's now stale —
-- i.e. changing your password (or an admin/reset-link password change) revokes every
-- other session for that account. NULL means "never changed since this column existed",
-- which getCurrentUser() treats as never-stale.

ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL;
