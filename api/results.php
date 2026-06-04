<?php
declare(strict_types=1);
/**
 * API Endpoint: Test Results
 * 
 * GET    /api/results.php?session_id=1 - Alle Results einer Session
 * POST   /api/results.php              - Test-Ergebnis speichern/aktualisieren
 * DELETE /api/results.php?id=1         - Test-Ergebnis löschen
 *
 * Alle Methoden erfordern Authentifizierung. Ein User kann nur auf Results
 * von Sessions zugreifen, die ihm selbst gehören. Admins sehen alle.
 */

require_once 'config.php';

// Hilfsfunktion für Session-Validierung
function getUserFromSession($conn, $token) {
    $conn->query("DELETE FROM " . tbl('auth_sessions') . " WHERE expires_at < NOW()");

    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.is_admin
        FROM " . tbl('auth_sessions') . " s
        JOIN " . tbl('auth_users') . " u ON u.id = s.user_id
        WHERE s.session_token = ? AND s.expires_at > NOW() AND u.is_active = TRUE
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

$method = $_SERVER['REQUEST_METHOD'];
$conn = getDbConnection();

// Authentifizierung — ausschließlich via Authorization-Header
$authHeader = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

$currentUser = null;
if (!empty($authHeader)) {
    $token = str_replace('Bearer ', '', $authHeader);
    $currentUser = getUserFromSession($conn, $token);
}

if ($currentUser === null) {
    sendError('Nicht authentifiziert', 401);
}

// GET: Alle Results einer Session
if ($method === 'GET') {
    if (!isset($_GET['session_id'])) {
        sendError('Session ID erforderlich');
    }

    $session_id = validateInteger($_GET['session_id'], 1, null, 'Session ID');

    // Sicherstellen, dass die Session dem aktuellen User gehört (Admins ausgenommen)
    $sessionStmt = $conn->prepare("
        SELECT id FROM " . tbl('test_sessions') . "
        WHERE id = ?
        " . ($currentUser['is_admin'] ? '' : 'AND (user_id = ? OR user_id IS NULL)') . "
    ");
    if ($currentUser['is_admin']) {
        $sessionStmt->bind_param('i', $session_id);
    } else {
        $sessionStmt->bind_param('ii', $session_id, $currentUser['id']);
    }
    $sessionStmt->execute();
    if ($sessionStmt->get_result()->num_rows === 0) {
        sendError('Session nicht gefunden oder Zugriff verweigert', 404);
    }

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

    // Prüfen ob Session existiert UND dem aktuellen User gehört
    $sessionStmt = $conn->prepare("
        SELECT id FROM " . tbl('test_sessions') . "
        WHERE id = ?
        " . ($currentUser['is_admin'] ? '' : 'AND (user_id = ? OR user_id IS NULL)') . "
    ");
    if ($currentUser['is_admin']) {
        $sessionStmt->bind_param('i', $session_id);
    } else {
        $sessionStmt->bind_param('ii', $session_id, $currentUser['id']);
    }
    $sessionStmt->execute();
    if ($sessionStmt->get_result()->num_rows === 0) {
        sendError('Session nicht gefunden oder Zugriff verweigert', 404);
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

    // Prüfen ob Result existiert UND dem aktuellen User gehört (via Session)
    $ownerQuery = $currentUser['is_admin']
        ? "SELECT tr.test_number, tr.session_id
               FROM " . tbl('test_results') . " tr
               WHERE tr.id = ?"
        : "SELECT tr.test_number, tr.session_id
               FROM " . tbl('test_results') . " tr
               JOIN " . tbl('test_sessions') . " ts ON ts.id = tr.session_id
               WHERE tr.id = ? AND (ts.user_id = ? OR ts.user_id IS NULL)";

    $stmt = $conn->prepare($ownerQuery);
    if ($currentUser['is_admin']) {
        $stmt->bind_param('i', $id);
    } else {
        $stmt->bind_param('ii', $id, $currentUser['id']);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        sendError('Test-Ergebnis nicht gefunden oder Zugriff verweigert', 404);
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
