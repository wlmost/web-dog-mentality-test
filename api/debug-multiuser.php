<?php
/**
 * Debug-Script für Multi-User System
 * Zeigt den aktuellen Status der Datenbank und User-Zuordnungen
 */

require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Multi-User System Debug</h1>";

try {
    $conn = getDbConnection();
    
    // 1. Prüfe ob user_id Spalte in dogs existiert
    echo "<h2>1. Schema-Check: dogs Tabelle</h2>";
    $result = $conn->query("SHOW COLUMNS FROM dogs LIKE 'user_id'");
    if ($result->num_rows > 0) {
        echo "✅ <strong style='color:green'>user_id Spalte existiert in dogs</strong><br>";
    } else {
        echo "❌ <strong style='color:red'>user_id Spalte fehlt in dogs - Migration nicht ausgeführt!</strong><br>";
        echo "<pre>ALTER TABLE dogs ADD COLUMN user_id INT DEFAULT NULL AFTER id;</pre>";
    }
    
    // 2. Prüfe ob user_id Spalte in test_sessions existiert
    echo "<h2>2. Schema-Check: test_sessions Tabelle</h2>";
    $result = $conn->query("SHOW COLUMNS FROM test_sessions LIKE 'user_id'");
    if ($result->num_rows > 0) {
        echo "✅ <strong style='color:green'>user_id Spalte existiert in test_sessions</strong><br>";
    } else {
        echo "❌ <strong style='color:red'>user_id Spalte fehlt in test_sessions - Migration nicht ausgeführt!</strong><br>";
        echo "<pre>ALTER TABLE test_sessions ADD COLUMN user_id INT DEFAULT NULL AFTER battery_id;</pre>";
    }
    
    // 3. Anzahl Hunde mit/ohne user_id
    echo "<h2>3. Hunde-Zuordnung</h2>";
    $result = $conn->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) AS with_user,
            SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) AS without_user
        FROM dogs
    ");
    $stats = $result->fetch_assoc();
    echo "Gesamt: {$stats['total']}<br>";
    echo "Mit user_id: <strong>{$stats['with_user']}</strong><br>";
    echo "Ohne user_id (NULL): <strong style='color:orange'>{$stats['without_user']}</strong><br>";
    
    if ($stats['without_user'] > 0) {
        echo "<br><strong style='color:orange'>⚠️ {$stats['without_user']} Hunde haben keine user_id!</strong><br>";
        echo "Diese werden von allen Usern gesehen.<br>";
        echo "<pre>UPDATE dogs SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1) WHERE user_id IS NULL;</pre>";
    }
    
    // 4. Hunde gruppiert nach user_id
    echo "<h2>4. Hunde pro User</h2>";
    $result = $conn->query("
        SELECT 
            COALESCE(u.username, 'Kein User (NULL)') AS username,
            d.user_id,
            COUNT(*) AS anzahl_hunde,
            GROUP_CONCAT(d.dog_name SEPARATOR ', ') AS hunde
        FROM dogs d
        LEFT JOIN auth_users u ON d.user_id = u.id
        GROUP BY d.user_id, u.username
        ORDER BY d.user_id
    ");
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>User ID</th><th>Username</th><th>Anzahl Hunde</th><th>Hunde</th></tr>";
    while ($row = $result->fetch_assoc()) {
        $style = $row['user_id'] === null ? "background-color: #fff3cd;" : "";
        echo "<tr style='$style'>";
        echo "<td>" . ($row['user_id'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
        echo "<td>" . $row['anzahl_hunde'] . "</td>";
        echo "<td>" . htmlspecialchars($row['hunde']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 5. Sessions-Zuordnung
    echo "<h2>5. Sessions-Zuordnung</h2>";
    $result = $conn->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) AS with_user,
            SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) AS without_user
        FROM test_sessions
    ");
    $stats = $result->fetch_assoc();
    echo "Gesamt: {$stats['total']}<br>";
    echo "Mit user_id: <strong>{$stats['with_user']}</strong><br>";
    echo "Ohne user_id (NULL): <strong style='color:orange'>{$stats['without_user']}</strong><br>";
    
    if ($stats['without_user'] > 0) {
        echo "<br><strong style='color:orange'>⚠️ {$stats['without_user']} Sessions haben keine user_id!</strong><br>";
        echo "<pre>UPDATE test_sessions SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1) WHERE user_id IS NULL;</pre>";
    }
    
    // 6. Alle Benutzer
    echo "<h2>6. Benutzer</h2>";
    $result = $conn->query("
        SELECT id, username, email, is_admin, is_active, created_at
        FROM auth_users
        ORDER BY is_admin DESC, id ASC
    ");
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Admin</th><th>Aktiv</th><th>Erstellt</th></tr>";
    while ($row = $result->fetch_assoc()) {
        $adminBadge = $row['is_admin'] ? "<strong style='color:red'>JA</strong>" : "Nein";
        $activeBadge = $row['is_active'] ? "Ja" : "<span style='color:red'>Nein</span>";
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
        echo "<td>" . htmlspecialchars($row['email'] ?? '-') . "</td>";
        echo "<td>" . $adminBadge . "</td>";
        echo "<td>" . $activeBadge . "</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 7. Empfohlene Aktionen
    echo "<h2>7. Empfohlene Aktionen</h2>";
    
    $needsMigration = false;
    $needsAssignment = false;
    
    $result = $conn->query("SHOW COLUMNS FROM dogs LIKE 'user_id'");
    if ($result->num_rows == 0) {
        $needsMigration = true;
        echo "❌ <strong>Migration erforderlich!</strong><br>";
        echo "<pre>
-- Führen Sie diese SQL-Befehle aus:
ALTER TABLE dogs ADD COLUMN user_id INT DEFAULT NULL AFTER id, ADD INDEX idx_user_id (user_id);
ALTER TABLE test_sessions ADD COLUMN user_id INT DEFAULT NULL AFTER battery_id, ADD INDEX idx_user_id (user_id);
</pre>";
    }
    
    $result = $conn->query("SELECT COUNT(*) as count FROM dogs WHERE user_id IS NULL");
    $row = $result->fetch_assoc();
    if ($row['count'] > 0) {
        $needsAssignment = true;
        echo "⚠️ <strong>Alte Daten ohne Zuordnung gefunden!</strong><br>";
        echo "<pre>
-- Alte Hunde und Sessions dem Admin zuordnen:
UPDATE dogs SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1) WHERE user_id IS NULL;
UPDATE test_sessions SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1) WHERE user_id IS NULL;
</pre>";
    }
    
    if (!$needsMigration && !$needsAssignment) {
        echo "✅ <strong style='color:green'>Alles korrekt konfiguriert!</strong><br>";
        echo "Falls Testuser trotzdem alle Hunde sieht:<br>";
        echo "1. Prüfen Sie ob api/dogs.php aktualisiert wurde<br>";
        echo "2. Prüfen Sie ob Session-Token korrekt gesendet wird (Browser DevTools → Network)<br>";
        echo "3. Cache leeren und neu einloggen<br>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'><strong>Fehler:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><small>Debug-Script: api/debug-multiuser.php</small></p>";
?>
