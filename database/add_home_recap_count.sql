-- Admin-configurable count (3-10) of completed races shown in the homepage "Recap" hero
-- carousel — rotates podium results when there's no upcoming-race hero and Paddock
-- Challenges is disabled. Additive, safe to run once per environment. Range is enforced
-- at the admin form / POST-handler layer, not the DB.

ALTER TABLE settings ADD COLUMN home_recap_count INT NOT NULL DEFAULT 5;
