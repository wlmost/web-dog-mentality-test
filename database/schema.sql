-- Dog Mentality Test - Datenbank Schema
-- Erstellt: 2025-12-15
-- MySQL 5.7+ kompatibel

-- Datenbank erstellen (optional, falls noch nicht vorhanden)
-- CREATE DATABASE IF NOT EXISTS dog_mentality CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE dog_mentality;

-- ===================================================================
-- Tabelle: dogs
-- Speichert Stammdaten von Hund und Halter
-- ===================================================================
CREATE TABLE IF NOT EXISTS dogs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    owner_name VARCHAR(100) NOT NULL,
    dog_name VARCHAR(100) NOT NULL,
    breed VARCHAR(100) DEFAULT '',
    age_years INT NOT NULL,
    age_months INT NOT NULL DEFAULT 0,
    gender ENUM('Rüde', 'Hündin') NOT NULL,
    neutered BOOLEAN DEFAULT FALSE,
    intended_use VARCHAR(200) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_dog_name (dog_name),
    INDEX idx_owner_name (owner_name),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- Tabelle: test_batteries
-- Speichert Testbatterien (importiert aus Excel)
-- ===================================================================
CREATE TABLE IF NOT EXISTS test_batteries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- Tabelle: battery_tests
-- Speichert einzelne Tests einer Testbatterie
-- ===================================================================
CREATE TABLE IF NOT EXISTS battery_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    battery_id INT NOT NULL,
    test_number INT NOT NULL,
    ocean_dimension ENUM('Offenheit', 'Gewissenhaftigkeit', 'Extraversion', 'Verträglichkeit', 'Neurotizismus') NOT NULL,
    name VARCHAR(200) NOT NULL,
    setting TEXT,
    materials TEXT,
    duration VARCHAR(100),
    role_figurant TEXT,
    observation_criteria TEXT,
    rating_scale TEXT,
    
    FOREIGN KEY (battery_id) REFERENCES test_batteries(id) ON DELETE CASCADE,
    UNIQUE KEY unique_battery_test (battery_id, test_number),
    INDEX idx_ocean_dimension (ocean_dimension)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- Tabelle: test_sessions
-- Speichert Test-Sessions mit optionalen KI-Profilen
-- ===================================================================
CREATE TABLE IF NOT EXISTS test_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dog_id INT NOT NULL,
    battery_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    session_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    session_notes TEXT,
    
    -- KI-Features (JSON-Format für OCEAN-Werte)
    ideal_profile JSON COMMENT 'KI-generiertes Idealprofil: {"O": 10, "C": 12, ...}',
    owner_profile JSON COMMENT 'Halter-Erwartungsprofil: {"O": 8, "C": 10, ...}',
    ai_assessment TEXT COMMENT 'KI-Bewertung basierend auf 3 Profilen',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (dog_id) REFERENCES dogs(id) ON DELETE CASCADE,
    FOREIGN KEY (battery_id) REFERENCES test_batteries(id) ON DELETE RESTRICT,
    INDEX idx_session_date (session_date),
    INDEX idx_dog_id (dog_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- Tabelle: test_results
-- Speichert einzelne Testergebnisse einer Session
-- ===================================================================
CREATE TABLE IF NOT EXISTS test_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    test_number INT NOT NULL,
    score INT NOT NULL CHECK (score BETWEEN -2 AND 2),
    notes TEXT,
    
    FOREIGN KEY (session_id) REFERENCES test_sessions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_test (session_id, test_number),
    INDEX idx_session_id (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- Tabelle: users (optional - für Multi-User-System)
-- ===================================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    
    INDEX idx_username (username),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- Views: Häufig benötigte Abfragen
-- ===================================================================

-- View: Vollständige Session-Informationen
CREATE OR REPLACE VIEW v_session_overview AS
SELECT 
    ts.id AS session_id,
    ts.session_date,
    ts.session_notes,
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
    COUNT(tr.id) AS completed_tests,
    ts.ideal_profile,
    ts.owner_profile,
    ts.ai_assessment,
    CASE WHEN ts.ideal_profile IS NOT NULL THEN 1 ELSE 0 END AS has_ideal_profile
FROM test_sessions ts
JOIN dogs d ON ts.dog_id = d.id
JOIN test_batteries tb ON ts.battery_id = tb.id
LEFT JOIN test_results tr ON ts.id = tr.session_id
GROUP BY ts.id, ts.session_date, ts.session_notes, ts.user_id, d.id, d.dog_name, 
         d.breed, d.age_years, d.age_months, d.gender, d.owner_name, 
         d.intended_use, tb.name, ts.ideal_profile, ts.owner_profile, ts.ai_assessment;

-- View: OCEAN-Scores Berechnung pro Session
CREATE OR REPLACE VIEW v_ocean_scores AS
SELECT 
    tr.session_id,
    bt.ocean_dimension,
    SUM(tr.score) AS total_score,
    COUNT(tr.id) AS test_count,
    ROUND(AVG(tr.score), 2) AS average_score
FROM test_results tr
JOIN test_sessions ts ON tr.session_id = ts.id
JOIN battery_tests bt ON ts.battery_id = bt.battery_id AND tr.test_number = bt.test_number
GROUP BY tr.session_id, bt.ocean_dimension;

-- ===================================================================
-- Beispiel-Daten zum Testen (optional)
-- ===================================================================

-- Beispiel-Testbatterie
INSERT INTO test_batteries (name, description) VALUES 
('Testbatterie OCEAN - Freigelände V4', 'Standardtestbatterie für tiergestützte Arbeit')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Beispiel-Hund
INSERT INTO dogs (owner_name, dog_name, breed, age_years, age_months, gender, neutered, intended_use) VALUES 
('Max Mustermann', 'Bello', 'Golden Retriever', 3, 6, 'Rüde', TRUE, 'Therapiehund')
ON DUPLICATE KEY UPDATE owner_name = VALUES(owner_name);

-- ===================================================================
-- Hinweis: Trigger wurden in separate Datei ausgelagert
-- Die Validierung erfolgt bereits in der PHP-Anwendungslogik (api/dogs.php)
-- Trigger sind OPTIONAL und bei vielen Hostern (z.B. netbeat) NICHT verfügbar
-- Siehe: database/triggers.sql (nur wenn Ihr Server Trigger unterstützt)
-- ===================================================================

-- ===================================================================
-- Indexes für Performance-Optimierung
-- ===================================================================

-- Composite Index für häufige Session-Abfragen
CREATE INDEX idx_session_dog_battery ON test_sessions(dog_id, battery_id, session_date DESC);

-- Fulltext-Index für Textsuche (optional)
-- ALTER TABLE dogs ADD FULLTEXT INDEX ft_dog_search (dog_name, breed, owner_name);
-- ALTER TABLE battery_tests ADD FULLTEXT INDEX ft_test_search (name, observation_criteria);

-- ===================================================================
-- ENDE SCHEMA
-- ===================================================================
