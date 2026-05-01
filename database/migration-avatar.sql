-- Datenbankschema-Erweiterung für Profilbearbeitung
-- Fügt Avatar-Feld zur auth_users Tabelle hinzu

-- ===================================================================
-- Avatar-Spalte zur auth_users Tabelle hinzufügen
-- ===================================================================
ALTER TABLE {{PREFIX}}auth_users 
ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER full_name;

-- Index für schnellere Suche
CREATE INDEX idx_avatar ON {{PREFIX}}auth_users(avatar);

-- ===================================================================
-- Upload-Verzeichnis sollte außerhalb von Git sein
-- Stelle sicher, dass uploads/avatars/ existiert und beschreibbar ist
-- ===================================================================

-- Hinweis: Führe diese Migration aus, wenn die Tabelle auth_users bereits existiert
-- Falls nicht, ist das Feld bereits im Hauptschema enthalten
