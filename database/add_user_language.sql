-- Add preferred language to users table.
-- Authenticated users' UI language is driven by this column instead of the session only.
ALTER TABLE users
    ADD COLUMN language VARCHAR(2) NOT NULL DEFAULT 'da'
    AFTER in_competition;
