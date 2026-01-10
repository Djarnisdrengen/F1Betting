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
-- Runde 1-6 (CET tider)
(UUID(), 'Australian Grand Prix', 'Melbourne', '2026-03-08', '06:00'),
(UUID(), 'Chinese Grand Prix', 'Shanghai', '2026-03-15', '08:00'),
(UUID(), 'Japanese Grand Prix', 'Suzuka', '2026-03-29', '07:00'),
(UUID(), 'Bahrain Grand Prix', 'Sakhir', '2026-04-12', '17:00'),
(UUID(), 'Saudi Arabian Grand Prix', 'Jeddah', '2026-04-19', '19:00'),
(UUID(), 'Miami Grand Prix', 'Miami', '2026-05-03', '22:00'),

-- Runde 7-12 (CET tider)
(UUID(), 'Canadian Grand Prix', 'Montreal', '2026-05-24', '20:00'),
(UUID(), 'Monaco Grand Prix', 'Monaco', '2026-06-07', '15:00'),
(UUID(), 'Barcelona-Catalunya Grand Prix', 'Barcelona', '2026-06-14', '15:00'),
(UUID(), 'Austrian Grand Prix', 'Spielberg', '2026-06-28', '15:00'),
(UUID(), 'British Grand Prix', 'Silverstone', '2026-07-05', '16:00'),
(UUID(), 'Belgian Grand Prix', 'Spa-Francorchamps', '2026-07-19', '15:00'),

-- Runde 13-18 (CET tider)
(UUID(), 'Hungarian Grand Prix', 'Budapest', '2026-07-26', '15:00'),
(UUID(), 'Dutch Grand Prix', 'Zandvoort', '2026-08-23', '15:00'),
(UUID(), 'Italian Grand Prix', 'Monza', '2026-09-06', '15:00'),
(UUID(), 'Spanish Grand Prix', 'Madrid', '2026-09-13', '15:00'),
(UUID(), 'Azerbaijan Grand Prix', 'Baku', '2026-09-26', '14:00'),
(UUID(), 'Singapore Grand Prix', 'Marina Bay', '2026-10-11', '14:00'),

-- Runde 19-24 (CET tider)
(UUID(), 'United States Grand Prix', 'Austin', '2026-10-25', '21:00'),
(UUID(), 'Mexico City Grand Prix', 'Mexico City', '2026-11-01', '21:00'),
(UUID(), 'Brazilian Grand Prix', 'São Paulo', '2026-11-08', '19:00'),
(UUID(), 'Las Vegas Grand Prix', 'Las Vegas', '2026-11-21', '07:00'),
(UUID(), 'Qatar Grand Prix', 'Lusail', '2026-11-29', '17:00'),
(UUID(), 'Abu Dhabi Grand Prix', 'Abu Dhabi', '2026-12-06', '14:00');

-- =====================================================
-- VERIFICER DATA
-- =====================================================
SELECT '2026 Kørere:' as Info, COUNT(*) as Antal FROM drivers;
SELECT '2026 Løb:' as Info, COUNT(*) as Antal FROM races;
