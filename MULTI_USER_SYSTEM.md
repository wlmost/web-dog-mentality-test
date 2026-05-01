# Multi-User System - Vollständige Implementierung

## Übersicht

Das System ist nun vollständig multi-user-fähig:

### Normale Benutzer sehen nur:
- ✅ **Ihre eigenen Hunde** (dogs mit user_id = eigene ID)
- ✅ **Ihre eigenen Sessions** (test_sessions mit user_id = eigene ID)
- ❌ Keine Hunde anderer Benutzer
- ❌ Keine Sessions anderer Benutzer

### Administratoren sehen:
- ✅ **Alle Hunde** aller Benutzer
- ✅ **Alle Sessions** aller Benutzer
- ✅ **Alle Testbatterien** (sind global für alle)

## Architektur

```
User 1 (Normal)
├── Hund A (user_id=1)
│   ├── Session 1 (user_id=1)
│   └── Session 2 (user_id=1)
└── Hund B (user_id=1)
    └── Session 3 (user_id=1)

User 2 (Normal)
├── Hund C (user_id=2)
│   └── Session 4 (user_id=2)
└── Hund D (user_id=2)

Admin (is_admin=TRUE)
└── Sieht: Hund A, B, C, D + alle Sessions
```

## Implementierte Änderungen

### 1. Datenbank-Schema

**dogs Tabelle:**
```sql
ALTER TABLE dogs 
ADD COLUMN user_id INT DEFAULT NULL AFTER id,
ADD INDEX idx_user_id (user_id);
```

**test_sessions Tabelle:**
```sql
ALTER TABLE test_sessions 
ADD COLUMN user_id INT DEFAULT NULL AFTER battery_id,
ADD INDEX idx_user_id (user_id);
```

### 2. API-Filterung

#### dogs.php
- **GET alle Hunde**: Normale User sehen nur `WHERE user_id = <eigene ID>`
- **GET einzelner Hund**: Berechtigungsprüfung
- **POST neuer Hund**: Automatische user_id Zuweisung
- **PUT Hund bearbeiten**: Nur Eigentümer oder Admin
- **DELETE Hund löschen**: Nur Eigentümer oder Admin

#### sessions.php
- **GET alle Sessions**: Normale User sehen nur `WHERE user_id = <eigene ID>`
- **GET einzelne Session**: Berechtigungsprüfung
- **POST neue Session**: Automatische user_id Zuweisung
- **PUT Session bearbeiten**: Nur Eigentümer oder Admin
- **DELETE Session löschen**: Nur Eigentümer oder Admin

## Migration

### Schritt 1: Schema aktualisieren

```sql
-- Hunde
ALTER TABLE dogs 
ADD COLUMN user_id INT DEFAULT NULL AFTER id,
ADD INDEX idx_user_id (user_id);

-- Sessions
ALTER TABLE test_sessions 
ADD COLUMN user_id INT DEFAULT NULL AFTER battery_id,
ADD INDEX idx_user_id (user_id);

-- View aktualisieren
DROP VIEW IF EXISTS v_session_overview;
CREATE VIEW v_session_overview AS
SELECT 
    ts.id AS session_id,
    ts.session_date,
    ts.session_notes,
    ts.user_id,
    d.id AS dog_id,
    -- ... weitere Felder
FROM test_sessions ts
JOIN dogs d ON ts.dog_id = d.id
-- ... Rest der View
```

### Schritt 2: Bestehende Daten zuordnen

**Option A - Alle Daten dem ersten Admin zuordnen:**
```sql
-- Alle Hunde
UPDATE dogs
SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1)
WHERE user_id IS NULL;

-- Alle Sessions
UPDATE test_sessions
SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1)
WHERE user_id IS NULL;
```

**Option B - Daten unzugeordnet lassen:**
```sql
-- Nichts tun - user_id bleibt NULL
-- Diese Daten sehen alle Benutzer (auch Normale)
```

**Option C - Daten einem spezifischen User zuordnen:**
```sql
-- Hunde von "Max Mustermann" dem User mit ID 5 zuordnen
UPDATE dogs d
SET user_id = 5
WHERE owner_name = 'Max Mustermann' AND user_id IS NULL;

-- Zugehörige Sessions auch zuordnen
UPDATE test_sessions ts
JOIN dogs d ON ts.dog_id = d.id
SET ts.user_id = d.user_id
WHERE ts.user_id IS NULL AND d.user_id = 5;
```

