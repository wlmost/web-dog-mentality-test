<?php
/**
 * Debug-Script - Testet Konfiguration
 */

// Error Reporting aktivieren
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain; charset=utf-8');

echo "=== DEBUG INFO ===\n\n";

// 1. PHP Version
echo "PHP Version: " . phpversion() . "\n\n";

// 2. .env Datei prüfen
$envPath = __DIR__ . '/../.env';
echo ".env Pfad: $envPath\n";
echo ".env existiert: " . (file_exists($envPath) ? 'JA' : 'NEIN') . "\n";

if (file_exists($envPath)) {
    echo ".env lesbar: " . (is_readable($envPath) ? 'JA' : 'NEIN') . "\n";
    echo ".env Größe: " . filesize($envPath) . " bytes\n";
} else {
    echo "FEHLER: .env Datei fehlt!\n";
    echo "Bitte .env.example zu .env kopieren und anpassen.\n";
}

echo "\n";

// 3. config.php laden
echo "Lade config.php...\n";
try {
    require_once __DIR__ . '/config.php';
    echo "config.php geladen: OK\n\n";
} catch (Exception $e) {
    echo "FEHLER beim Laden von config.php: " . $e->getMessage() . "\n";
    exit(1);
}

// 4. Datenbankverbindung testen
echo "=== DATENBANK-TEST ===\n";
echo "DB_HOST: " . DB_HOST . "\n";
echo "DB_USER: " . DB_USER . "\n";
echo "DB_NAME: " . DB_NAME . "\n";
echo "DB_PASS: " . (empty(DB_PASS) ? '(leer)' : '***gesetzt***') . "\n\n";

echo "Teste Verbindung...\n";
try {
    $conn = getDbConnection();
    echo "Verbindung: OK ✓\n";
    echo "Server Info: " . $conn->server_info . "\n";
    $conn->close();
} catch (Exception $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== ALLE TESTS BESTANDEN ===\n";
