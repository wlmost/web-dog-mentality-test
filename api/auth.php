<?php
/**
 * Authentication & 2FA API
 * Handles login, 2FA verification, session management
 */

require_once 'config.php';

// Headers werden bereits in config.php gesetzt, nicht doppelt setzen
// header('Content-Type: application/json'); // Bereits in config.php
// header('Access-Control-Allow-Origin: *'); // Bereits in config.php
// header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS'); // Bereits in config.php
// header('Access-Control-Allow-Headers: Content-Type, Authorization'); // Bereits in config.php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    // DB-Verbindung innerhalb try-catch, um Fehler abzufangen
    $conn = getDbConnection();
    
    switch ($method) {
        case 'POST':
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);
            
            // Debug: Log raw input bei JSON-Fehler
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error: " . json_last_error_msg());
                error_log("Raw input: " . substr($rawInput, 0, 500));
                throw new Exception('Invalid JSON in request body');
            }
            
            if (!isset($input['action'])) {
                throw new Exception('Action required');
            }
            
            switch ($input['action']) {
                case 'login':
                    echo json_encode(handleLogin($conn, $input));
                    break;
                    
                case 'verify_2fa':
                    echo json_encode(verify2FA($conn, $input));
                    break;
                    
                case 'setup_2fa':
                    echo json_encode(setup2FA($conn, $input));
                    break;
                    
                case 'enable_2fa':
                    echo json_encode(enable2FA($conn, $input));
                    break;
                    
                case 'disable_2fa':
                    echo json_encode(disable2FA($conn, $input));
                    break;
                    
                case 'verify_session':
                    echo json_encode(verifySession($conn, $input));
                    break;
                    
                case 'change_password':
                    echo json_encode(changePassword($conn, $input));
                    break;
                    
                default:
                    throw new Exception('Unknown action');
            }
            break;
            
        case 'DELETE':
            // Logout
            $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            echo json_encode(handleLogout($conn, $token));
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

// Verbindung schließen (nur wenn sie existiert)
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

// ===================================================================
// Login (Schritt 1: Username + Passwort)
// ===================================================================
function handleLogin($conn, $input) {
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        throw new Exception('Username and password required');
    }
    
    // Benutzer laden
    $stmt = $conn->prepare("
        SELECT id, username, password_hash, is_admin, is_active, 
               totp_enabled, totp_secret, failed_attempts, locked_until
        FROM auth_users 
        WHERE username = ?
    ");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        logAuthEvent($conn, $username, 'login_failed', 'User not found');
        sleep(2); // Brute-force Protection
        throw new Exception('Invalid credentials');
    }
    
    // Account deaktiviert?
    if (!$user['is_active']) {
        logAuthEvent($conn, $username, 'login_failed', 'Account deactivated');
        throw new Exception('Account is deactivated');
    }
    
    // Account gesperrt?
    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        $remaining = strtotime($user['locked_until']) - time();
        throw new Exception("Account locked for " . ceil($remaining / 60) . " minutes");
    }
    
    // Passwort prüfen
    if (!password_verify($password, $user['password_hash'])) {
        // Failed attempts erhöhen
        $failed = $user['failed_attempts'] + 1;
        $locked_until = null;
        
        if ($failed >= 5) {
            $locked_until = date('Y-m-d H:i:s', time() + 900); // 15 Min
            logAuthEvent($conn, $username, 'account_locked', "After $failed attempts");
        }
        
        $stmt = $conn->prepare("
            UPDATE auth_users 
            SET failed_attempts = ?, locked_until = ?
            WHERE id = ?
        ");
        $stmt->bind_param('isi', $failed, $locked_until, $user['id']);
        $stmt->execute();
        
        logAuthEvent($conn, $username, 'login_failed', 'Invalid password');
        sleep(2);
        throw new Exception('Invalid credentials');
    }
    
    // Passwort korrekt - Failed attempts zurücksetzen
    $stmt = $conn->prepare("
        UPDATE auth_users 
        SET failed_attempts = 0, locked_until = NULL
        WHERE id = ?
    ");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    
    // 2FA aktiviert?
    if ($user['totp_enabled']) {
        // Temporäres Token für 2FA-Verifizierung
        $temp_token = bin2hex(random_bytes(16));
        
        return [
            'success' => true,
            'requires_2fa' => true,
            'temp_token' => $temp_token,
            'user_id' => $user['id']
        ];
    }
    
    // Kein 2FA - direkt Session erstellen
    $session = createSession($conn, $user['id']);
    logAuthEvent($conn, $username, 'login_success');
    
    return [
        'success' => true,
        'requires_2fa' => false,
        'session_token' => $session['token'],
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'is_admin' => (bool)$user['is_admin']
        ]
    ];
}

