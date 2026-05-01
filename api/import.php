<?php
declare(strict_types=1);
/**
 * Excel Import für Testbatterien
 * Verwendet PhpSpreadsheet für .xlsx Dateien
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Prüfe ob PhpSpreadsheet verfügbar ist
if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    sendError('PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet', 500);
}

use PhpOffice\PhpSpreadsheet\IOFactory;

$method = $_SERVER['REQUEST_METHOD'];
$conn = getDbConnection();

try {
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
    logMessage('ERROR', 'import.php: ' . $e->getMessage());
    sendError('Import failed', 500, $e->getMessage());
}

/**
 * Excel-Import verarbeiten
 */
function handleImport($conn) {
    // Datei-Upload prüfen
    if (!isset($_FILES['file'])) {
        sendError('No file uploaded', 400);
    }
    
    $file = $_FILES['file'];
    
    // Validierung
    if ($file['error'] !== UPLOAD_ERR_OK) {
        sendError('Upload error: ' . $file['error'], 400);
    }
    
    $allowedExtensions = ['xlsx', 'xls'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($fileExtension, $allowedExtensions)) {
        sendError('Invalid file type. Only .xlsx and .xls allowed', 400);
    }
    
    $maxFileSize = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $maxFileSize) {
        sendError('File too large. Maximum 5 MB', 400);
    }
    
    try {
        // Excel laden
        $spreadsheet = IOFactory::load($file['tmp_name']);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Header-Zeile lesen (Zeile 1) - erweitert auf 10 Spalten
        $headerRow = $worksheet->rangeToArray('A1:J1')[0];
        
        // Header validieren (Original-Format aus dog-mentality-test)
        // Flexible Validierung: nur erste 3 Spalten sind Pflicht
        if ($headerRow[0] !== 'Test-Nr.' || $headerRow[1] !== 'OCEAN-Dimension' || $headerRow[2] !== 'Testname') {
            sendError('Invalid Excel format. First 3 columns must be: Test-Nr., OCEAN-Dimension, Testname', 400, [
                'found' => array_slice($headerRow, 0, 3),
                'expected' => ['Test-Nr.', 'OCEAN-Dimension', 'Testname']
            ]);
        }
        
        // Batteriename aus Dateinamen extrahieren
        $batteryName = pathinfo($file['name'], PATHINFO_FILENAME);
        
        // Beschreibung aus Excel-Inhalt generieren
        $totalRows = $worksheet->getHighestRow();
        $batteryDescription = "Importiert am " . date('Y-m-d H:i:s') . " - " . ($totalRows - 1) . " Tests";
        
        // Tests einlesen (ab Zeile 2)
        $tests = [];
        $rowIndex = 2;
        $validDimensions = ['Offenheit', 'Gewissenhaftigkeit', 'Extraversion', 'Verträglichkeit', 'Neurotizismus'];
        
        while (true) {
            $row = $worksheet->rangeToArray("A{$rowIndex}:J{$rowIndex}")[0];
            
            // Leere Zeile = Ende
            if (empty($row[0]) && empty($row[1]) && empty($row[2])) {
                break;
            }
            
            // Spalten-Mapping (Original-Format)
            $testNumber = (int)$row[0];                    // Test-Nr.
            $oceanDimension = trim($row[1]);                // OCEAN-Dimension
            $testName = trim($row[2]);                      // Testname
            $setting = trim($row[3] ?? '');                 // Setting & Durchführung
            $material = trim($row[4] ?? '');                // Material
            $duration = trim($row[5] ?? '');                // Dauer
            $role = trim($row[6] ?? '');                    // Rolle der Figurant:in
            $criteria = trim($row[7] ?? '');                // Beobachtungskriterien
            $scale = trim($row[8] ?? '');                   // Bewertungsskala
            
            // Beschreibung zusammenbauen
            $descriptionParts = [];
            if ($setting) $descriptionParts[] = "Setting: " . $setting;
            if ($material) $descriptionParts[] = "Material: " . $material;
            if ($duration) $descriptionParts[] = "Dauer: " . $duration;
            if ($role) $descriptionParts[] = "Figurant: " . $role;
            if ($criteria) $descriptionParts[] = "Kriterien: " . $criteria;
            if ($scale) $descriptionParts[] = "Skala: " . $scale;
            
            $description = implode(" | ", $descriptionParts);
            
            // Max. Wert aus Skala extrahieren (z.B. "-2 bis +2" -> 2)
            $maxValue = 5; // Default für -2 bis +2 Skala
            if (preg_match('/[-+]?(\d+)/', $scale, $matches)) {
                $maxValue = (int)$matches[1];
            }
            
            // Validierung
            if ($testNumber < 1) {
                sendError("Invalid test number in row {$rowIndex}: {$row[0]}", 400);
            }
            
            if (empty($testName)) {
                sendError("Empty test name in row {$rowIndex}", 400);
            }
            
            if (!in_array($oceanDimension, $validDimensions)) {
                sendError("Invalid OCEAN dimension in row {$rowIndex}: '{$oceanDimension}'. Expected: " . 
                    implode(', ', $validDimensions), 400);
            }
            
            // Duplikat-Prüfung
            foreach ($tests as $existing) {
                if ($existing['test_number'] === $testNumber) {
                    sendError("Duplicate test number: {$testNumber}", 400);
                }
            }
            
            $tests[] = [
                'test_number' => $testNumber,
                'test_name' => $testName,
                'ocean_dimension' => $oceanDimension,
                'max_value' => $maxValue,
                'description' => $description
            ];
            
            $rowIndex++;
            
            // Sicherheits-Limit
            if ($rowIndex > 1000) {
                sendError('Too many rows. Maximum 1000 tests per battery', 400);
            }
        }
        
        if (empty($tests)) {
            sendError('No tests found in Excel file', 400);
        }
        
        // In Datenbank importieren
        $conn->begin_transaction();
        
        try {
            // Batterie erstellen
            $stmt = $conn->prepare("
                INSERT INTO " . tbl('test_batteries') . " (name, description)
                VALUES (?, ?)
            ");
            $stmt->bind_param('ss', $batteryName, $batteryDescription);
            $stmt->execute();
            
            $batteryId = $conn->insert_id;
            
            // Tests einfügen
            $stmt = $conn->prepare("
                INSERT INTO " . tbl('battery_tests') . "
                (battery_id, test_number, test_name, ocean_dimension, max_value)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($tests as $test) {
                $stmt->bind_param(
                    'iissi',
                    $batteryId,
                    $test['test_number'],
                    $test['test_name'],
                    $test['ocean_dimension'],
                    $test['max_value']
                );
                $stmt->execute();
            }
            
            $conn->commit();
            
            logMessage('INFO', "Battery imported from Excel: ID={$batteryId}, Name={$batteryName}, Tests=" . count($tests));
            
            sendResponse([
                'message' => 'Battery imported successfully',
                'battery_id' => $batteryId,
                'battery_name' => $batteryName,
                'test_count' => count($tests)
            ], 201);
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
    } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
        sendError('Failed to read Excel file', 400, $e->getMessage());
    }
}

/**
 * Excel-Template herunterladen
 */
function downloadTemplate() {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Testbatterie');
    
    // Metadaten (Zeile 0 - unsichtbar in normaler Ansicht)
    $sheet->setCellValue('A0', 'Testbatterie Name');
    $sheet->setCellValue('B0', 'Beschreibung der Testbatterie (optional)');
    
    // Header (Zeile 1)
    $sheet->setCellValue('A1', 'Test Nr.');
    $sheet->setCellValue('B1', 'Testname');
    $sheet->setCellValue('C1', 'OCEAN Dimension');
    $sheet->setCellValue('D1', 'Max. Wert');
    $sheet->setCellValue('E1', 'Beschreibung');
    
    // Styling für Header
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D6EFD']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
    ];
    $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);
    
    // Beispieldaten
    $examples = [
        [1, 'Reaktion auf neue Umgebung', 'Offenheit', 7, 'Wie reagiert der Hund auf eine neue Umgebung?'],
        [2, 'Impulskontrolle', 'Gewissenhaftigkeit', 7, 'Kann der Hund Impulse kontrollieren?'],
        [3, 'Aktivitätslevel', 'Extraversion', 7, 'Wie aktiv ist der Hund?'],
        [4, 'Freundlichkeit gegenüber Fremden', 'Verträglichkeit', 14, 'Besonders wichtiger Test (höherer Wert)'],
        [5, 'Ängstlichkeit', 'Neurotizismus', 7, 'Zeigt der Hund ängstliches Verhalten?']
    ];
    
    $row = 2;
    foreach ($examples as $example) {
        $sheet->setCellValue("A{$row}", $example[0]);
        $sheet->setCellValue("B{$row}", $example[1]);
        $sheet->setCellValue("C{$row}", $example[2]);
        $sheet->setCellValue("D{$row}", $example[3]);
        $sheet->setCellValue("E{$row}", $example[4]);
        $row++;
    }
    
    // Spaltenbreiten
    $sheet->getColumnDimension('A')->setWidth(10);
    $sheet->getColumnDimension('B')->setWidth(35);
    $sheet->getColumnDimension('C')->setWidth(20);
    $sheet->getColumnDimension('D')->setWidth(12);
    $sheet->getColumnDimension('E')->setWidth(50);
    
    // Dropdown für OCEAN Dimension (C2:C100)
    $validation = $sheet->getCell('C2')->getDataValidation();
    $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
    $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
    $validation->setAllowBlank(false);
    $validation->setShowInputMessage(true);
    $validation->setShowErrorMessage(true);
    $validation->setErrorTitle('Ungültige Eingabe');
    $validation->setError('Bitte eine der 5 OCEAN-Dimensionen auswählen');
    $validation->setPromptTitle('OCEAN Dimension');
    $validation->setPrompt('Offenheit, Gewissenhaftigkeit, Extraversion, Verträglichkeit, Neurotizismus');
    $validation->setFormula1('"Offenheit,Gewissenhaftigkeit,Extraversion,Verträglichkeit,Neurotizismus"');
    
    // Dropdown auf weitere Zeilen kopieren
    for ($i = 3; $i <= 100; $i++) {
        $sheet->getCell("C{$i}")->setDataValidation(clone $validation);
    }
    
    // Zellen-Formatierung
    $sheet->getStyle('A2:A100')->getNumberFormat()->setFormatCode('0');
    $sheet->getStyle('D2:D100')->getNumberFormat()->setFormatCode('0');
    
    // Kommentar/Anleitung
    $sheet->getComment('A1')->getText()->createTextRun(
        "ANLEITUNG:\n" .
        "1. Tragen Sie Batterie-Name in A0 ein (optional)\n" .
        "2. Tragen Sie Tests ab Zeile 2 ein\n" .
        "3. Test Nr. = fortlaufend ab 1\n" .
        "4. OCEAN Dimension = Dropdown verwenden\n" .
        "5. Max. Wert = meist 7, für wichtige Tests 14\n" .
        "6. Speichern als .xlsx und hochladen"
    );
    
    // Download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="testbatterie_template.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
