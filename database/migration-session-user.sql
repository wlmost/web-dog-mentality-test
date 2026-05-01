-- Migration: User-Zuordnung für Test-Sessions
-- Fügt user_id Spalte zur test_sessions Tabelle hinzu

-- ===================================================================
-- Schritt 1: user_id Spalte hinzufügen
-- ===================================================================
ALTER TABLE test_sessions 
ADD COLUMN user_id INT DEFAULT NULL AFTER battery_id,
ADD INDEX idx_user_id (user_id);

-- Optional: Foreign Key Constraint (wenn auth_users Tabelle existiert)
-- ALTER TABLE test_sessions 
-- ADD CONSTRAINT fk_session_user 
-- FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE SET NULL;

-- ===================================================================
-- Schritt 2: Bestehende Sessions dem Admin zuordnen (optional)
-- ===================================================================
-- Falls bereits Sessions existieren, können diese dem ersten Admin zugeordnet werden
-- UPDATE test_sessions ts
-- SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1)
-- WHERE user_id IS NULL;

-- ===================================================================
-- Hinweis:
-- - user_id kann NULL sein (für alte Sessions oder System-Sessions)
-- - Normale User sehen nur Sessions wo user_id = ihre ID
-- - Admins sehen alle Sessions
-- ===================================================================
