<?php
/**
 * Test: AI Assessment Endpoint direkter Test
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Simuliere POST-Request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['action'] = 'assessment';

// Test-Daten
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
    'owner_profile' => [
        'O' => 6,
        'C' => 7,
        'E' => 5,
        'A' => 8,
        'N' => -2
    ],
    'dog_data' => [
        'dog_name' => 'TestHund',
        'breed' => 'Labrador',
        'intended_use' => 'Familienhund'
    ]
];

// Simuliere JSON-Input
$_SERVER['CONTENT_TYPE'] = 'application/json';
file_put_contents('php://input', json_encode($testData));

echo json_encode([
    'status' => 'Test vorbereitet',
    'info' => 'Rufen Sie nun ai.php direkt auf oder prüfen Sie Logs',
    'test_data' => $testData,
    'next_step' => 'Versuchen Sie den echten API-Aufruf nochmal'
]);
?>
