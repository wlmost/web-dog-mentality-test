<?php
/**
 * Test: Simuliere echten AI Assessment Request
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Simuliere POST Request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['action'] = 'assessment';

// Echte Test-Daten die vom Frontend kommen könnten
$requestData = [
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
    'owner_profile' => null,
    'dog_data' => [
        'dog_name' => 'Bella',
        'breed' => 'Labrador Retriever',
        'intended_use' => 'Familienhund'
    ]
];

// Simuliere JSON Input
file_put_contents('php://temp', json_encode($requestData));

echo "=== Test wird gestartet ===\n\n";
echo "Request Data:\n";
echo json_encode($requestData, JSON_PRETTY_PRINT);
echo "\n\n=== Include ai.php ===\n\n";

// Versuche ai.php zu laden
try {
    // Buffer output
    ob_start();
    
    // Fake stdin für getJsonInput
    $GLOBALS['HTTP_RAW_POST_DATA'] = json_encode($requestData);
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    
    // Manually set the input for file_get_contents('php://input')
    stream_wrapper_unregister("php");
    stream_wrapper_register("php", "MockPhpStream");
    
    class MockPhpStream {
        public $context;
        private static $data;
        
        public static function setData($data) {
            self::$data = $data;
        }
        
        public function stream_open($path) {
            return true;
        }
        
        public function stream_read($count) {
            $data = self::$data;
            self::$data = '';
            return $data;
        }
        
        public function stream_eof() {
            return true;
        }
        
        public function stream_stat() {
            return [];
        }
    }
    
    MockPhpStream::setData(json_encode($requestData));
    
    include 'ai.php';
    
    $output = ob_get_clean();
    echo $output;
    
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString())
    ], JSON_PRETTY_PRINT);
}
?>
