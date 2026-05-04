<?php
declare(strict_types=1);
/**
 * Datenbank-Konfiguration und Verbindung
 * 
 * Lädt Umgebungsvariablen aus .env und stellt MySQL-Verbindung her
 */

// Error Reporting (nur während Entwicklung aktiviert)
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Verhindert Ausgabe vor JSON
ini_set('log_errors', '1');

// CORS Headers für Frontend-Zugriff
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// OPTIONS-Request für Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// .env Datei laden
function loadEnv($path = __DIR__ . '/../.env') {
    if (!file_exists($path)) {
        error_log("Warnung: .env Datei nicht gefunden bei $path");
        return;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Kommentare überspringen
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // KEY=VALUE parsen
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Anführungszeichen entfernen
            $value = trim($value, '"\'');
            
            // In Umgebung setzen (nur wenn noch nicht gesetzt)
            if (!getenv($key)) {
                putenv("$key=$value");
            }
        }
    }
}

// .env laden
loadEnv();

// Lokale Konfiguration laden (installationsspezifisch, nicht im Repository)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Datenbank-Konfiguration
if (!defined('DB_HOST'))    define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
if (!defined('DB_USER'))    define('DB_USER',    getenv('DB_USER')    ?: 'root');
if (!defined('DB_PASS'))    define('DB_PASS',    getenv('DB_PASS')    ?: '');
if (!defined('DB_NAME'))    define('DB_NAME',    getenv('DB_NAME')    ?: 'dog_mentality');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('DB_PREFIX'))  define('DB_PREFIX',  getenv('DB_PREFIX')  ?: '');

// OpenAI Konfiguration
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
define('OPENAI_MODEL', getenv('OPENAI_MODEL') ?: 'gpt-4o-mini');
define('OPENAI_MAX_TOKENS', (int)(getenv('OPENAI_MAX_TOKENS') ?: 500));
define('OPENAI_TIMEOUT', (int)(getenv('OPENAI_TIMEOUT') ?: 30));

// Datenbank-Verbindung herstellen
function getDbConnection() {
    static $conn = null;
    
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            if ($conn->connect_error) {
                throw new Exception("Verbindungsfehler: " . $conn->connect_error);
            }
            
            // UTF-8 setzen
            if (!$conn->set_charset(DB_CHARSET)) {
                throw new Exception("Fehler beim Setzen von Charset: " . $conn->error);
            }
            
        } catch (Exception $e) {
            error_log("DB Connection Error: " . $e->getMessage());
            // Werfe Exception statt direkt JSON auszugeben
            throw new Exception('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
        }
    }
    
    return $conn;
}

// Hilfsfunktion: Tabellennamen mit Präfix versehen
function tbl(string $name): string {
    return DB_PREFIX . $name;
}

// Helper: JSON Response senden
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// Helper: Fehler-Response senden
function sendError($message, $statusCode = 400, $details = null) {
    // Log Error
    $errorLogPath = __DIR__ . '/../logs/error.log';
    $timestamp = date('Y-m-d H:i:s');
    $errorMsg = "[$timestamp] API ERROR ($statusCode): $message";
    if ($details) {
        $errorMsg .= " | Details: " . json_encode($details);
    }
    $errorMsg .= "\n";
    
    if (!file_exists(dirname($errorLogPath))) {
        mkdir(dirname($errorLogPath), 0755, true);
    }
    file_put_contents($errorLogPath, $errorMsg, FILE_APPEND);
    
    // Send response
    $response = ['error' => $message];
    if ($details !== null) {
        $response['details'] = $details;
    }
    sendResponse($response, $statusCode);
}

// Helper: Input-Validierung
function validateRequired($data, $requiredFields) {
    $missing = [];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            $missing[] = $field;
            continue;
        }
        
        $value = $data[$field];
        
        // Leere Strings oder null sind ungültig
        if ($value === null || $value === '') {
            $missing[] = $field;
            continue;
        }
        
        // Für Strings: trim und prüfen
        if (is_string($value) && trim($value) === '') {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        sendError('Pflichtfelder fehlen', 400, ['missing_fields' => $missing]);
    }
}

// Helper: SQL Injection Prevention
function sanitizeString($str) {
    // Konvertiere zu String falls nötig
    if (is_array($str)) {
        $str = implode(', ', $str);
    } elseif ($str === null) {
        $str = '';
    } elseif (!is_string($str)) {
        $str = (string)$str;
    }
    
    $conn = getDbConnection();
    return $conn->real_escape_string(trim($str));
}

// Helper: Integer-Validierung
function validateInteger($value, $min = null, $max = null, $fieldName = 'Wert') {
    if (!is_numeric($value) || (int)$value != $value) {
        sendError("$fieldName muss eine Ganzzahl sein");
    }
    
    $intValue = (int)$value;
    
    if ($min !== null && $intValue < $min) {
        sendError("$fieldName muss mindestens $min sein");
    }
    
    if ($max !== null && $intValue > $max) {
        sendError("$fieldName darf maximal $max sein");
    }
    
    return $intValue;
}

// Helper: Enum-Validierung
function validateEnum($value, $allowedValues, $fieldName = 'Wert') {
    if (!in_array($value, $allowedValues, true)) {
        sendError("$fieldName muss einer dieser Werte sein: " . implode(', ', $allowedValues));
    }
    return $value;
}

// Helper: JSON-Validierung
function validateJson($jsonString) {
    $data = json_decode($jsonString, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError('Ungültiges JSON-Format', 400, ['json_error' => json_last_error_msg()]);
    }
    return $data;
}

// Helper: OCEAN-Profil validieren
function validateOceanProfile($profile, $maxValue = 14) {
    $requiredKeys = ['O', 'C', 'E', 'A', 'N'];
    
    foreach ($requiredKeys as $key) {
        if (!isset($profile[$key])) {
            sendError("OCEAN-Profil unvollständig: Fehlt Key '$key'");
        }
        
        $value = validateInteger($profile[$key], -$maxValue, $maxValue, "OCEAN-Wert $key");
        $profile[$key] = $value;
    }
    
    return $profile;
}

// Helper: Request-Body als JSON parsen
function getJsonInput() {
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return [];
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError('Ungültiger JSON-Input', 400, ['error' => json_last_error_msg()]);
    }
    
    return $data;
}

// Helper: Logging
function logMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/../logs/app.log';
    
    // Log-Verzeichnis erstellen falls nicht vorhanden
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Helper: OpenAI konfiguriert?
function isOpenAIConfigured() {
    return !empty(OPENAI_API_KEY) && OPENAI_API_KEY !== '';
}

// Verbindung initialisieren (eager loading)
$conn = getDbConnection();

?>
