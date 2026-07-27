-- Real expiry & rotation for content-topup (epics/Real expiry & rotation for content-topup/).
-- Adds an 'archived' status to Rumor or Not items and Trivia questions so stale published
-- content-topup output can roll off without ever being deleted (challenge_answers /
-- challenge_trivia_answers / challenge_points stay intact — see feature-1's REQ-601).
-- Purely additive: every existing 'draft'/'published' row and query is unaffected; nothing
-- reads status='archived' until the archival engine (public/includes/challenges.php) ships.
-- Additive, safe to run once per environment.

ALTER TABLE challenge_items MODIFY status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft';
ALTER TABLE challenge_trivia_questions MODIFY status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft';
