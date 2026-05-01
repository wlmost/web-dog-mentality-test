<?php
declare(strict_types=1);
/**
 * API Endpoint: Dogs (Stammdaten) mit User-Zuordnung
 * 
 * GET    /api/dogs.php          - Alle Hunde abrufen (user-gefiltert)
 * GET    /api/dogs.php?id=1     - Einzelnen Hund abrufen
 * POST   /api/dogs.php          - Neuen Hund erstellen
 * PUT    /api/dogs.php?id=1     - Hund aktualisieren
 * DELETE /api/dogs.php?id=1     - Hund löschen
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

// GET: Hunde abrufen
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        // Einzelnen Hund
        $id = validateInteger($_GET['id'], 1, null, 'Dog ID');
        
        // User-Filterung bei Abruf
        $userFilter = "";
        $userParam = null;
        if ($currentUser && !$currentUser['is_admin']) {
            $userFilter = " AND user_id = ?";
            $userParam = $currentUser['id'];
        }
        
        $sql = "SELECT * FROM " . tbl('dogs') . " WHERE id = ?" . $userFilter;
        $stmt = $conn->prepare($sql);
        
        if ($userParam !== null) {
            $stmt->bind_param("ii", $id, $userParam);
        } else {
            $stmt->bind_param("i", $id);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($dog = $result->fetch_assoc()) {
            sendResponse($dog);
        } else {
            sendError('Hund nicht gefunden', 404);
        }
        
    } else {
        // Alle Hunde mit optionaler Suche
        $search = isset($_GET['search']) ? sanitizeString($_GET['search']) : '';
        
        // User-Filterung: Normale User sehen nur ihre Hunde (und KEINE NULL-Werte)
        $userFilter = "";
        $params = [];
        $types = "";
        
        if ($currentUser && !$currentUser['is_admin']) {
            // Nur Hunde mit exakt dieser user_id (keine NULL-Werte)
            $userFilter = " AND user_id = ?";
            $params[] = $currentUser['id'];
            $types .= "i";
        }
        
        if ($search) {
            $searchParam = "%$search%";
            $sql = "
                SELECT * FROM " . tbl('dogs') . "
                WHERE (dog_name LIKE ? 
                   OR owner_name LIKE ? 
                   OR breed LIKE ?)" . $userFilter . "
                ORDER BY dog_name ASC
            ";
            array_unshift($params, $searchParam, $searchParam, $searchParam);
            $types = "sss" . $types;
        } else {
            $sql = "SELECT * FROM " . tbl('dogs') . " WHERE 1=1" . $userFilter . " ORDER BY created_at DESC";
        }
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $dogs = [];
        while ($row = $result->fetch_assoc()) {
            $dogs[] = $row;
        }
        
        sendResponse([
            'total' => count($dogs),
            'dogs' => $dogs
        ]);
    }
}

// POST: Neuen Hund erstellen
elseif ($method === 'POST') {
    $data = getJsonInput();
    
    // Validierung
    validateRequired($data, ['owner_name', 'dog_name', 'age_years', 'gender']);
    
    $owner_name = sanitizeString($data['owner_name']);
    $dog_name = sanitizeString($data['dog_name']);
    $breed = sanitizeString($data['breed'] ?? '');
    $age_years = validateInteger($data['age_years'], 0, 20, 'Alter (Jahre)');
    $age_months = validateInteger($data['age_months'] ?? 0, 0, 11, 'Alter (Monate)');
    $gender = validateEnum($data['gender'], ['Rüde', 'Hündin'], 'Geschlecht');
    $neutered = isset($data['neutered']) ? (bool)$data['neutered'] : false;
    $intended_use = sanitizeString($data['intended_use'] ?? '');
    
    // User-ID automatisch setzen wenn angemeldet
    $user_id = $currentUser ? $currentUser['id'] : null;
    
    // Validierung: Alter mindestens 1 Monat
    if ($age_years === 0 && $age_months === 0) {
        sendError('Alter muss mindestens 1 Monat betragen');
    }
    
    // Insert mit user_id
    $stmt = $conn->prepare("
        INSERT INTO " . tbl('dogs') . "
        (user_id, owner_name, dog_name, breed, age_years, age_months, gender, neutered, intended_use)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param(
        "isssiisis",
        $user_id, $owner_name, $dog_name, $breed, $age_years, $age_months, 
        $gender, $neutered, $intended_use
    );
    
    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        
        // Neu erstellten Hund zurückgeben
        $stmt = $conn->prepare("SELECT * FROM " . tbl('dogs') . " WHERE id = ?");
        $stmt->bind_param("i", $newId);
        $stmt->execute();
        $result = $stmt->get_result();
        $newDog = $result->fetch_assoc();
        
        logMessage("Neuer Hund erstellt: ID $newId, Name: $dog_name, User ID: " . ($user_id ?? 'NULL'));
        sendResponse($newDog, 201);
        
    } else {
        sendError('Fehler beim Erstellen des Hundes', 500, $stmt->error);
    }
}

// PUT: Hund aktualisieren
elseif ($method === 'PUT') {
    if (!isset($_GET['id'])) {
        sendError('Dog ID erforderlich');
    }
    
    $id = validateInteger($_GET['id'], 1, null, 'Dog ID');
    $data = getJsonInput();
    
    // Prüfen ob Hund existiert und User berechtigt ist
    $stmt = $conn->prepare("SELECT user_id FROM " . tbl('dogs') . " WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Hund nicht gefunden', 404);
    }
    
    $dog = $result->fetch_assoc();
    
    // Berechtigungsprüfung: Nur Eigentümer oder Admin dürfen bearbeiten
    if ($currentUser && !$currentUser['is_admin']) {
        if ($dog['user_id'] != $currentUser['id']) {
            sendError('Keine Berechtigung für diesen Hund', 403);
        }
    }
    
    // Validierung
    validateRequired($data, ['owner_name', 'dog_name', 'age_years', 'gender']);
    
    $owner_name = sanitizeString($data['owner_name']);
    $dog_name = sanitizeString($data['dog_name']);
    $breed = sanitizeString($data['breed'] ?? '');
    $age_years = validateInteger($data['age_years'], 0, 20, 'Alter (Jahre)');
    $age_months = validateInteger($data['age_months'] ?? 0, 0, 11, 'Alter (Monate)');
    $gender = validateEnum($data['gender'], ['Rüde', 'Hündin'], 'Geschlecht');
    $neutered = isset($data['neutered']) ? (bool)$data['neutered'] : false;
    $intended_use = sanitizeString($data['intended_use'] ?? '');
    
    // Validierung: Alter mindestens 1 Monat
    if ($age_years === 0 && $age_months === 0) {
        sendError('Alter muss mindestens 1 Monat betragen');
    }
    
    // Update
    $stmt = $conn->prepare("
        UPDATE " . tbl('dogs') . " SET
            owner_name = ?,
            dog_name = ?,
            breed = ?,
            age_years = ?,
            age_months = ?,
            gender = ?,
            neutered = ?,
            intended_use = ?
        WHERE id = ?
    ");
    
    $stmt->bind_param(
        "sssiisisi",
        $owner_name, $dog_name, $breed, $age_years, $age_months,
        $gender, $neutered, $intended_use, $id
    );
    
    if ($stmt->execute()) {
        // Aktualisierten Hund zurückgeben
        $stmt = $conn->prepare("SELECT * FROM " . tbl('dogs') . " WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $updatedDog = $result->fetch_assoc();
        
        logMessage("Hund aktualisiert: ID $id, Name: $dog_name");
        sendResponse($updatedDog);
        
    } else {
        sendError('Fehler beim Aktualisieren des Hundes', 500, $stmt->error);
    }
}

// DELETE: Hund löschen
elseif ($method === 'DELETE') {
    if (!isset($_GET['id'])) {
        sendError('Dog ID erforderlich');
    }
    
    $id = validateInteger($_GET['id'], 1, null, 'Dog ID');
    
    // Prüfen ob Hund existiert und User berechtigt ist
    $stmt = $conn->prepare("SELECT user_id, dog_name FROM " . tbl('dogs') . " WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendError('Hund nicht gefunden', 404);
    }
    
    $dog = $result->fetch_assoc();
    $dogName = $dog['dog_name'];
    
    // Berechtigungsprüfung: Nur Eigentümer oder Admin dürfen löschen
    if ($currentUser && !$currentUser['is_admin']) {
        if ($dog['user_id'] != $currentUser['id']) {
            sendError('Keine Berechtigung für diesen Hund', 403);
        }
    }
    
    // Löschen (CASCADE löscht automatisch zugehörige Sessions)
    $stmt = $conn->prepare("DELETE FROM " . tbl('dogs') . " WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        logMessage("Hund gelöscht: ID $id, Name: $dogName");
        sendResponse([
            'message' => 'Hund erfolgreich gelöscht',
            'id' => $id,
            'deleted_dog' => $dogName
        ]);
    } else {
        sendError('Fehler beim Löschen des Hundes', 500, $stmt->error);
    }
}

else {
    sendError('Methode nicht erlaubt', 405);
}

?>
