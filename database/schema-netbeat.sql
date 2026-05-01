-- Dog Mentality Test - Datenbank Schema (netbeat-kompatibel)
-- Erstellt: 2025-12-15
-- MySQL 5.7+ kompatibel
-- OHNE TRIGGER (wegen fehlender SUPER-Berechtigung bei Shared Hosting)

-- ===================================================================
-- Tabelle: dogs
-- Speichert Stammdaten von Hund und Halter
-- ===================================================================
CREATE TABLE IF NOT EXISTS dogs (
    id INT AUTO_INCREMENT PRIMARY KEY,
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
    INDEX idx_owner_name (owner_name)
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
    INDEX idx_dog_id (dog_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- Tabelle: test_results
-- Speichert einzelne Testergebnisse einer Session
-- ===================================================================
CREATE TABLE IF NOT EXISTS test_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    test_number INT NOT NULL,
    score INT NOT NULL,
    notes TEXT,
    
    FOREIGN KEY (session_id) REFERENCES test_sessions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_test (session_id, test_number),
    INDEX idx_session_id (session_id),
    
    -- CHECK Constraint für Score-Validierung (MySQL 8.0.16+)
    -- Falls MySQL < 8.0.16: Validierung erfolgt in PHP
    CONSTRAINT chk_score CHECK (score BETWEEN -2 AND 2)
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
    ts.ai_assessment
FROM test_sessions ts
JOIN dogs d ON ts.dog_id = d.id
JOIN test_batteries tb ON ts.battery_id = tb.id
LEFT JOIN test_results tr ON ts.id = tr.session_id
GROUP BY ts.id, ts.session_date, ts.session_notes, d.id, d.dog_name, 
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
-- Indexes für Performance-Optimierung
-- ===================================================================

-- Composite Index für häufige Session-Abfragen
CREATE INDEX idx_session_dog_battery ON test_sessions(dog_id, battery_id, session_date DESC);

-- ===================================================================
-- HINWEIS: Validierung erfolgt in PHP!
-- ===================================================================
-- Da netbeat keine SUPER-Berechtigung für Trigger erlaubt,
-- wird die Datenvalidierung in der PHP-API durchgeführt:
--
-- 1. Alter (Jahre): 0-20
-- 2. Alter (Monate): 0-11
-- 3. Mindestens 1 Monat Alter
-- 4. Score: -2 bis +2
--
-- Siehe: api/dogs.php und api/results.php
-- ===================================================================

-- ENDE SCHEMA
