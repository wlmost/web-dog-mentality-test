<?php
declare(strict_types=1);
/**
 * API Endpoint: Test Results
 * 
 * POST   /api/results.php              - Test-Ergebnis speichern/aktualisieren
 * DELETE /api/results.php?id=1         - Test-Ergebnis löschen
 * GET    /api/results.php?session_id=1 - Alle Results einer Session
 */

require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = getDbConnection();

// GET: Alle Results einer Session
if ($method === 'GET') {
    if (!isset($_GET['session_id'])) {
        sendError('Session ID erforderlich');
    }
    
    $session_id = validateInteger($_GET['session_id'], 1, null, 'Session ID');
    
    $stmt = $conn->prepare("
        SELECT tr.id, tr.test_number, tr.score, tr.notes,
               bt.name as test_name, bt.ocean_dimension
        FROM " . tbl('test_results') . " tr
        LEFT JOIN " . tbl('test_sessions') . " ts ON tr.session_id = ts.id
        LEFT JOIN " . tbl('battery_tests') . " bt ON ts.battery_id = bt.battery_id 
            AND tr.test_number = bt.test_number
        WHERE tr.session_id = ?
        ORDER BY tr.test_number
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $results = [];
    while ($row = $result->fetch_assoc()) {
        $results[] = $row;
    }
    
    sendResponse([
        'session_id' => $session_id,
        'total' => count($results),
        'results' => $results
    ]);
}

// POST: Test-Ergebnis speichern/aktualisieren
elseif ($method === 'POST') {
    $data = getJsonInput();
    
    // Validierung
    validateRequired($data, ['session_id', 'test_number', 'score']);
    
    $session_id = validateInteger($data['session_id'], 1, null, 'Session ID');
    $test_number = validateInteger($data['test_number'], 1, null, 'Test-Nummer');
    $score = validateInteger($data['score'], -2, 2, 'Score');
    $notes = sanitizeString($data['notes'] ?? '');
    
    // Prüfen ob Session existiert
    $stmt = $conn->prepare("SELECT id FROM " . tbl('test_sessions') . " WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        sendError('Session nicht gefunden', 404);
    }
    
    // Prüfen ob Result bereits existiert
    $stmt = $conn->prepare("
        SELECT id FROM " . tbl('test_results') . "
        WHERE session_id = ? AND test_number = ?
    ");
    $stmt->bind_param("ii", $session_id, $test_number);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    
    if ($existing) {
        // Update
        $stmt = $conn->prepare("
            UPDATE " . tbl('test_results') . "
            SET score = ?, notes = ?
            WHERE session_id = ? AND test_number = ?
        ");
        $stmt->bind_param("isii", $score, $notes, $session_id, $test_number);
        
        if ($stmt->execute()) {
            $resultId = $existing['id'];
            logMessage("Test-Result aktualisiert: Session $session_id, Test $test_number, Score $score");
        } else {
            sendError('Fehler beim Aktualisieren des Ergebnisses', 500, $stmt->error);
        }
        
    } else {
        // Insert
        $stmt = $conn->prepare("
            INSERT INTO " . tbl('test_results') . " (session_id, test_number, score, notes)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("iiis", $session_id, $test_number, $score, $notes);
        
        if ($stmt->execute()) {
            $resultId = $conn->insert_id;
            logMessage("Neues Test-Result erstellt: Session $session_id, Test $test_number, Score $score");
        } else {
            sendError('Fehler beim Erstellen des Ergebnisses', 500, $stmt->error);
        }
    }
    
    // Erstelltes/Aktualisiertes Result zurückgeben
    $stmt = $conn->prepare("
        SELECT tr.*, bt.name as test_name, bt.ocean_dimension
        FROM " . tbl('test_results') . " tr
        LEFT JOIN " . tbl('test_sessions') . " ts ON tr.session_id = ts.id
        LEFT JOIN " . tbl('battery_tests') . " bt ON ts.battery_id = bt.battery_id 
            AND tr.test_number = bt.test_number
        WHERE tr.id = ?
    ");
    $stmt->bind_param("i", $resultId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    sendResponse($result, $existing ? 200 : 201);
}

// DELETE: Test-Ergebnis löschen
elseif ($method === 'DELETE') {
    if (!isset($_GET['id'])) {
        sendError('Result ID erforderlich');
    }
    
    $id = validateInteger($_GET['id'], 1, null, 'Result ID');
    
    // Prüfen ob Result existiert
    $stmt = $conn->prepare("SELECT test_number, session_id FROM " . tbl('test_results') . " WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Test-Ergebnis nicht gefunden', 404);
    }
    
    $resultData = $result->fetch_assoc();
    
    // Löschen
    $stmt = $conn->prepare("DELETE FROM " . tbl('test_results') . " WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        logMessage("Test-Result gelöscht: ID $id");
        sendResponse([
            'message' => 'Test-Ergebnis erfolgreich gelöscht',
            'id' => $id,
            'test_number' => $resultData['test_number']
        ]);
    } else {
        sendError('Fehler beim Löschen des Ergebnisses', 500, $stmt->error);
    }
}

else {
    sendError('Methode nicht erlaubt', 405);
}

?>
