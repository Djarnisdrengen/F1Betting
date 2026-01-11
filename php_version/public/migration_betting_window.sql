-- Migration: Add betting window hours setting
-- Run this in phpMyAdmin if you already have the database set up

ALTER TABLE settings ADD COLUMN betting_window_hours INT DEFAULT 48;

-- Update existing row
UPDATE settings SET betting_window_hours = 48 WHERE id = 1;
