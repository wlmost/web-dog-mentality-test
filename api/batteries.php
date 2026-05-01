<?php
declare(strict_types=1);
/**
 * Testbatterien API - CRUD Operations
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$conn = getDbConnection();

try {
    switch ($method) {
        case 'GET':
            handleGet($conn);
            break;
        case 'POST':
            handlePost($conn);
            break;
        case 'PUT':
            handlePut($conn);
            break;
        case 'DELETE':
            handleDelete($conn);
            break;
        default:
            sendError('Method not allowed', 405);
    }
} catch (Exception $e) {
    logMessage('ERROR', 'batteries.php: ' . $e->getMessage());
    sendError('Internal server error', 500, $e->getMessage());
}

/**
 * GET - Liste aller Batterien oder einzelne Batterie
 */
function handleGet($conn) {
    if (isset($_GET['id'])) {
        getBattery($conn, $_GET['id']);
    } else {
        listBatteries($conn);
    }
}

function listBatteries($conn) {
    $sql = "SELECT 
                b.id,
                b.name,
                b.description,
                b.created_at,
                COUNT(bt.id) as test_count
            FROM " . tbl('test_batteries') . " b
            LEFT JOIN " . tbl('battery_tests') . " bt ON b.id = bt.battery_id
            GROUP BY b.id
            ORDER BY b.name";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        sendError('Database query failed', 500, $conn->error);
    }
    
    $batteries = [];
    while ($row = $result->fetch_assoc()) {
        $batteries[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'test_count' => (int)$row['test_count'],
            'created_at' => $row['created_at']
        ];
    }
    
    sendResponse(['batteries' => $batteries]);
}

