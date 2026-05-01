<?php
declare(strict_types=1);
/**
 * Database Connection Test
 * Hilft beim Debugging von Verbindungsproblemen
 */

// Error Reporting aktivieren
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Konfiguration und tbl()-Funktion laden
require_once __DIR__ . '/config.php';

echo "<h1>Database Connection Test</h1>";

// Konfiguration anzeigen
$db_pass_display = defined('DB_PASS') && DB_PASS !== '' ? '***GESETZT***' : '(leer)';
echo "<h2>Konfiguration</h2>";
echo "<ul>";
echo "<li><strong>DB_HOST:</strong> " . htmlspecialchars(DB_HOST) . "</li>";
echo "<li><strong>DB_USER:</strong> " . htmlspecialchars(DB_USER) . "</li>";
echo "<li><strong>DB_PASS:</strong> $db_pass_display</li>";
echo "<li><strong>DB_NAME:</strong> " . htmlspecialchars(DB_NAME) . "</li>";
echo "<li><strong>DB_PREFIX:</strong> " . htmlspecialchars(DB_PREFIX) . "</li>";
echo "</ul>";

// Verbindung testen
echo "<h2>Verbindungstest</h2>";

try {
    $conn = getDbConnection();
    echo "<p style='color: green;'><strong>SUCCESS:</strong> Datenbankverbindung erfolgreich!</p>";
    echo "<p style='color: green;'><strong>OK:</strong> UTF-8 Charset gesetzt</p>";

    // Tabellen prüfen
    echo "<h2>Tabellen-Check</h2>";
    $tables = ['auth_users', 'auth_sessions', 'auth_logs', 'dogs', 'test_batteries', 'battery_tests', 'test_sessions', 'test_results'];

    foreach ($tables as $table) {
        $prefixedTable = tbl($table);
        $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($prefixedTable) . "'");
        if ($result && $result->num_rows > 0) {
            echo "<p style='color: green;'>&#10003; Tabelle <strong>" . htmlspecialchars($prefixedTable) . "</strong> existiert</p>";
        } else {
            echo "<p style='color: red;'>&#10007; Tabelle <strong>" . htmlspecialchars($prefixedTable) . "</strong> fehlt!</p>";
        }
    }

    // auth_users prüfen
    echo "<h2>Admin-User Check</h2>";
    $stmt = $conn->prepare("SELECT id, username, is_admin, is_active FROM " . tbl('auth_users') . " WHERE username = ?");
    $adminUser = 'admin';
    $stmt->bind_param('s', $adminUser);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $admin = $result->fetch_assoc();
        echo "<p style='color: green;'><strong>OK:</strong> Admin-User gefunden</p>";
        echo "<ul>";
        echo "<li><strong>ID:</strong> " . htmlspecialchars((string)$admin['id']) . "</li>";
        echo "<li><strong>Username:</strong> " . htmlspecialchars($admin['username']) . "</li>";
        echo "<li><strong>is_admin:</strong> " . ($admin['is_admin'] ? 'JA' : 'NEIN') . "</li>";
        echo "<li><strong>is_active:</strong> " . ($admin['is_active'] ? 'JA' : 'NEIN') . "</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: red;'><strong>FEHLER:</strong> Admin-User nicht gefunden!</p>";
    }
    $stmt->close();

} catch (Exception $e) {
    echo "<p style='color: red;'><strong>FEHLER:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr><p><small>Test abgeschlossen: " . htmlspecialchars(date('Y-m-d H:i:s')) . "</small></p>";
