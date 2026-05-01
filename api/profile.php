<?php
declare(strict_types=1);
/**
 * User Profile Management API
 * Ermöglicht Benutzern die Bearbeitung ihres eigenen Profils
 */

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Hilfsfunktion für Session-Validierung
function getUserFromSession($conn, $token) {
    // Abgelaufene Sessions löschen
    $conn->query("DELETE FROM " . tbl('auth_sessions') . " WHERE expires_at < NOW()");
    
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.email, u.full_name, u.avatar, u.is_admin
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
    $conn = getDbConnection();
    
    // Session-Token aus Header holen
    $authHeader = '';
    
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (isset($_GET['token'])) {
        $authHeader = 'Bearer ' . $_GET['token'];
    } elseif (isset($_POST['token'])) {
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
    
    // Benutzer aus Session laden
    $currentUser = getUserFromSession($conn, $token);
    
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired session']);
        exit;
    }
    
    switch ($method) {
        case 'GET':
            // Profil abrufen
            echo json_encode(getProfile($conn, $currentUser));
            break;
            
        case 'POST':
            // Avatar hochladen
            echo json_encode(uploadAvatar($conn, $currentUser));
            break;
            
        case 'PUT':
            // Profil aktualisieren
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode(updateProfile($conn, $currentUser, $input));
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
    
} catch (Exception $e) {
    error_log("Profile API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

// ===================================================================
// Profil abrufen
// ===================================================================
function getProfile($conn, $currentUser) {
    $stmt = $conn->prepare("
        SELECT 
            id, username, email, full_name, avatar, 
            totp_enabled, last_login, created_at
        FROM " . tbl('auth_users') . "
        WHERE id = ?
    ");
    $stmt->bind_param('i', $currentUser['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    return [
        'success' => true,
        'profile' => $user
    ];
}

// ===================================================================
// Profil aktualisieren (Email, Name, Passwort)
// ===================================================================
function updateProfile($conn, $currentUser, $input) {
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'update_info':
            return updateUserInfo($conn, $currentUser, $input);
            
        case 'change_password':
            return changeUserPassword($conn, $currentUser, $input);
            
        case 'delete_avatar':
            return deleteUserAvatar($conn, $currentUser);
            
        default:
            throw new Exception('Invalid action');
    }
}

// ===================================================================
// Benutzer-Informationen aktualisieren
// ===================================================================
function updateUserInfo($conn, $currentUser, $input) {
    $email = $input['email'] ?? null;
    $fullName = $input['full_name'] ?? null;
    
    // Validierung
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    // Email-Duplikat prüfen
    if ($email !== null) {
        $stmt = $conn->prepare("
            SELECT id FROM " . tbl('auth_users') . "
            WHERE email = ? AND id != ?
        ");
        $stmt->bind_param('si', $email, $currentUser['id']);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            throw new Exception('Email already in use');
        }
    }
    
    // Update durchführen
    $stmt = $conn->prepare("
        UPDATE " . tbl('auth_users') . "
        SET email = ?, full_name = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('ssi', $email, $fullName, $currentUser['id']);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update profile');
    }
    
    return [
        'success' => true,
        'message' => 'Profile updated successfully'
    ];
}

// ===================================================================
// Passwort ändern
// ===================================================================
function changeUserPassword($conn, $currentUser, $input) {
    $currentPassword = $input['current_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    
    if (empty($currentPassword) || empty($newPassword)) {
        throw new Exception('Current and new password required');
    }
    
    if (strlen($newPassword) < 8) {
        throw new Exception('Password must be at least 8 characters');
    }
    
    // Aktuelles Passwort prüfen
    $stmt = $conn->prepare("
        SELECT password_hash FROM " . tbl('auth_users') . " WHERE id = ?
    ");
    $stmt->bind_param('i', $currentUser['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        throw new Exception('Current password is incorrect');
    }
    
    // Neues Passwort hashen und speichern
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    
    $stmt = $conn->prepare("
        UPDATE " . tbl('auth_users') . "
        SET password_hash = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('si', $newHash, $currentUser['id']);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update password');
    }
    
    // Alle anderen Sessions des Benutzers löschen (außer der aktuellen)
    $stmt = $conn->prepare("
        DELETE FROM " . tbl('auth_sessions') . "
        WHERE user_id = ? AND session_token != ?
    ");
    $sessionToken = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $stmt->bind_param('is', $currentUser['id'], $sessionToken);
    $stmt->execute();
    
    return [
        'success' => true,
        'message' => 'Password changed successfully'
    ];
}

// ===================================================================
// Avatar hochladen
// ===================================================================
function uploadAvatar($conn, $currentUser) {
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }
    
    $file = $_FILES['avatar'];
    
    // Validierung
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5 MB
    
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP allowed');
    }
    
    if ($file['size'] > $maxSize) {
        throw new Exception('File too large. Maximum 5 MB allowed');
    }
    
    // Dateiname generieren
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'avatar_' . $currentUser['id'] . '_' . time() . '.' . $extension;
    
    // Upload-Verzeichnis erstellen falls nötig
    $uploadDir = __DIR__ . '/../uploads/avatars/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $targetPath = $uploadDir . $filename;
    
    // Alten Avatar löschen
    if (!empty($currentUser['avatar'])) {
        $oldPath = $uploadDir . basename($currentUser['avatar']);
        if (file_exists($oldPath)) {
            unlink($oldPath);
        }
    }
    
    // Datei verschieben
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Failed to save file');
    }
    
    // In Datenbank speichern (relativer Pfad)
    $avatarPath = 'uploads/avatars/' . $filename;
    
    $stmt = $conn->prepare("
        UPDATE " . tbl('auth_users') . "
        SET avatar = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('si', $avatarPath, $currentUser['id']);
    
    if (!$stmt->execute()) {
        // Upload rückgängig machen
        unlink($targetPath);
        throw new Exception('Failed to update database');
    }
    
    return [
        'success' => true,
        'avatar' => $avatarPath,
        'message' => 'Avatar uploaded successfully'
    ];
}

// ===================================================================
// Avatar löschen
// ===================================================================
function deleteUserAvatar($conn, $currentUser) {
    if (empty($currentUser['avatar'])) {
        throw new Exception('No avatar to delete');
    }
    
    // Datei löschen
    $uploadDir = __DIR__ . '/../uploads/avatars/';
    $filePath = $uploadDir . basename($currentUser['avatar']);
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    // Aus Datenbank entfernen
    $stmt = $conn->prepare("
        UPDATE " . tbl('auth_users') . "
        SET avatar = NULL, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('i', $currentUser['id']);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update database');
    }
    
    return [
        'success' => true,
        'message' => 'Avatar deleted successfully'
    ];
}
