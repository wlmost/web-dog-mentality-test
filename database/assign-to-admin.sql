-- Bestehende Daten dem Admin zuordnen
-- Dieses Script ordnet alle Hunde und Sessions ohne user_id dem ersten Admin zu
-- Führen Sie es aus, damit Testuser nur noch ihre eigenen Daten sehen

-- ===================================================================
-- WICHTIG: Führen Sie dieses Script nur einmal aus!
-- ===================================================================

-- Schritt 1: Alle Hunde ohne user_id dem Admin zuordnen
UPDATE dogs
SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1)
WHERE user_id IS NULL;

-- Schritt 2: Alle Sessions ohne user_id dem Admin zuordnen
UPDATE test_sessions
SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1)
WHERE user_id IS NULL;

-- ===================================================================
-- Ergebnis prüfen:
-- ===================================================================

-- Alle Hunde sollten nun eine user_id haben
SELECT 
    COUNT(*) AS total_dogs,
    COUNT(user_id) AS dogs_with_user,
    COUNT(*) - COUNT(user_id) AS dogs_without_user
FROM dogs;

-- Alle Sessions sollten nun eine user_id haben
SELECT 
    COUNT(*) AS total_sessions,
    COUNT(user_id) AS sessions_with_user,
    COUNT(*) - COUNT(user_id) AS sessions_without_user
FROM test_sessions;

-- ===================================================================
-- Hinweis:
-- Nach diesem Script sieht der Testuser nur noch seine eigenen Daten.
-- Der Admin sieht weiterhin alle Daten (inkl. der alten zugeordneten).
-- ===================================================================