### Schritt 3: API-Dateien deployen

Aktualisieren Sie:
- `api/dogs.php`
- `api/sessions.php`

## Problem-Lösung: Testuser sieht alles

**Ursache:**
- Bestehende Hunde haben `user_id = NULL`
- Bestehende Sessions haben `user_id = NULL`
- NULL-Werte werden nicht gefiltert → Alle User sehen sie

**Lösung:**
```sql
-- 1. Alle alten Hunde dem Admin zuordnen
UPDATE dogs
SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1)
WHERE user_id IS NULL;

-- 2. Alle alten Sessions dem Admin zuordnen
UPDATE test_sessions
SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1)
WHERE user_id IS NULL;
```

**Danach:**
- Testuser sieht nur noch seine eigenen Hunde/Sessions
- Admin sieht weiterhin alles

## Workflow

### Normale User

1. **Einloggen**
2. **Hund anlegen** → Wird automatisch user_id = <eigene ID> zugeordnet
3. **Testbatterie auswählen** (global verfügbar)
4. **Session erstellen** → Wird automatisch user_id = <eigene ID> zugeordnet
5. **Nur eigene Daten sichtbar**

### Admin

1. **Einloggen**
2. **Alle Hunde sehen** (eigene + fremde)
3. **Alle Sessions sehen** (eigene + fremde)
4. **Jede Session bearbeiten/löschen**
5. **Benutzer verwalten** (users.php)

## Testbatterien

**Testbatterien sind GLOBAL:**
- Haben keine user_id
- Sind für alle Benutzer sichtbar
- Können von Admins verwaltet werden (über batteries.php)
- Normale User wählen aus verfügbaren Batterien aus

## Sicherheit

### API-Ebene
```php
// Automatische Filterung in allen GET-Requests
if ($currentUser && !$currentUser['is_admin']) {
    $sql .= " AND user_id = ?";
    $params[] = $currentUser['id'];
}
```

### Berechtigungsprüfung
```php
// Bei PUT/DELETE
if ($currentUser && !$currentUser['is_admin']) {
    if ($entity['user_id'] != $currentUser['id']) {
        sendError('Keine Berechtigung', 403);
    }
}
```

## Frontend

**Keine Änderungen erforderlich!**

Das Frontend sendet automatisch den Session-Token:
```javascript
const token = localStorage.getItem('session_token');
fetch('/api/dogs.php', {
    headers: {
        'Authorization': `Bearer ${token}`
    }
});
```

Die API filtert automatisch basierend auf dem Token.

## Testen

### Test 1: Normaler User
```bash
# Als User einloggen
# Hund erstellen → Eigene user_id wird gesetzt
# Hunde auflisten → Nur eigene Hunde sichtbar
# Fremden Hund bearbeiten → 403 Fehler
```

### Test 2: Admin
```bash
# Als Admin einloggen
# Hunde auflisten → Alle Hunde sichtbar
# Fremden Hund bearbeiten → Erfolgreich
# Fremden Hund löschen → Erfolgreich
```

### Test 3: Ohne Login
```bash
# Hunde auflisten → Alle Hunde mit user_id=NULL sichtbar
# Session erstellen → user_id=NULL wird gesetzt
```

## FAQ

**Q: Warum sieht mein Testuser alle Daten?**
A: Alte Daten haben user_id=NULL. Führen Sie die Migration aus (siehe oben).

**Q: Können Users Testbatterien hochladen?**
A: Nein, nur Admins. Testbatterien sind global.

**Q: Was passiert wenn ein User gelöscht wird?**
A: user_id wird auf NULL gesetzt (ON DELETE SET NULL). Daten bleiben erhalten.

**Q: Kann ich Sessions zwischen Usern teilen?**
A: Aktuell nein. Zukünftige Erweiterung möglich.

## Zusammenfassung

✅ Dogs haben user_id  
✅ Sessions haben user_id  
✅ API filtert nach user_id  
✅ Admins sehen alles  
✅ Normale User sehen nur eigene Daten  
✅ Automatische Zuordnung bei Erstellung  
✅ Berechtigungsprüfung bei Bearbeitung/Löschung
