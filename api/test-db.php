<?php
/**
 * Database Connection Test
 * Hilft beim Debugging von Verbindungsproblemen
 */

// Error Reporting aktivieren
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Database Connection Test</h1>";

// .env laden
function loadEnv($path = __DIR__ . '/../.env') {
    if (!file_exists($path)) {
        echo "<p style='color: red;'><strong>FEHLER:</strong> .env Datei nicht gefunden bei: $path</p>";
        return false;
    }
    
    echo "<p style='color: green;'><strong>OK:</strong> .env Datei gefunden</p>";
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, '"\'');
            
            if (!getenv($key)) {
                putenv("$key=$value");
            }
        }
    }
    return true;
}

loadEnv();

// DB Konfiguration anzeigen
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ? '***GESETZT***' : '(leer)';
$db_name = getenv('DB_NAME') ?: 'dog_mentality';

echo "<h2>Konfiguration</h2>";
echo "<ul>";
echo "<li><strong>DB_HOST:</strong> $db_host</li>";
echo "<li><strong>DB_USER:</strong> $db_user</li>";
echo "<li><strong>DB_PASS:</strong> $db_pass</li>";
echo "<li><strong>DB_NAME:</strong> $db_name</li>";
echo "</ul>";

// Verbindung testen
echo "<h2>Verbindungstest</h2>";

try {
    $conn = new mysqli($db_host, $db_user, getenv('DB_PASS') ?: '', $db_name);
    
    if ($conn->connect_error) {
        throw new Exception("Verbindungsfehler: " . $conn->connect_error);
    }
    
    echo "<p style='color: green;'><strong>SUCCESS:</strong> Datenbankverbindung erfolgreich!</p>";
    
    // Charset setzen
    if (!$conn->set_charset('utf8mb4')) {
        throw new Exception("Fehler beim Setzen von Charset: " . $conn->error);
    }
    
    echo "<p style='color: green;'><strong>OK:</strong> UTF-8 Charset gesetzt</p>";
    
    // Tabellen prüfen
    echo "<h2>Tabellen-Check</h2>";
    $tables = ['auth_users', 'auth_sessions', 'auth_logs', 'dogs', 'test_batteries', 'battery_tests', 'test_sessions', 'test_results'];
    
    foreach ($tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result->num_rows > 0) {
            echo "<p style='color: green;'>✓ Tabelle <strong>$table</strong> existiert</p>";
        } else {
            echo "<p style='color: red;'>✗ Tabelle <strong>$table</strong> fehlt!</p>";
        }
    }
    
    // auth_users prüfen
    echo "<h2>Admin-User Check</h2>";
    $result = $conn->query("SELECT id, username, is_admin, is_active FROM auth_users WHERE username='admin'");
    
    if ($result && $result->num_rows > 0) {
        $admin = $result->fetch_assoc();
        echo "<p style='color: green;'><strong>OK:</strong> Admin-User gefunden</p>";
        echo "<ul>";
        echo "<li><strong>ID:</strong> " . $admin['id'] . "</li>";
        echo "<li><strong>Username:</strong> " . $admin['username'] . "</li>";
        echo "<li><strong>is_admin:</strong> " . ($admin['is_admin'] ? 'JA' : 'NEIN') . "</li>";
        echo "<li><strong>is_active:</strong> " . ($admin['is_active'] ? 'JA' : 'NEIN') . "</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: red;'><strong>FEHLER:</strong> Admin-User nicht gefunden!</p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>FEHLER:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr><p><small>Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
