-- =====================================================
-- MIGRATION: Tilføj point konfiguration til settings
-- Kør dette script i phpMyAdmin hvis du allerede har en database
-- =====================================================

-- Tilføj nye kolonner til settings tabellen
ALTER TABLE settings 
ADD COLUMN IF NOT EXISTS points_p1 INT DEFAULT 25,
ADD COLUMN IF NOT EXISTS points_p2 INT DEFAULT 18,
ADD COLUMN IF NOT EXISTS points_p3 INT DEFAULT 15,
ADD COLUMN IF NOT EXISTS points_wrong_pos INT DEFAULT 5;

-- Opdater eksisterende række med standardværdier
UPDATE settings SET 
    points_p1 = COALESCE(points_p1, 25),
    points_p2 = COALESCE(points_p2, 18),
    points_p3 = COALESCE(points_p3, 15),
    points_wrong_pos = COALESCE(points_wrong_pos, 5)
WHERE id = 1;

-- Verificer ændringer
SELECT points_p1, points_p2, points_p3, points_wrong_pos FROM settings WHERE id = 1;
