<?php
/**
 * Test: Simuliere AI Assessment Aufruf mit Debug-Output
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

// Simuliere die Daten die vom Frontend kommen
$testData = [
    'ist_profile' => [
        'O' => 5,
        'C' => 8,
        'E' => 6,
        'A' => 7,
        'N' => -3
    ],
    'ideal_profile' => [
        'O' => 7,
        'C' => 10,
        'E' => 8,
        'A' => 9,
        'N' => -5
    ],
    'dog_data' => [
        'dog_name' => 'TestHund',
        'breed' => 'Labrador',
        'intended_use' => 'Familienhund'
    ]
];

echo json_encode([
    'status' => 'Test gestartet',
    'test_data' => $testData,
    'steps' => []
], JSON_PRETTY_PRINT);

echo "\n\n=== SCHRITT 1: validateOceanProfile ===\n";
try {
    $istProfile = validateOceanProfile($testData['ist_profile']);
    echo "✓ ist_profile validiert\n";
} catch (Exception $e) {
    echo "✗ Fehler: " . $e->getMessage() . "\n";
    exit;
}

echo "\n=== SCHRITT 2: dog_data verarbeiten ===\n";
$dogData = $testData['dog_data'];
echo "dog_name type: " . gettype($dogData['dog_name']) . "\n";
echo "dog_name value: " . var_export($dogData['dog_name'], true) . "\n";

echo "\n=== SCHRITT 3: sanitizeString testen ===\n";
try {
    $dogName = sanitizeString($dogData['dog_name']);
    echo "✓ dog_name sanitized: $dogName\n";
    
    $breed = sanitizeString($dogData['breed']);
    echo "✓ breed sanitized: $breed\n";
    
    $intendedUse = sanitizeString($dogData['intended_use']);
    echo "✓ intended_use sanitized: $intendedUse\n";
    
    echo "\n✓ ALLE TESTS BESTANDEN\n";
} catch (Exception $e) {
    echo "✗ Fehler bei sanitizeString: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
?>
