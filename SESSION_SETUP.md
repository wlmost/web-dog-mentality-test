# Schnellstart: Session User-Zuordnung

## Installation in 3 Schritten

### 1️⃣ Datenbank migrieren

Führen Sie die Migration aus:

**Option A - Mit MySQL-Client:**
```bash
mysql -u username -p datenbankname < database/migration-session-user.sql
```

**Option B - Manuell in phpMyAdmin/HeidiSQL:**
```sql
ALTER TABLE test_sessions 
ADD COLUMN user_id INT DEFAULT NULL AFTER battery_id,
ADD INDEX idx_user_id (user_id);
```

**Optional - Foreign Key hinzufügen:**
```sql
ALTER TABLE test_sessions 
ADD CONSTRAINT fk_session_user 
FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE SET NULL;
```

### 2️⃣ View aktualisieren

```sql
DROP VIEW IF EXISTS v_session_overview;

CREATE VIEW v_session_overview AS
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
```

### 3️⃣ API-Datei deployen

Aktualisieren Sie die Datei auf dem Server:
- `api/sessions.php`

## Fertig! ✅

Die Session User-Zuordnung ist nun aktiv.

## Bestehende Sessions zuordnen (optional)

Falls Sie bestehende Sessions einem Benutzer zuordnen möchten:

```sql
-- Alle Sessions dem ersten Admin zuordnen
UPDATE test_sessions ts
SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1)
WHERE user_id IS NULL;
```

## Testen

1. **Als normaler User einloggen**
2. **Neue Session erstellen** → Wird automatisch dem User zugeordnet
3. **Sessions anzeigen** → Nur eigene Sessions sichtbar
4. **Als Admin einloggen** → Alle Sessions sichtbar

## Verhalten

| Aktion | Normaler User | Admin | Ohne Login |
|--------|---------------|-------|------------|
| Sessions auflisten | Nur eigene | Alle | Alle |
| Session erstellen | user_id = eigene ID | user_id = eigene ID | user_id = NULL |
| Eigene Session bearbeiten | ✅ Erlaubt | ✅ Erlaubt | ✅ Erlaubt |
| Fremde Session bearbeiten | ❌ 403 Fehler | ✅ Erlaubt | ✅ Erlaubt |
| Eigene Session löschen | ✅ Erlaubt | ✅ Erlaubt | ✅ Erlaubt |
| Fremde Session löschen | ❌ 403 Fehler | ✅ Erlaubt | ✅ Erlaubt |

## Support

Bei Fragen oder Problemen siehe [SESSION_USER_ASSIGNMENT.md](SESSION_USER_ASSIGNMENT.md) für Details.
