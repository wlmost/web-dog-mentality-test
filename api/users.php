<?php
declare(strict_types=1);
/**
 * User Management API (nur für Admins)
 */

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// getUserFromSession Funktion aus auth.php (kopiert um require_once Problem zu vermeiden)
function getUserFromSession($conn, $token) {
    // Abgelaufene Sessions löschen
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

try {
    // DB-Verbindung und Admin-Check innerhalb try-catch
    $conn = getDbConnection();
    
    // Session-Token aus Header holen (verschiedene Quellen probieren)
    $authHeader = '';
    
    // Versuch 1: Standard HTTP_AUTHORIZATION
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    // Versuch 2: Apache mod_rewrite
    elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    // Versuch 3: Als Query-Parameter (Fallback)
    elseif (isset($_GET['token'])) {
        $authHeader = 'Bearer ' . $_GET['token'];
    }
    // Versuch 4: Als POST-Parameter
    elseif (isset($_POST['token'])) {
        $authHeader = 'Bearer ' . $_POST['token'];
    }
    
    if (empty($authHeader)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authorization header missing']);
        exit;
    }
    
    $token = str_replace('Bearer ', '', $authHeader);
    
    if (empty($token)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Token missing']);
        exit;
    }
    
    // Admin-Check
    $currentUser = getUserFromSession($conn, $token);
    
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired session']);
        exit;
    }
    
    if (!$currentUser['is_admin']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
    
    // Aktuellen User global speichern für deleteUser
    $GLOBALS['currentUser'] = $currentUser;
    
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                echo json_encode(getUser($conn, $_GET['id']));
            } else {
                echo json_encode(listUsers($conn));
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode(createUser($conn, $input));
            break;
            
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode(updateUser($conn, $input));
            break;
            
        case 'DELETE':
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode(deleteUser($conn, $input));
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
    
} catch (Exception $e) {
    error_log("Users API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}

// Verbindung schließen (nur wenn sie existiert)
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

// ===================================================================
// Alle Benutzer auflisten
// ===================================================================
function listUsers($conn) {
    $stmt = $conn->prepare("
        SELECT 
            id, username, email, full_name, is_admin, is_active,
            totp_enabled, last_login, created_at
        FROM " . tbl('auth_users') . "
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    return [
        'success' => true,
        'users' => $users
    ];
}

// ===================================================================
// Einzelnen Benutzer abrufen
// ===================================================================
function getUser($conn, $id) {
    $stmt = $conn->prepare("
        SELECT 
            id, username, email, full_name, is_admin, is_active,
            totp_enabled, last_login, created_at
        FROM " . tbl('auth_users') . "
        WHERE id = ?
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    return [
        'success' => true,
        'user' => $user
    ];
}

// ===================================================================
// Neuen Benutzer anlegen
// ===================================================================
function createUser($conn, $input) {
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    $email = $input['email'] ?? null;
    $fullName = $input['full_name'] ?? null;
    $isAdmin = (bool)($input['is_admin'] ?? false);
    
    // Validierung
    if (empty($username) || strlen($username) < 3) {
        throw new Exception('Username must be at least 3 characters');
    }
    
    if (empty($password) || strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters');
    }
    
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address');
    }
    
    // Prüfen ob Username bereits existiert
    $stmt = $conn->prepare("SELECT id FROM " . tbl('auth_users') . " WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        throw new Exception('Username already exists');
    }
    
    // Passwort hashen
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    
    // Benutzer anlegen
    $stmt = $conn->prepare("
        INSERT INTO " . tbl('auth_users') . "
        (username, password_hash, email, full_name, is_admin, is_active)
        VALUES (?, ?, ?, ?, ?, TRUE)
    ");
    $stmt->bind_param('ssssi', $username, $passwordHash, $email, $fullName, $isAdmin);
    $stmt->execute();
    
    $userId = $conn->insert_id;
    
    return [
        'success' => true,
        'user_id' => $userId,
        'message' => 'User created successfully'
    ];
}

// ===================================================================
// Benutzer aktualisieren
// ===================================================================
function updateUser($conn, $input) {
    $userId = $input['id'] ?? 0;
    
    if (!$userId) {
        throw new Exception('User ID required');
    }
    
    // Benutzer laden
    $stmt = $conn->prepare("SELECT username FROM " . tbl('auth_users') . " WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    // Update-Felder sammeln
    $updates = [];
    $params = [];
    $types = '';
    
    if (isset($input['email'])) {
        if ($input['email'] && !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }
        $updates[] = 'email = ?';
        $params[] = $input['email'];
        $types .= 's';
    }
    
    if (isset($input['full_name'])) {
        $updates[] = 'full_name = ?';
        $params[] = $input['full_name'];
        $types .= 's';
    }
    
    if (isset($input['is_admin'])) {
        $updates[] = 'is_admin = ?';
        $params[] = (bool)$input['is_admin'];
        $types .= 'i';
    }
    
    if (isset($input['is_active'])) {
        $updates[] = 'is_active = ?';
        $params[] = (bool)$input['is_active'];
        $types .= 'i';
    }
    
    if (isset($input['password'])) {
        if (strlen($input['password']) < 8) {
            throw new Exception('Password must be at least 8 characters');
        }
        $updates[] = 'password_hash = ?';
        $params[] = password_hash($input['password'], PASSWORD_BCRYPT);
        $types .= 's';
    }
    
    if (empty($updates)) {
        throw new Exception('No fields to update');
    }
    
    // Update ausführen
    $params[] = $userId;
    $types .= 'i';
    
    $sql = "UPDATE " . tbl('auth_users') . " SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    
    return [
        'success' => true,
        'message' => 'User updated successfully'
    ];
}

// ===================================================================
// Benutzer löschen
// ===================================================================
function deleteUser($conn, $input) {
    $userId = $input['id'] ?? 0;
    
    if (!$userId) {
        throw new Exception('User ID required');
    }
    
    // Nicht sich selbst löschen
    $currentUserId = $GLOBALS['currentUser']['id'] ?? 0;
    if ($userId == $currentUserId) {
        throw new Exception('Cannot delete your own account');
    }
    
    // Nicht den letzten Admin löschen
    $stmt = $conn->prepare("
        SELECT COUNT(*) as admin_count 
        FROM " . tbl('auth_users') . "
        WHERE is_admin = TRUE AND is_active = TRUE
    ");
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['admin_count'];
    
    if ($count <= 1) {
        $stmt = $conn->prepare("SELECT is_admin FROM " . tbl('auth_users') . " WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if ($user['is_admin']) {
            throw new Exception('Cannot delete the last admin user');
        }
    }
    
    // Benutzer löschen (CASCADE löscht auch Sessions)
    $stmt = $conn->prepare("DELETE FROM " . tbl('auth_users') . " WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        throw new Exception('User not found');
    }
    
    return [
        'success' => true,
        'message' => 'User deleted successfully'
    ];
}
