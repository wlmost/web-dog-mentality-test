<?php
/**
 * Test: Prüfe config.php sanitizeString Version
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

// Test sanitizeString mit verschiedenen Inputs
$tests = [
    'string' => 'TestString',
    'null' => null,
    'array' => ['Test', 'Array'],
    'number' => 123,
    'empty' => ''
];

$results = [];

foreach ($tests as $name => $input) {
    try {
        $output = sanitizeString($input);
        $results[$name] = [
            'input_type' => gettype($input),
            'input_value' => $input,
            'output' => $output,
            'success' => true
        ];
    } catch (Throwable $e) {
        $results[$name] = [
            'input_type' => gettype($input),
            'input_value' => $input,
            'error' => $e->getMessage(),
            'success' => false
        ];
    }
}

// Prüfe ob sanitizeString die neue Version ist
$reflection = new ReflectionFunction('sanitizeString');
$filename = $reflection->getFileName();
$startLine = $reflection->getStartLine();
$endLine = $reflection->getEndLine();
$length = $endLine - $startLine;

$file = file($filename);
$functionCode = implode('', array_slice($file, $startLine - 1, $length + 1));

$hasArrayCheck = strpos($functionCode, 'is_array') !== false;
$hasNullCheck = strpos($functionCode, 'null') !== false;

echo json_encode([
    'sanitizeString_info' => [
        'file' => basename($filename),
        'line_start' => $startLine,
        'line_end' => $endLine,
        'has_array_check' => $hasArrayCheck,
        'has_null_check' => $hasNullCheck,
        'code_length' => strlen($functionCode)
    ],
    'test_results' => $results,
    'conclusion' => $hasArrayCheck && $hasNullCheck 
        ? 'config.php hat die NEUE Version (mit Array/Null-Checks)' 
        : 'config.php hat die ALTE Version (ohne Array/Null-Checks) - BITTE HOCHLADEN!'
], JSON_PRETTY_PRINT);
?>
