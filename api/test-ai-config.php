<?php
/**
 * Test-Endpunkt: OpenAI Konfiguration prüfen
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$errors = [];
$warnings = [];
$info = [];

// 1. .env Datei prüfen
$envPath = __DIR__ . '/../.env';
$info['env_path'] = $envPath;
$info['env_exists'] = file_exists($envPath);

if (!file_exists($envPath)) {
    $errors[] = '.env Datei nicht gefunden';
} else {
    $info['env_readable'] = is_readable($envPath);
}

// 2. config.php laden
try {
    require_once 'config.php';
    $info['config_loaded'] = true;
} catch (Exception $e) {
    $errors[] = 'config.php Fehler: ' . $e->getMessage();
    $info['config_loaded'] = false;
}

// 3. Konstanten prüfen
$info['openai_key_defined'] = defined('OPENAI_API_KEY');
$info['openai_key_empty'] = defined('OPENAI_API_KEY') && empty(OPENAI_API_KEY);
$info['openai_key_length'] = defined('OPENAI_API_KEY') ? strlen(OPENAI_API_KEY) : 0;
$info['openai_key_starts_with'] = defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY) 
    ? substr(OPENAI_API_KEY, 0, 7) . '...' 
    : 'N/A';

if (defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY)) {
    if (!str_starts_with(OPENAI_API_KEY, 'sk-')) {
        $warnings[] = 'OpenAI API Key hat falsches Format (sollte mit "sk-" beginnen)';
    }
    if (strlen(OPENAI_API_KEY) < 40) {
        $warnings[] = 'OpenAI API Key ist zu kurz';
    }
} else {
    $errors[] = 'OPENAI_API_KEY ist nicht gesetzt oder leer';
}

$info['openai_model'] = defined('OPENAI_MODEL') ? OPENAI_MODEL : 'N/A';
$info['openai_max_tokens'] = defined('OPENAI_MAX_TOKENS') ? OPENAI_MAX_TOKENS : 'N/A';
$info['openai_timeout'] = defined('OPENAI_TIMEOUT') ? OPENAI_TIMEOUT : 'N/A';

// 4. cURL verfügbar?
$info['curl_available'] = function_exists('curl_init');
if (!function_exists('curl_init')) {
    $errors[] = 'cURL Erweiterung nicht verfügbar';
}

// 5. Funktionen prüfen
$functions = ['validateOceanProfile', 'callOpenAI', 'sendError', 'getJsonInput'];
foreach ($functions as $func) {
    $info['function_' . $func] = function_exists($func);
    if (!function_exists($func)) {
        $errors[] = "Funktion $func nicht definiert";
    }
}

// Ergebnis
$result = [
    'status' => empty($errors) ? 'OK' : 'ERROR',
    'timestamp' => date('Y-m-d H:i:s'),
    'errors' => $errors,
    'warnings' => $warnings,
    'info' => $info
];

if (empty($errors)) {
    http_response_code(200);
} else {
    http_response_code(500);
}

echo json_encode($result, JSON_PRETTY_PRINT);
?>
