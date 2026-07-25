-- Feature flag to hide Paddock Challenges nav/hero surfaces (header drawer link, CP chip,
-- bottom nav tab, homepage hero + CP leaderboard preview) without touching the underlying
-- game (challenges.php, cron, content pipeline all keep working — direct links still open).
-- Additive, safe to run once per environment. Defaults to enabled; flip off per-environment
-- via the admin Settings tab.

ALTER TABLE settings ADD COLUMN challenges_enabled TINYINT(1) NOT NULL DEFAULT 1;
