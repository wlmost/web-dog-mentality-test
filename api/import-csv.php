<?php
declare(strict_types=1);
/**
 * CSV Import für Testbatterien
 * Format: Semikolon-getrennt, UTF-8
 */

// Error Reporting für Debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $conn = getDbConnection();
    
    switch ($method) {
        case 'POST':
            handleImport($conn);
            break;
        case 'GET':
            // Template-Download
            if (isset($_GET['template'])) {
                downloadTemplate();
            } else {
                sendError('Invalid request', 400);
            }
            break;
        default:
            sendError('Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log('import-csv.php Exception: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendError('Import failed', 500, $e->getMessage());
}

/**
 * CSV-Import verarbeiten
 */
function handleImport($conn) {
    try {
        // Datei-Upload prüfen
        if (!isset($_FILES['file'])) {
            sendError('No file uploaded', 400);
        }
        
        $file = $_FILES['file'];
        
        // Validierung
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'PHP extension stopped the upload'
            ];
            $errorMsg = $errorMessages[$file['error']] ?? 'Unknown upload error: ' . $file['error'];
            sendError($errorMsg, 400);
        }
        
        if ($file['size'] > 5 * 1024 * 1024) { // 5 MB max
            sendError('File too large (max 5 MB)', 400);
        }
        
        // CSV-Format prüfen
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            sendError('Invalid file type. Only .csv files allowed', 400);
        }
        
        // CSV einlesen
        $csvData = readCsvFile($file['tmp_name']);
        
        if ($csvData === null) {
            sendError('Failed to read CSV file', 500);
        }
        
        if (empty($csvData)) {
            sendError('CSV file is empty or contains no valid data rows', 400);
        }
        
        // Import durchführen
        $result = importBattery($conn, $csvData, $file['name']);
        
        sendResponse([
            'success' => true,
            'message' => 'Battery imported successfully',
            'battery_id' => $result['battery_id'],
            'battery_name' => $result['battery_name'],
            'tests_imported' => $result['tests_imported']
        ], 200);
        
    } catch (Exception $e) {
        error_log('handleImport Exception: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * CSV-Datei einlesen
 */
function readCsvFile($filepath) {
    $data = [];
    $handle = fopen($filepath, 'r');
    
    if (!$handle) {
        return null;
    }
    
    // Header-Zeile überspringen
    $header = fgetcsv($handle, 0, ';');
    
    // Datenzeilen einlesen
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        // Leere Zeilen überspringen
        if (count(array_filter($row)) === 0) {
            continue;
        }
        
        $data[] = $row;
    }
    
    fclose($handle);
    return $data;
}

/**
 * Batterie in Datenbank importieren
 */
function importBattery($conn, $csvData, $filename) {
    // Batterie-Name aus Dateiname extrahieren
    $batteryName = pathinfo($filename, PATHINFO_FILENAME);
    
    // Prüfen ob Batterie bereits existiert
    $checkStmt = $conn->prepare("SELECT id FROM " . tbl('test_batteries') . " WHERE name = ?");
    $checkStmt->bind_param('s', $batteryName);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        $checkStmt->close();
        throw new Exception("Battery with name '{$batteryName}' already exists. Please rename the file or delete the existing battery.");
    }
    $checkStmt->close();
    
    // Batterie erstellen
    $stmt = $conn->prepare("
        INSERT INTO " . tbl('test_batteries') . " (name, description, created_at)
        VALUES (?, 'Importiert aus CSV', NOW())
    ");
    $stmt->bind_param('s', $batteryName);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to create battery: ' . $stmt->error);
    }
    
    $batteryId = $conn->insert_id;
    $stmt->close();
    
    // Tests importieren
    $importedTests = 0;
    $stmt = $conn->prepare("
        INSERT INTO " . tbl('battery_tests') . " (
            battery_id, 
            test_number, 
            ocean_dimension, 
            name, 
            setting, 
            materials, 
            duration, 
            role_figurant, 
            observation_criteria, 
            rating_scale
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($csvData as $row) {
        // CSV-Format:
        // 0: Test-Nr.
        // 1: OCEAN-Dimension
        // 2: Testname
        // 3: Setting & Durchführung
        // 4: Material
        // 5: Dauer
        // 6: Rolle der Figurant:in
        // 7: Beobachtungskriterien
        // 8: Bewertungsskala
        // 9: Score (wird ignoriert beim Import)
        
        if (count($row) < 9) {
            logMessage("Skipping row with insufficient columns: " . implode(';', $row), 'WARNING');
            continue;
        }
        
        $testNumber = (int)$row[0];
        $oceanDimension = trim($row[1]);
        $testName = trim($row[2]);
        $setting = trim($row[3]);
        $material = trim($row[4]);
        $duration = trim($row[5]);
        $figurantRole = trim($row[6]);
        $observationCriteria = trim($row[7]);
        $ratingScale = trim($row[8]);
        
        // OCEAN-Dimension mapping (CSV -> DB ENUM)
        // CSV: "Extraversion (E)" -> DB: "Extraversion"
        $oceanMap = [
            'Extraversion (E)' => 'Extraversion',
            'Agreeableness (A)' => 'Verträglichkeit',
            'Conscientiousness (C)' => 'Gewissenhaftigkeit',
            'Openness (O)' => 'Offenheit',
            'Neuroticism (N)' => 'Neurotizismus',
            // Fallback für deutsche Namen
            'Extraversion' => 'Extraversion',
            'Verträglichkeit' => 'Verträglichkeit',
            'Gewissenhaftigkeit' => 'Gewissenhaftigkeit',
            'Offenheit' => 'Offenheit',
            'Neurotizismus' => 'Neurotizismus'
        ];
        
        $oceanDimension = $oceanMap[$oceanDimension] ?? $oceanDimension;
        
        $stmt->bind_param(
            'iissssssss',
            $batteryId,
            $testNumber,
            $oceanDimension,
            $testName,
            $setting,
            $material,
            $duration,
            $figurantRole,
            $observationCriteria,
            $ratingScale
        );
        
        if ($stmt->execute()) {
            $importedTests++;
        } else {
            logMessage("Failed to import test {$testNumber}: " . $stmt->error, 'ERROR');
        }
    }
    
    $stmt->close();
    
    return [
        'battery_id' => $batteryId,
        'battery_name' => $batteryName,
        'tests_imported' => $importedTests,
        'total_rows' => count($csvData)
    ];
}

/**
 * Template-Download
 */
function downloadTemplate() {
    $templateFile = __DIR__ . '/../database/template-battery-import.csv';
    
    if (!file_exists($templateFile)) {
        // Fallback: Template on-the-fly generieren
        generateTemplateOnTheFly();
        return;
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="batterie-template.csv"');
    header('Cache-Control: no-cache, must-revalidate');
    
    readfile($templateFile);
    exit;
}

/**
 * Template on-the-fly generieren falls Datei nicht existiert
 */
function generateTemplateOnTheFly() {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="batterie-template.csv"');
    header('Cache-Control: no-cache, must-revalidate');
    
    // UTF-8 BOM für Excel
    echo "\xEF\xBB\xBF";
    
    echo "Test-Nr.;OCEAN-Dimension;Testname;Setting & Durchführung (Praxisform);Material;Dauer;Rolle der Figurant:in;Beobachtungskriterien (Checkliste);Bewertungsskala (-2 bis +2, mit Ankern);Score (-2..2)\n";
    echo "1;Extraversion (E);Begrüßung & Annäherung;Die Figurant:in nähert sich dem Hund an.;-;1 min;Nähert sich ruhig dem Hund, spricht ihn freundlich an.;Anlehnung: Sucht der Hund direkten Kontakt/Körpernähe?;-2: Zieht sich stark zurück. +2: Sehr enthusiastisch;\n";
    echo "2;Extraversion (E);Spontanes Spielangebot;Die Figurant:in bietet kurz ein Spielzeug an.;Diverses Spielzeug;1 min;Zeigt das Spielzeug kurz, lädt zum Spiel ein.;Reaktion: Wie schnell reagiert der Hund?;-2: Zeigt kein Interesse. +2: Sofortige, hohe Erregung;\n";
    
    exit;
}