function getBattery($conn, $id) {
    if (!validateInteger($id, 1, null, 'id')) {
        return;
    }
    
    // Batterie-Info
    $stmt = $conn->prepare("SELECT * FROM " . tbl('test_batteries') . " WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Battery not found', 404);
    }
    
    $battery = $result->fetch_assoc();
    
    // Tests laden
    $stmt = $conn->prepare("
        SELECT 
            id,
            test_number,
            name,
            ocean_dimension,
            setting,
            materials,
            duration,
            role_figurant,
            observation_criteria,
            rating_scale
        FROM " . tbl('battery_tests') . "
        WHERE battery_id = ?
        ORDER BY test_number
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $testsResult = $stmt->get_result();
    
    $tests = [];
    while ($row = $testsResult->fetch_assoc()) {
        $tests[] = [
            'id' => (int)$row['id'],
            'test_number' => (int)$row['test_number'],
            'test_name' => $row['name'],
            'ocean_dimension' => $row['ocean_dimension'],
            'setting' => $row['setting'],
            'materials' => $row['materials'],
            'duration' => $row['duration'],
            'role_figurant' => $row['role_figurant'],
            'observation_criteria' => $row['observation_criteria'],
            'rating_scale' => $row['rating_scale']
        ];
    }
    
    $response = [
        'id' => (int)$battery['id'],
        'name' => $battery['name'],
        'description' => $battery['description'],
        'created_at' => $battery['created_at'],
        'tests' => $tests
    ];
    
    sendResponse($response);
}

/**
 * POST - Neue Batterie erstellen
 */
function handlePost($conn) {
    $data = getJsonInput();
    
    // Validierung
    if (!validateRequired($data, ['name', 'tests'])) {
        return;
    }
    
    if (!is_array($data['tests']) || count($data['tests']) === 0) {
        sendError('tests must be a non-empty array', 400);
    }
    
    $conn->begin_transaction();
    
    try {
        // Batterie erstellen
        $stmt = $conn->prepare("
            INSERT INTO " . tbl('test_batteries') . " (name, description)
            VALUES (?, ?)
        ");
        
        $description = $data['description'] ?? null;
        $stmt->bind_param('ss', $data['name'], $description);
        $stmt->execute();
        
        $batteryId = $conn->insert_id;
        
        // Tests einfügen
        $stmt = $conn->prepare("
            INSERT INTO " . tbl('battery_tests') . "
            (battery_id, test_number, test_name, ocean_dimension, max_value)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($data['tests'] as $test) {
            if (!validateRequired($test, ['test_number', 'test_name', 'ocean_dimension', 'max_value'])) {
                throw new Exception('Invalid test data');
            }
            
            if (!validateInteger($test['test_number'], 1, null, 'test_number')) {
                throw new Exception('Invalid test_number');
            }
            
            if (!validateInteger($test['max_value'], 1, 100, 'max_value')) {
                throw new Exception('Invalid max_value');
            }
            
            $validDimensions = ['Offenheit', 'Gewissenhaftigkeit', 'Extraversion', 'Verträglichkeit', 'Neurotizismus'];
            if (!in_array($test['ocean_dimension'], $validDimensions)) {
                throw new Exception('Invalid ocean_dimension: ' . $test['ocean_dimension']);
            }
            
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
        
        logMessage('INFO', "Battery created: ID=$batteryId, Name={$data['name']}");
        
        // Neue Batterie laden und zurückgeben
        getBattery($conn, $batteryId);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * PUT - Batterie aktualisieren
 */
function handlePut($conn) {
    if (!isset($_GET['id'])) {
        sendError('Battery ID required', 400);
    }
    
    $id = $_GET['id'];
    if (!validateInteger($id, 1, null, 'id')) {
        return;
    }
    
    $data = getJsonInput();
    
    // Prüfen ob Batterie existiert
    $stmt = $conn->prepare("SELECT id FROM " . tbl('test_batteries') . " WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        sendError('Battery not found', 404);
    }
    
    $conn->begin_transaction();
    
    try {
        // Batterie-Metadaten aktualisieren
        if (isset($data['name']) || isset($data['description'])) {
            $updates = [];
            $params = [];
            $types = '';
            
            if (isset($data['name'])) {
                $updates[] = "name = ?";
                $params[] = $data['name'];
                $types .= 's';
            }
            
            if (isset($data['description'])) {
                $updates[] = "description = ?";
                $params[] = $data['description'];
                $types .= 's';
            }
            
            $params[] = $id;
            $types .= 'i';
            
            $sql = "UPDATE " . tbl('test_batteries') . " SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
        }
        
        // Tests aktualisieren (falls vorhanden)
        if (isset($data['tests']) && is_array($data['tests'])) {
            // Alte Tests löschen
            $stmt = $conn->prepare("DELETE FROM " . tbl('battery_tests') . " WHERE battery_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            
            // Neue Tests einfügen
            $stmt = $conn->prepare("
                INSERT INTO " . tbl('battery_tests') . "
                (battery_id, test_number, test_name, ocean_dimension, max_value)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            foreach ($data['tests'] as $test) {
                if (!validateRequired($test, ['test_number', 'test_name', 'ocean_dimension', 'max_value'])) {
                    throw new Exception('Invalid test data');
                }
                
                $stmt->bind_param(
                    'iissi',
                    $id,
                    $test['test_number'],
                    $test['test_name'],
                    $test['ocean_dimension'],
                    $test['max_value']
                );
                $stmt->execute();
            }
        }
        
        $conn->commit();
        
        logMessage('INFO', "Battery updated: ID=$id");
        
        sendResponse(['message' => 'Battery updated successfully']);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * DELETE - Batterie löschen
 */
function handleDelete($conn) {
    if (!isset($_GET['id'])) {
        sendError('Battery ID required', 400);
    }
    
    $id = $_GET['id'];
    if (!validateInteger($id, 1, null, 'id')) {
        return;
    }
    
    // Prüfen ob Batterie existiert
    $stmt = $conn->prepare("SELECT name FROM " . tbl('test_batteries') . " WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Battery not found', 404);
    }
    
    $battery = $result->fetch_assoc();
    
    // Prüfen ob Batterie in Sessions verwendet wird
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM " . tbl('test_sessions') . " WHERE battery_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    if ($row['count'] > 0) {
        sendError('Cannot delete battery: ' . $row['count'] . ' sessions are using it', 409);
    }
    
    // Batterie löschen (Tests werden via CASCADE gelöscht)
    $stmt = $conn->prepare("DELETE FROM " . tbl('test_batteries') . " WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    
    logMessage('INFO', "Battery deleted: ID=$id, Name={$battery['name']}");
    
    sendResponse(['message' => 'Battery deleted successfully']);
}
