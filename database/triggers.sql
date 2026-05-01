-- Trigger: Automatische Validierung für Dog Mentality Test
-- 
-- WICHTIG: Diese Trigger sind OPTIONAL und funktionieren nicht auf allen Hosting-Providern!
-- Bei netbeat und vielen anderen Shared-Hosting-Anbietern werden Trigger nicht unterstützt.
--
-- Die Validierung ist bereits in der PHP-Anwendungslogik (api/dogs.php) implementiert,
-- daher ist diese Datei NICHT zwingend erforderlich.
--
-- Diese Trigger dienen nur als zusätzliche Datenbank-Ebene Sicherheit.
-- Falls Ihr Hosting-Provider Trigger nicht unterstützt, können Sie diese Datei ignorieren.
--
-- Import (nur wenn Ihr Server Trigger unterstützt): 
-- mysql -u username -p datenbankname < triggers.sql

-- ===================================================================
-- Trigger: Validiere Alter (Jahre 0-20, Monate 0-11)
-- ===================================================================

DROP TRIGGER IF EXISTS validate_dog_age_before_insert;
DROP TRIGGER IF EXISTS validate_dog_age_before_update;

DELIMITER $$

CREATE TRIGGER validate_dog_age_before_insert
BEFORE INSERT ON dogs
FOR EACH ROW
BEGIN
    IF NEW.age_years < 0 OR NEW.age_years > 20 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Alter (Jahre) muss zwischen 0 und 20 liegen';
    END IF;
    IF NEW.age_months < 0 OR NEW.age_months > 11 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Alter (Monate) muss zwischen 0 und 11 liegen';
    END IF;
    IF NEW.age_years = 0 AND NEW.age_months = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Alter muss mindestens 1 Monat betragen';
    END IF;
END$$

CREATE TRIGGER validate_dog_age_before_update
BEFORE UPDATE ON dogs
FOR EACH ROW
BEGIN
    IF NEW.age_years < 0 OR NEW.age_years > 20 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Alter (Jahre) muss zwischen 0 und 20 liegen';
    END IF;
    IF NEW.age_months < 0 OR NEW.age_months > 11 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Alter (Monate) muss zwischen 0 und 11 liegen';
    END IF;
    IF NEW.age_years = 0 AND NEW.age_months = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Alter muss mindestens 1 Monat betragen';
    END IF;
END$$

DELIMITER ;

-- Hinweis: Trigger sind optional und dienen der Datenvalidierung
-- Falls Sie sie nicht benötigen, können Sie diese Datei ignorieren
