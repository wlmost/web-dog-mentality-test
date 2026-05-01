-- Migration: User-Zuordnung für Hunde (Dogs)
-- Fügt user_id Spalte zur dogs Tabelle hinzu

-- ===================================================================
-- Schritt 1: user_id Spalte hinzufügen
-- ===================================================================
ALTER TABLE {{PREFIX}}dogs 
ADD COLUMN user_id INT DEFAULT NULL AFTER id,
ADD INDEX idx_user_id (user_id);

-- Optional: Foreign Key Constraint (wenn auth_users Tabelle existiert)
-- ALTER TABLE {{PREFIX}}dogs 
-- ADD CONSTRAINT fk_dog_user 
-- FOREIGN KEY (user_id) REFERENCES {{PREFIX}}auth_users(id) ON DELETE SET NULL;

-- ===================================================================
-- Schritt 2: Bestehende Hunde dem Admin zuordnen (optional)
-- ===================================================================
-- Falls bereits Hunde existieren, können diese dem ersten Admin zugeordnet werden
-- UPDATE {{PREFIX}}dogs
-- SET user_id = (SELECT id FROM {{PREFIX}}auth_users WHERE is_admin = TRUE LIMIT 1)
-- WHERE user_id IS NULL;

-- ===================================================================
-- Hinweis:
-- - user_id kann NULL sein (für alte Hunde ohne Zuordnung)
-- - Normale User sehen nur Hunde wo user_id = ihre ID
-- - Admins sehen alle Hunde
-- - Sessions sind automatisch gefiltert durch ihre Hunde-Zuordnung
-- ===================================================================
