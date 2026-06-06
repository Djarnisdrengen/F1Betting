-- F1 Betting Database Schema for MySQL
-- Kør dette script i phpMyAdmin på Simply.com
-- Select the target database in phpMyAdmin before running — do not add CREATE DATABASE or USE here.

-- Brugere
CREATE TABLE users (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    display_name VARCHAR(100),
    role ENUM('user', 'admin') DEFAULT 'user',
    points INT DEFAULT 0,
    stars INT DEFAULT 0,
    in_competition TINYINT(1) DEFAULT 1,
    language   VARCHAR(2) NOT NULL DEFAULT 'da',
    theme      ENUM('dark','light')       NULL DEFAULT NULL,
    font_stack ENUM('system','editorial') NULL DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL
);

 
-- Kørere
CREATE TABLE drivers (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    team VARCHAR(100) NOT NULL,
    number INT NOT NULL
);

-- Løb
CREATE TABLE races (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(100) NOT NULL,
    race_date DATE NOT NULL,
    race_time TIME NOT NULL,
    quali_p1 VARCHAR(36),
    quali_p2 VARCHAR(36),
    quali_p3 VARCHAR(36),
    result_p1 VARCHAR(36),
    result_p2 VARCHAR(36),
    result_p3 VARCHAR(36),
    bettingpool_won tinyint(1),
    bettingpool_size INT,
    FOREIGN KEY (quali_p1) REFERENCES drivers(id) ON DELETE SET NULL,
    FOREIGN KEY (quali_p2) REFERENCES drivers(id) ON DELETE SET NULL,
    FOREIGN KEY (quali_p3) REFERENCES drivers(id) ON DELETE SET NULL,
    FOREIGN KEY (result_p1) REFERENCES drivers(id) ON DELETE SET NULL,
    FOREIGN KEY (result_p2) REFERENCES drivers(id) ON DELETE SET NULL,
    FOREIGN KEY (result_p3) REFERENCES drivers(id) ON DELETE SET NULL
);

-- Migration v2.3.0: qualifying timing (run once on each environment via phpMyAdmin)
-- ALTER TABLE races
--   ADD COLUMN quali_date DATE NULL AFTER race_time,
--   ADD COLUMN quali_time TIME NULL AFTER quali_date;

-- Bets
CREATE TABLE bets (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    race_id VARCHAR(36) NOT NULL,
    p1 VARCHAR(36) NOT NULL,
    p2 VARCHAR(36) NOT NULL,
    p3 VARCHAR(36) NOT NULL,
    points INT DEFAULT 0,
    is_perfect TINYINT(1) DEFAULT 0,
    placed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (race_id) REFERENCES races(id) ON DELETE CASCADE,
    FOREIGN KEY (p1) REFERENCES drivers(id),
    FOREIGN KEY (p2) REFERENCES drivers(id),
    FOREIGN KEY (p3) REFERENCES drivers(id),
    UNIQUE KEY unique_user_race (user_id, race_id)
);

-- Indstillinger
CREATE TABLE settings (
    id INT PRIMARY KEY DEFAULT 1,
    app_title VARCHAR(100) DEFAULT 'F1 Betting',
    app_year VARCHAR(10) DEFAULT '2025',
    hero_title_en VARCHAR(200) DEFAULT 'Predict the Podium',
    hero_title_da VARCHAR(200) DEFAULT 'Forudsig Podiet',
    hero_text_en TEXT,
    hero_text_da TEXT,
    points_p1 INT DEFAULT 25,
    points_p2 INT DEFAULT 18,
    points_p3 INT DEFAULT 15,
    points_wrong_pos INT DEFAULT 5,
    betting_window_hours INT DEFAULT 48,
    bet_size INT DEFAULT 10
);

-- Indsæt standard indstillinger
INSERT INTO settings (id, app_title, app_year, hero_title_en, hero_title_da, hero_text_en, hero_text_da, points_p1, points_p2, points_p3, points_wrong_pos, betting_window_hours, bet_size) VALUES 
(1, 'F1 Betting', '2025', 'Predict the Podium', 'Forudsig Podiet', 
'Compete with friends by predicting top 3 for each Grand Prix. Earn points for correct predictions.',
'Konkurrér med venner ved at forudsige top 3 for hvert Grand Prix. Optjen point for korrekte forudsigelser.',
25, 18, 15, 5, 48, 10);

-- Password reset tokens
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Rate limiting: tracks failed login attempts per IP (sliding 15-minute window)
CREATE TABLE login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, attempted_at)
);

-- Invitations (kun admin kan invitere nye brugere)
CREATE TABLE invites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    created_by VARCHAR(36) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Leaderboard rank snapshots — written after each race scoring; used to compute rank delta on leaderboard
CREATE TABLE IF NOT EXISTS leaderboard_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    race_id VARCHAR(36) NOT NULL,
    `rank` INT NOT NULL,
    points INT NOT NULL,
    scored_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_race (user_id, race_id)
) DEFAULT CHARSET=utf8mb4;

-- Service admin account — same credentials in test and live (set F1_ADMIN_EMAIL + F1_ADMIN_PASSWORD in config.php)
-- Password: UKT@Qhs!fbUH@UX0pnjLpv@yt$GHfwTyCzF3Jl1GuwS#yjIQtJaaJ6V9icV8xU2R
INSERT INTO users (id, email, password, display_name, role, in_competition, points, stars) VALUES
(UUID(), 'f1_admin@helvegpovlsen.dk', 'change-me-to-a-real-password', 'F1 Admin', 'admin', 0, 0, 0);

-- Indsæt F1 kørere 2025
INSERT INTO drivers (id, name, team, number) VALUES
(UUID(), 'Max Verstappen', 'Red Bull Racing', 1),
(UUID(), 'Sergio Perez', 'Red Bull Racing', 11),
(UUID(), 'Lewis Hamilton', 'Ferrari', 44),
(UUID(), 'Charles Leclerc', 'Ferrari', 16),
(UUID(), 'Lando Norris', 'McLaren', 4),
(UUID(), 'Oscar Piastri', 'McLaren', 81),
(UUID(), 'George Russell', 'Mercedes', 63),
(UUID(), 'Andrea Kimi Antonelli', 'Mercedes', 12),
(UUID(), 'Fernando Alonso', 'Aston Martin', 14),
(UUID(), 'Lance Stroll', 'Aston Martin', 18);
