<?php
/**
 * API Endpoint: Test Sessions (mit User-Zuordnung)
 * 
 * GET    /api/sessions.php           - Alle Sessions abrufen (user-gefiltert)
 * GET    /api/sessions.php?id=1      - Einzelne Session mit Details
 * POST   /api/sessions.php           - Neue Session erstellen
 * PUT    /api/sessions.php?id=1      - Session aktualisieren
 * DELETE /api/sessions.php?id=1      - Session löschen
 */

require_once 'config.php';

// Hilfsfunktion für Session-Validierung
function getUserFromSession($conn, $token) {
    $conn->query("DELETE FROM auth_sessions WHERE expires_at < NOW()");
    
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.is_admin
        FROM auth_sessions s
        JOIN auth_users u ON u.id = s.user_id
        WHERE s.session_token = ? AND s.expires_at > NOW() AND u.is_active = TRUE
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

$method = $_SERVER['REQUEST_METHOD'];
$conn = getDbConnection();

// Authentifizierung
$authHeader = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif (isset($_GET['token'])) {
    $authHeader = 'Bearer ' . $_GET['token'];
}

$currentUser = null;
if (!empty($authHeader)) {
    $token = str_replace('Bearer ', '', $authHeader);
    $currentUser = getUserFromSession($conn, $token);
}

// GET: Sessions abrufen
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        // Einzelne Session mit allen Details
        $id = validateInteger($_GET['id'], 1, null, 'Session ID');
        
        // User-Filterung bei Abruf
        $userFilter = "";
        $userParam = null;
        if ($currentUser && !$currentUser['is_admin']) {
            $userFilter = " AND ts.user_id = ?";
            $userParam = $currentUser['id'];
        }
        
        // Session-Grunddaten mit Dog + Battery Info
        $sql = "
            SELECT 
                ts.id AS session_id,
                ts.session_date,
                ts.session_notes,
                ts.battery_id,
                ts.user_id,
                d.id AS dog_id,
                d.dog_name,
                d.breed,
                d.age_years,
                d.age_months,
                d.gender,
                d.owner_name,
                d.intended_use,
                tb.name AS battery_name,
                ts.ideal_profile,
                ts.owner_profile,
                ts.ai_assessment
            FROM test_sessions ts
            JOIN dogs d ON ts.dog_id = d.id
            JOIN test_batteries tb ON ts.battery_id = tb.id
            WHERE ts.id = ?" . $userFilter;
        
        $stmt = $conn->prepare($sql);
        if ($userParam !== null) {
            $stmt->bind_param("ii", $id, $userParam);
        } else {
            $stmt->bind_param("i", $id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($session = $result->fetch_assoc()) {
            // Integers konvertieren
            $session['session_id'] = (int)$session['session_id'];
            $session['battery_id'] = (int)$session['battery_id'];
            $session['dog_id'] = (int)$session['dog_id'];
            $session['age_years'] = (int)$session['age_years'];
            $session['age_months'] = (int)$session['age_months'];
            
            // JSON-Felder dekodieren
            if ($session['ideal_profile']) {
                $session['ideal_profile'] = json_decode($session['ideal_profile'], true);
            }
            if ($session['owner_profile']) {
                $session['owner_profile'] = json_decode($session['owner_profile'], true);
            }
            
            // Test-Ergebnisse laden
            $stmt = $conn->prepare("
                SELECT tr.id, tr.test_number, tr.score, tr.notes,
                       bt.name as test_name, bt.ocean_dimension
                FROM test_results tr
                LEFT JOIN test_sessions ts ON tr.session_id = ts.id
                LEFT JOIN battery_tests bt ON ts.battery_id = bt.battery_id 
                    AND tr.test_number = bt.test_number
                WHERE tr.session_id = ?
                ORDER BY tr.test_number
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $session['results'] = $results;
            
            sendResponse($session);
        } else {
            sendError('Session nicht gefunden', 404);
        }
        
    } else {
        // Alle Sessions (Übersicht)
        $dogId = isset($_GET['dog_id']) ? validateInteger($_GET['dog_id'], 1) : null;
        
        // User-Filterung: Normale User sehen nur ihre Sessions
        $userFilter = "";
        $params = [];
        $types = "";
        
        if ($currentUser && !$currentUser['is_admin']) {
            $userFilter = " AND user_id = ?";
            $params[] = $currentUser['id'];
            $types .= "i";
        }
        
        if ($dogId) {
            $sql = "SELECT * FROM v_session_overview WHERE dog_id = ?" . $userFilter . " ORDER BY session_date DESC";
            array_unshift($params, $dogId);
            $types = "i" . $types;
        } else {
            $sql = "SELECT * FROM v_session_overview WHERE 1=1" . $userFilter . " ORDER BY session_date DESC";
        }
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            // JSON-Felder dekodieren
            if ($row['ideal_profile']) {
                $row['ideal_profile'] = json_decode($row['ideal_profile'], true);
            }
            if ($row['owner_profile']) {
                $row['owner_profile'] = json_decode($row['owner_profile'], true);
            }
            $sessions[] = $row;
        }
        
        sendResponse([
            'total' => count($sessions),
            'sessions' => $sessions
        ]);
    }
}

// POST: Neue Session erstellen
elseif ($method === 'POST') {
    $data = getJsonInput();
    
    // Validierung
    validateRequired($data, ['dog_id', 'battery_id']);
    
    $dog_id = validateInteger($data['dog_id'], 1, null, 'Dog ID');
    $battery_id = validateInteger($data['battery_id'], 1, null, 'Battery ID');
    $session_notes = sanitizeString($data['session_notes'] ?? '');
    
    // User-ID automatisch setzen wenn angemeldet
    $user_id = $currentUser ? $currentUser['id'] : null;
    
    // Optional: Profile validieren falls vorhanden
    $ideal_profile = null;
    $owner_profile = null;
    $ai_assessment = null;
    
    if (isset($data['ideal_profile']) && !empty($data['ideal_profile'])) {
        $ideal_profile = json_encode(validateOceanProfile($data['ideal_profile']));
    }
    
    if (isset($data['owner_profile']) && !empty($data['owner_profile'])) {
        $owner_profile = json_encode(validateOceanProfile($data['owner_profile']));
    }
    
    if (isset($data['ai_assessment'])) {
        $ai_assessment = sanitizeString($data['ai_assessment']);
    }
    
    // Prüfen ob Dog und Battery existieren
    $stmt = $conn->prepare("SELECT id FROM dogs WHERE id = ?");
    $stmt->bind_param("i", $dog_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        sendError('Hund nicht gefunden', 404);
    }
    
    $stmt = $conn->prepare("SELECT id FROM test_batteries WHERE id = ?");
    $stmt->bind_param("i", $battery_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        sendError('Testbatterie nicht gefunden', 404);
    }
    
    // Insert mit user_id
    $stmt = $conn->prepare("
        INSERT INTO test_sessions 
        (dog_id, battery_id, user_id, session_notes, ideal_profile, owner_profile, ai_assessment)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param(
        "iiissss",
        $dog_id, $battery_id, $user_id, $session_notes,
        $ideal_profile, $owner_profile, $ai_assessment
    );
    
    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        
        // Neu erstellte Session zurückgeben
        $stmt = $conn->prepare("SELECT * FROM v_session_overview WHERE session_id = ?");
        $stmt->bind_param("i", $newId);
        $stmt->execute();
        $result = $stmt->get_result();
        $newSession = $result->fetch_assoc();
        
        // JSON dekodieren
        if ($newSession['ideal_profile']) {
            $newSession['ideal_profile'] = json_decode($newSession['ideal_profile'], true);
        }
        if ($newSession['owner_profile']) {
            $newSession['owner_profile'] = json_decode($newSession['owner_profile'], true);
        }
        $newSession['results'] = [];
        
        logMessage("Neue Session erstellt: ID $newId, Dog ID: $dog_id, User ID: " . ($user_id ?? 'NULL'));
        sendResponse($newSession, 201);
        
    } else {
        sendError('Fehler beim Erstellen der Session', 500, $stmt->error);
    }
}

// PUT: Session aktualisieren
elseif ($method === 'PUT') {
    if (!isset($_GET['id'])) {
        sendError('Session ID erforderlich');
    }
    
    $id = validateInteger($_GET['id'], 1, null, 'Session ID');
    $data = getJsonInput();
    
    // Prüfen ob Session existiert und User berechtigt ist
    $stmt = $conn->prepare("SELECT user_id FROM test_sessions WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Session nicht gefunden', 404);
    }
    
    $session = $result->fetch_assoc();
    
    // Berechtigungsprüfung: Nur Eigentümer oder Admin dürfen bearbeiten
    if ($currentUser && !$currentUser['is_admin']) {
        if ($session['user_id'] != $currentUser['id']) {
            sendError('Keine Berechtigung für diese Session', 403);
        }
    }
    
    // Felder aktualisieren (nur die übergebenen)
    $updates = [];
    $params = [];
    $types = "";
    
    if (isset($data['session_notes'])) {
        $updates[] = "session_notes = ?";
        $params[] = sanitizeString($data['session_notes']);
        $types .= "s";
    }
    
    if (isset($data['ideal_profile'])) {
        $updates[] = "ideal_profile = ?";
        $params[] = json_encode(validateOceanProfile($data['ideal_profile']));
        $types .= "s";
    }
    
    if (isset($data['owner_profile'])) {
        $updates[] = "owner_profile = ?";
        $params[] = json_encode(validateOceanProfile($data['owner_profile']));
        $types .= "s";
    }
    
    if (isset($data['ai_assessment'])) {
        $updates[] = "ai_assessment = ?";
        $params[] = sanitizeString($data['ai_assessment']);
        $types .= "s";
    }
    
    if (empty($updates)) {
        sendError('Keine Felder zum Aktualisieren angegeben');
    }
    
    // Update ausführen
    $sql = "UPDATE test_sessions SET " . implode(", ", $updates) . " WHERE id = ?";
    $params[] = $id;
    $types .= "i";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        // Aktualisierte Session zurückgeben
        $stmt = $conn->prepare("SELECT * FROM v_session_overview WHERE session_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $updatedSession = $result->fetch_assoc();
        
        // JSON dekodieren
        if ($updatedSession['ideal_profile']) {
            $updatedSession['ideal_profile'] = json_decode($updatedSession['ideal_profile'], true);
        }
        if ($updatedSession['owner_profile']) {
            $updatedSession['owner_profile'] = json_decode($updatedSession['owner_profile'], true);
        }
        
        logMessage("Session aktualisiert: ID $id");
        sendResponse($updatedSession);
        
    } else {
        sendError('Fehler beim Aktualisieren der Session', 500, $stmt->error);
    }
}

// DELETE: Session löschen
elseif ($method === 'DELETE') {
    if (!isset($_GET['id'])) {
        sendError('Session ID erforderlich');
    }
    
    $id = validateInteger($_GET['id'], 1, null, 'Session ID');
    
    // Prüfen ob Session existiert und User berechtigt ist
    $stmt = $conn->prepare("SELECT user_id, session_date FROM test_sessions WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Session nicht gefunden', 404);
    }
    
    $session = $result->fetch_assoc();
    
    // Berechtigungsprüfung: Nur Eigentümer oder Admin dürfen löschen
    if ($currentUser && !$currentUser['is_admin']) {
        if ($session['user_id'] != $currentUser['id']) {
            sendError('Keine Berechtigung für diese Session', 403);
        }
    }
    
    // Löschen (CASCADE löscht automatisch zugehörige Results)
    $stmt = $conn->prepare("DELETE FROM test_sessions WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        logMessage("Session gelöscht: ID $id");
        sendResponse([
            'message' => 'Session erfolgreich gelöscht',
            'id' => $id
        ]);
    } else {
        sendError('Fehler beim Löschen der Session', 500, $stmt->error);
    }
}

else {
    sendError('Methode nicht erlaubt', 405);
}

?>
