<?php
/**
 * Debug-Version für CSV Import
 * Zeigt detaillierte Fehlerinformationen
 */

// Error Reporting aktivieren
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

echo json_encode([
    'status' => 'debug',
    'php_version' => PHP_VERSION,
    'post_max_size' => ini_get('post_max_size'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'files_received' => isset($_FILES['file']),
    'file_info' => isset($_FILES['file']) ? [
        'name' => $_FILES['file']['name'],
        'type' => $_FILES['file']['type'],
        'size' => $_FILES['file']['size'],
        'error' => $_FILES['file']['error'],
        'tmp_name' => $_FILES['file']['tmp_name']
    ] : null,
    'method' => $_SERVER['REQUEST_METHOD'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
