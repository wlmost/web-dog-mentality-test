-- 2FA Authentication Schema
-- Erweitert das bestehende Schema um Authentifizierung

-- ===================================================================
-- Tabelle: auth_users
-- Speichert Benutzer mit 2FA-Daten
-- ===================================================================
CREATE TABLE IF NOT EXISTS {{PREFIX}}auth_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    full_name VARCHAR(100) DEFAULT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    totp_secret VARCHAR(32) DEFAULT NULL,
    totp_enabled BOOLEAN DEFAULT FALSE,
    backup_codes TEXT DEFAULT NULL,  -- JSON Array von Backup-Codes
    failed_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL DEFAULT NULL,
    last_login TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_avatar (avatar),
    INDEX idx_locked (locked_until),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- Tabelle: auth_sessions
-- Speichert aktive Login-Sessions
-- ===================================================================
CREATE TABLE IF NOT EXISTS {{PREFIX}}auth_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES {{PREFIX}}auth_users(id) ON DELETE CASCADE,
    INDEX idx_token (session_token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- Tabelle: auth_logs
-- Speichert Login-Versuche für Audit
-- ===================================================================
CREATE TABLE IF NOT EXISTS {{PREFIX}}auth_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    action ENUM('login_success', 'login_failed', 'logout', '2fa_success', '2fa_failed', 'account_locked') NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- Standard-Admin erstellen
-- Passwort: "admin123" (BITTE ÄNDERN!)
-- ===================================================================
INSERT INTO {{PREFIX}}auth_users (username, password_hash, email, full_name, is_admin, totp_enabled) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@localhost', 'Administrator', TRUE, FALSE)
ON DUPLICATE KEY UPDATE 
    is_admin = TRUE,
    is_active = TRUE;

-- Hinweis: Das Standard-Passwort ist "admin123"
-- Bitte ändere es sofort nach dem ersten Login!