// ===================================================================
// 2FA Verifizierung (Schritt 2: TOTP Code)
// ===================================================================
function verify2FA($conn, $input) {
    $user_id = $input['user_id'] ?? 0;
    $code = $input['code'] ?? '';
    
    if (empty($code)) {
        throw new Exception('2FA code required');
    }
    
    // Benutzer laden
    $stmt = $conn->prepare("
        SELECT id, username, totp_secret, backup_codes
        FROM auth_users 
        WHERE id = ? AND totp_enabled = TRUE
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        throw new Exception('Invalid user or 2FA not enabled');
    }
    
    $valid = false;
    
    // TOTP Code prüfen
    if (verifyTOTP($user['totp_secret'], $code)) {
        $valid = true;
        logAuthEvent($conn, $user['username'], '2fa_success', 'TOTP');
    } 
    // Backup Code prüfen
    else if ($user['backup_codes']) {
        $backup_codes = json_decode($user['backup_codes'], true);
        $code_hash = hash('sha256', $code);
        
        if (in_array($code_hash, $backup_codes)) {
            $valid = true;
            
            // Backup Code verbrauchen
            $backup_codes = array_values(array_diff($backup_codes, [$code_hash]));
            $stmt = $conn->prepare("
                UPDATE auth_users 
                SET backup_codes = ?
                WHERE id = ?
            ");
            $new_codes = json_encode($backup_codes);
            $stmt->bind_param('si', $new_codes, $user['id']);
            $stmt->execute();
            
            logAuthEvent($conn, $user['username'], '2fa_success', 'Backup code');
        }
    }
    
    if (!$valid) {
        logAuthEvent($conn, $user['username'], '2fa_failed');
        throw new Exception('Invalid 2FA code');
    }
    
    // Session erstellen
    $session = createSession($conn, $user['id']);
    logAuthEvent($conn, $user['username'], 'login_success');
    
    // User-Info mit is_admin laden
    $stmt = $conn->prepare("SELECT is_admin FROM auth_users WHERE id = ?");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $userInfo = $stmt->get_result()->fetch_assoc();
    
    return [
        'success' => true,
        'session_token' => $session['token'],
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'is_admin' => (bool)$userInfo['is_admin']
        ]
    ];
}

