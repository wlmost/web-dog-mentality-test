-- Migration: 003
-- Description: Avatar-Feld für Benutzerprofile
-- Date: 2026-05-01
-- Hinweis: Spalte ist bereits in schema-auth.sql enthalten; dieser Guard
--          verhindert den Fehler bei Neuinstallationen.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = CONCAT('{{PREFIX}}', 'auth_users')
      AND COLUMN_NAME  = 'avatar');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE {{PREFIX}}auth_users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER full_name',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = CONCAT('{{PREFIX}}', 'auth_users')
      AND INDEX_NAME   = 'idx_avatar');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_avatar ON {{PREFIX}}auth_users(avatar)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

