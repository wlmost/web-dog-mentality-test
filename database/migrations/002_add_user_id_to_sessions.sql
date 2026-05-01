-- Migration: 002
-- Description: User-Zuordnung für Test-Sessions (Multi-User-Support)
-- Date: 2026-05-01
ALTER TABLE {{PREFIX}}test_sessions
ADD COLUMN user_id INT DEFAULT NULL AFTER battery_id,
ADD INDEX idx_user_id (user_id);