// ===================================================================
// 2FA Setup (QR-Code generieren)
// ===================================================================
function setup2FA($conn, $input) {
    $session_token = $input['session_token'] ?? '';
    
    // Session verifizieren
    $user = getUserFromSession($conn, $session_token);
    if (!$user) {
        throw new Exception('Invalid session');
    }
    
    // Neues TOTP Secret generieren
    $secret = generateTOTPSecret();
    
    // Backup Codes generieren (10 Stück)
    $backup_codes = [];
    $backup_codes_display = [];
    for ($i = 0; $i < 10; $i++) {
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $backup_codes_display[] = $code;
        $backup_codes[] = hash('sha256', $code);
    }
    
    // In DB speichern (noch nicht aktiviert)
    $stmt = $conn->prepare("
        UPDATE auth_users 
        SET totp_secret = ?, backup_codes = ?
        WHERE id = ?
    ");
    $codes_json = json_encode($backup_codes);
    $stmt->bind_param('ssi', $secret, $codes_json, $user['id']);
    $stmt->execute();
    
    // QR-Code URL generieren
    $issuer = 'DogMentalityTest';
    $label = $user['username'];
    $qr_url = "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}";
    
    return [
        'success' => true,
        'secret' => $secret,
        'qr_url' => $qr_url,
        'backup_codes' => $backup_codes_display
    ];
}

// ===================================================================
// 2FA aktivieren (nach erfolgreicher Code-Verifizierung)
// ===================================================================
function enable2FA($conn, $input) {
    $session_token = $input['session_token'] ?? '';
    $code = $input['code'] ?? '';
    
    // Session verifizieren
    $user = getUserFromSession($conn, $session_token);
    if (!$user) {
        throw new Exception('Invalid session');
    }
    
    // User-Daten mit Secret holen
    $stmt = $conn->prepare("
        SELECT totp_secret, backup_codes 
        FROM auth_users 
        WHERE id = ? AND totp_secret IS NOT NULL
    ");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $userData = $result->fetch_assoc();
    
    if (!$userData || !$userData['totp_secret']) {
        throw new Exception('2FA not configured. Please setup 2FA first.');
    }
    
    // Code verifizieren
    if (!verifyTOTP($userData['totp_secret'], $code)) {
        throw new Exception('Invalid verification code');
    }
    
    // 2FA aktivieren
    $stmt = $conn->prepare("
        UPDATE auth_users 
        SET totp_enabled = TRUE 
        WHERE id = ?
    ");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    
    logAuthEvent($conn, $user['username'], '2fa_enabled', 'User enabled 2FA');
    
    // Backup-Codes zurückgeben
    $backup_codes_raw = json_decode($userData['backup_codes'], true);
    
    return [
        'success' => true,
        'message' => '2FA successfully enabled'
    ];
}

// ===================================================================
// 2FA deaktivieren
// ===================================================================
function disable2FA($conn, $input) {
    $session_token = $input['session_token'] ?? '';
    
    // Session verifizieren
    $user = getUserFromSession($conn, $session_token);
    if (!$user) {
        throw new Exception('Invalid session');
    }
    
    // 2FA deaktivieren und Secrets löschen
    $stmt = $conn->prepare("
        UPDATE auth_users 
        SET totp_enabled = FALSE,
            totp_secret = NULL,
            backup_codes = NULL
        WHERE id = ?
    ");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    
    logAuthEvent($conn, $user['username'], '2fa_disabled', 'User disabled 2FA');
    
    return [
        'success' => true,
        'message' => '2FA successfully disabled'
    ];
}

// ===================================================================
// Session verifizieren
// ===================================================================
function verifySession($conn, $input) {
    $token = $input['session_token'] ?? '';
    
    $user = getUserFromSession($conn, $token);
    
    if (!$user) {
        return [
            'success' => false,
            'valid' => false
        ];
    }
    
    // Erweiterte User-Daten für Session-Verifizierung
    $stmt = $conn->prepare("
        SELECT totp_enabled, email, full_name, avatar, created_at, last_login
        FROM auth_users
        WHERE id = ?
    ");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $userDetails = $result->fetch_assoc();
    
    return [
        'success' => true,
        'valid' => true,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'is_admin' => (bool)$user['is_admin'],
            'totp_enabled' => (bool)($userDetails['totp_enabled'] ?? false),
            'email' => $userDetails['email'],
            'full_name' => $userDetails['full_name'],
            'avatar' => $userDetails['avatar'],
            'created_at' => $userDetails['created_at'],
            'last_login' => $userDetails['last_login']
        ]
    ];
}

// ===================================================================
// Logout
// ===================================================================
function handleLogout($conn, $token) {
    $token = str_replace('Bearer ', '', $token);
    
    $stmt = $conn->prepare("DELETE FROM auth_sessions WHERE session_token = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    
    return ['success' => true];
}

// ===================================================================
// Passwort ändern
// ===================================================================
function changePassword($conn, $input) {
    $session_token = $input['session_token'] ?? '';
    $old_password = $input['old_password'] ?? '';
    $new_password = $input['new_password'] ?? '';
    
    if (strlen($new_password) < 8) {
        throw new Exception('Password must be at least 8 characters');
    }
    
    $user = getUserFromSession($conn, $session_token);
    if (!$user) {
        throw new Exception('Invalid session');
    }
    
    // Altes Passwort prüfen
    $stmt = $conn->prepare("SELECT password_hash FROM auth_users WHERE id = ?");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    if (!password_verify($old_password, $data['password_hash'])) {
        throw new Exception('Current password incorrect');
    }
    
    // Neues Passwort setzen
    $hash = password_hash($new_password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("UPDATE auth_users SET password_hash = ? WHERE id = ?");
    $stmt->bind_param('si', $hash, $user['id']);
    $stmt->execute();
    
    return ['success' => true];
}

// ===================================================================
// Hilfsfunktionen
// ===================================================================

function createSession($conn, $user_id) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 86400); // 24h
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $conn->prepare("
        INSERT INTO auth_sessions (user_id, session_token, ip_address, user_agent, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('issss', $user_id, $token, $ip, $ua, $expires);
    $stmt->execute();
    
    // Last login aktualisieren
    $stmt = $conn->prepare("UPDATE auth_users SET last_login = NOW() WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    
    return ['token' => $token, 'expires' => $expires];
}

function getUserFromSession($conn, $token) {
    // Abgelaufene Sessions löschen
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

function generateTOTPSecret() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 16; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}

function verifyTOTP($secret, $code, $window = 1) {
    $time = floor(time() / 30);
    
    for ($i = -$window; $i <= $window; $i++) {
        if (generateTOTPCode($secret, $time + $i) === $code) {
            return true;
        }
    }
    
    return false;
}

function generateTOTPCode($secret, $time) {
    $key = base32Decode($secret);
    $time = pack('N*', 0, $time);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0xf;
    $code = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        (ord($hash[$offset + 3]) & 0xff)
    ) % 1000000;
    
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

function base32Decode($secret) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper($secret);
    $decoded = '';
    $buffer = 0;
    $bitsLeft = 0;
    
    for ($i = 0; $i < strlen($secret); $i++) {
        $val = strpos($chars, $secret[$i]);
        if ($val === false) continue;
        
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        
        if ($bitsLeft >= 8) {
            $decoded .= chr(($buffer >> ($bitsLeft - 8)) & 0xFF);
            $bitsLeft -= 8;
        }
    }
    
    return $decoded;
}

function logAuthEvent($conn, $username, $action, $note = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $conn->prepare("
        INSERT INTO auth_logs (username, action, ip_address, user_agent)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('ssss', $username, $action, $ip, $ua);
    $stmt->execute();
}
