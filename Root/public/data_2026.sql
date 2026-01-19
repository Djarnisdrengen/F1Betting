-- =====================================================
-- F1 2026 SÆSON DATA - Kørere og Løb
-- Kør dette script i phpMyAdmin efter database.sql
-- =====================================================

-- Opdater settings til 2026
UPDATE settings SET app_year = '2026' WHERE id = 1;

-- Slet eksisterende kørere (hvis nogen)
DELETE FROM bets;
DELETE FROM drivers;

-- =====================================================
-- 2026 F1 KØRERE (22 kørere, 11 teams)
-- =====================================================

-- Red Bull Racing
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'Max Verstappen', 'Red Bull Racing', 1),
(UUID(), 'Isack Hadjar', 'Red Bull Racing', 20);

-- Visa Cash App Racing Bulls
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'Liam Lawson', 'Visa Cash App RB', 30),
(UUID(), 'Arvid Lindblad', 'Visa Cash App RB', 17);

-- Ferrari
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'Charles Leclerc', 'Ferrari', 16),
(UUID(), 'Lewis Hamilton', 'Ferrari', 44);

-- Mercedes
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'George Russell', 'Mercedes', 63),
(UUID(), 'Kimi Antonelli', 'Mercedes', 12);

-- McLaren
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'Lando Norris', 'McLaren', 4),
(UUID(), 'Oscar Piastri', 'McLaren', 81);

-- Aston Martin
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'Fernando Alonso', 'Aston Martin', 14),
(UUID(), 'Lance Stroll', 'Aston Martin', 18);

-- Alpine
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'Pierre Gasly', 'Alpine', 10),
(UUID(), 'Franco Colapinto', 'Alpine', 43);

-- Williams
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'Alex Albon', 'Williams', 23),
(UUID(), 'Carlos Sainz', 'Williams', 55);

-- Haas
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'Esteban Ocon', 'Haas', 31),
(UUID(), 'Oliver Bearman', 'Haas', 87);

-- Audi (tidligere Sauber)
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'Nico Hülkenberg', 'Audi', 27),
(UUID(), 'Gabriel Bortoleto', 'Audi', 5);

-- Cadillac (nyt team 2026)
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'Sergio Pérez', 'Cadillac', 11),
(UUID(), 'Valtteri Bottas', 'Cadillac', 77);

-- =====================================================
-- 2026 F1 LØB KALENDER (24 løb)
-- Tider er i CET (Central European Time)
-- =====================================================

-- Slet eksisterende løb
DELETE FROM races;

INSERT INTO races (id, name, location, race_date, race_time) VALUES
-- Runde 1-6 (CET/CEST tider)
(UUID(), 'Australian Grand Prix', 'Melbourne', '2026-03-08', '05:00'),  -- 15:00 lokal = 05:00 CET
(UUID(), 'Chinese Grand Prix', 'Shanghai', '2026-03-15', '08:00'),      -- 15:00 lokal = 08:00 CET
(UUID(), 'Japanese Grand Prix', 'Suzuka', '2026-03-29', '07:00'),       -- 14:00 lokal = 07:00 CET
(UUID(), 'Bahrain Grand Prix', 'Sakhir', '2026-04-12', '17:00'),        -- 18:00 lokal = 17:00 CEST
(UUID(), 'Saudi Arabian Grand Prix', 'Jeddah', '2026-04-19', '19:00'),  -- 20:00 lokal = 19:00 CEST
(UUID(), 'Miami Grand Prix', 'Miami', '2026-05-03', '22:00'),           -- 16:00 lokal = 22:00 CEST

-- Runde 7-12 (CEST tider)
(UUID(), 'Canadian Grand Prix', 'Montreal', '2026-05-24', '22:00'),     -- 16:00 lokal = 22:00 CEST
(UUID(), 'Monaco Grand Prix', 'Monaco', '2026-06-07', '15:00'),         -- 15:00 lokal = 15:00 CEST
(UUID(), 'Barcelona-Catalunya Grand Prix', 'Barcelona', '2026-06-14', '15:00'), -- 15:00 lokal = 15:00 CEST
(UUID(), 'Austrian Grand Prix', 'Spielberg', '2026-06-28', '15:00'),    -- 15:00 lokal = 15:00 CEST
(UUID(), 'British Grand Prix', 'Silverstone', '2026-07-05', '16:00'),   -- 15:00 lokal = 16:00 CEST
(UUID(), 'Belgian Grand Prix', 'Spa-Francorchamps', '2026-07-19', '15:00'), -- 15:00 lokal = 15:00 CEST

-- Runde 13-18 (CEST tider)
(UUID(), 'Hungarian Grand Prix', 'Budapest', '2026-07-26', '15:00'),    -- 15:00 lokal = 15:00 CEST
(UUID(), 'Dutch Grand Prix', 'Zandvoort', '2026-08-23', '15:00'),       -- 15:00 lokal = 15:00 CEST
(UUID(), 'Italian Grand Prix', 'Monza', '2026-09-06', '15:00'),         -- 15:00 lokal = 15:00 CEST
(UUID(), 'Spanish Grand Prix', 'Madrid', '2026-09-13', '15:00'),        -- 15:00 lokal = 15:00 CEST
(UUID(), 'Azerbaijan Grand Prix', 'Baku', '2026-09-26', '13:00'),       -- 15:00 lokal = 13:00 CEST
(UUID(), 'Singapore Grand Prix', 'Marina Bay', '2026-10-11', '14:00'),  -- 20:00 lokal = 14:00 CEST

-- Runde 19-24 (CET/CEST tider)
(UUID(), 'United States Grand Prix', 'Austin', '2026-10-25', '21:00'),  -- 15:00 lokal = 21:00 CEST
(UUID(), 'Mexico City Grand Prix', 'Mexico City', '2026-11-01', '21:00'),-- 14:00 lokal = 21:00 CET
(UUID(), 'Brazilian Grand Prix', 'São Paulo', '2026-11-08', '18:00'),   -- 14:00 lokal = 18:00 CET
(UUID(), 'Las Vegas Grand Prix', 'Las Vegas', '2026-11-22', '05:00'),   -- 20:00 lokal = 05:00 CET (+1 dag)
(UUID(), 'Qatar Grand Prix', 'Lusail', '2026-11-29', '17:00'),          -- 19:00 lokal = 17:00 CET
(UUID(), 'Abu Dhabi Grand Prix', 'Abu Dhabi', '2026-12-06', '14:00');   -- 17:00 lokal = 14:00 CET

// Sæt standard bettingpool størrelse for første løb
UPDATE races SET bettingpool_size = 5000 WHERE name = 'Australian Grand Prix';

-- =====================================================
-- VERIFICER DATA
-- =====================================================
SELECT '2026 Kørere:' as Info, COUNT(*) as Antal FROM drivers;
SELECT '2026 Løb:' as Info, COUNT(*) as Antal FROM races;
