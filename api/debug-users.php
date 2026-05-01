<?php
/**
 * Debug für users.php
 */

// Error Reporting aktivieren
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/plain; charset=utf-8');

echo "=== USERS.PHP DEBUG ===\n\n";

try {
    require_once __DIR__ . '/config.php';
    echo "1. config.php geladen: OK\n";
    
    $conn = getDbConnection();
    echo "2. DB-Verbindung: OK\n";
    
    // Test getUserFromSession Funktion
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
    
    echo "3. getUserFromSession definiert: OK\n";
    
    // Test Token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? 'nicht gesetzt';
    echo "4. Authorization Header: $authHeader\n";
    
    if ($authHeader !== 'nicht gesetzt') {
        $token = str_replace('Bearer ', '', $authHeader);
        echo "5. Token extrahiert: " . substr($token, 0, 10) . "...\n";
        
        $user = getUserFromSession($conn, $token);
        if ($user) {
            echo "6. User gefunden: " . $user['username'] . " (Admin: " . ($user['is_admin'] ? 'ja' : 'nein') . ")\n";
        } else {
            echo "6. FEHLER: Kein User gefunden oder Session abgelaufen\n";
        }
    }
    
    // Test listUsers Funktion
    function listUsers($conn) {
        $stmt = $conn->prepare("
            SELECT 
                id, username, email, full_name, is_admin, is_active,
                totp_enabled, last_login, created_at
            FROM auth_users
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
    
    echo "7. listUsers definiert: OK\n";
    
    $result = listUsers($conn);
    echo "8. listUsers ausgeführt: " . count($result['users']) . " Benutzer gefunden\n";
    
    echo "\n=== ALLE TESTS BESTANDEN ===\n";
    
} catch (Exception $e) {
    echo "\nFEHLER: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
