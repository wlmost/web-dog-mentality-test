-- Migration: 001
-- Description: User-Zuordnung für Hunde (Multi-User-Support)
-- Date: 2026-05-01
ALTER TABLE {{PREFIX}}dogs
ADD COLUMN user_id INT DEFAULT NULL AFTER id,
ADD INDEX idx_user_id (user_id);
