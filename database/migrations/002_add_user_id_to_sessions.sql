-- Migration: 002
-- Description: User-Zuordnung für Test-Sessions (Multi-User-Support)
-- Date: 2026-05-01
-- Hinweis: Spalte ist bereits in schema.sql enthalten; dieser Guard
--          verhindert den Fehler bei Neuinstallationen.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = CONCAT('{{PREFIX}}', 'test_sessions')
      AND COLUMN_NAME  = 'user_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE {{PREFIX}}test_sessions ADD COLUMN user_id INT DEFAULT NULL AFTER battery_id, ADD INDEX idx_user_id (user_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
