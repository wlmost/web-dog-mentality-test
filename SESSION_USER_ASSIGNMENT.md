# Session User-Zuordnung

## Übersicht

Sessions sind nun Benutzern zugeordnet. Das bedeutet:
- **Normale Benutzer** sehen nur ihre eigenen Test-Sessions
- **Administratoren** sehen alle Sessions aller Benutzer
- Neue Sessions werden automatisch dem angemeldeten Benutzer zugeordnet

## Implementierung

### Datenbank

**Neue Spalte in `test_sessions`:**
```sql
user_id INT DEFAULT NULL
```

- `NULL` = Session ohne Benutzerzuordnung (z.B. alte Sessions oder System-Sessions)
- Integer = ID des zugeordneten Benutzers aus `auth_users`

### API-Verhalten

#### GET /api/sessions.php
- **Admin**: Sieht alle Sessions
- **Normaler User**: Sieht nur Sessions mit `user_id = <eigene ID>`
- **Nicht angemeldet**: Sieht alle Sessions (Abwärtskompatibilität)

#### GET /api/sessions.php?id=123
- **Admin**: Kann jede Session abrufen
- **Normaler User**: Kann nur eigene Sessions abrufen (403 bei fremder Session)
- **Nicht angemeldet**: Kann jede Session abrufen (Abwärtskompatibilität)

#### POST /api/sessions.php
- Neue Sessions erhalten automatisch die `user_id` des angemeldeten Benutzers
- Falls nicht angemeldet: `user_id = NULL`

#### PUT /api/sessions.php?id=123
- **Admin**: Kann jede Session bearbeiten
- **Normaler User**: Kann nur eigene Sessions bearbeiten (403 bei fremder Session)
- **Nicht angemeldet**: Kann jede Session bearbeiten (Abwärtskompatibilität)

#### DELETE /api/sessions.php?id=123
- **Admin**: Kann jede Session löschen
- **Normaler User**: Kann nur eigene Sessions löschen (403 bei fremder Session)
- **Nicht angemeldet**: Kann jede Session löschen (Abwärtskompatibilität)

## Migration

### Für bestehende Datenbanken

```sql
-- Spalte hinzufügen
ALTER TABLE test_sessions 
ADD COLUMN user_id INT DEFAULT NULL AFTER battery_id,
ADD INDEX idx_user_id (user_id);

-- Optional: Foreign Key (wenn auth_users existiert)
ALTER TABLE test_sessions 
ADD CONSTRAINT fk_session_user 
FOREIGN KEY (user_id) REFERENCES auth_users(id) ON DELETE SET NULL;
```

### Bestehende Sessions zuordnen (optional)

Falls Sie bestehende Sessions einem Benutzer zuordnen möchten:

```sql
-- Alle Sessions dem ersten Admin zuordnen
UPDATE test_sessions ts
SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1)
WHERE user_id IS NULL;
```

Oder bestimmte Sessions basierend auf anderen Kriterien:

```sql
-- Sessions basierend auf Hundebesitzer zuordnen (Beispiel)
UPDATE test_sessions ts
JOIN dogs d ON ts.dog_id = d.id
JOIN auth_users u ON u.full_name = d.owner_name
SET ts.user_id = u.id
WHERE ts.user_id IS NULL;
```

## View-Anpassung

Die View `v_session_overview` wurde erweitert:

```sql
CREATE OR REPLACE VIEW v_session_overview AS
SELECT 
    ts.id AS session_id,
    ts.session_date,
    ts.session_notes,
    ts.user_id,  -- NEU
    d.id AS dog_id,
    -- ... weitere Felder
```

## Authentifizierung

Die API verwendet Session-Tokens zur Authentifizierung:

```javascript
// Authorization Header
Authorization: Bearer <session_token>

// Oder als Query-Parameter (Fallback)
?token=<session_token>
```

Der Token wird aus der `auth_sessions` Tabelle validiert und dem aktuellen Benutzer zugeordnet.

## Sicherheit

### Berechtigungsprüfung

```php
// Normale User können nur ihre eigenen Sessions sehen/bearbeiten
if ($currentUser && !$currentUser['is_admin']) {
    if ($session['user_id'] != $currentUser['id']) {
        sendError('Keine Berechtigung für diese Session', 403);
    }
}
```

### Automatische Zuordnung

Neue Sessions werden automatisch dem angemeldeten Benutzer zugeordnet:

```php
$user_id = $currentUser ? $currentUser['id'] : null;
```

## Abwärtskompatibilität

- Sessions ohne `user_id` (= NULL) bleiben funktionsfähig
- Nicht angemeldete Benutzer können weiterhin Sessions erstellen (user_id = NULL)
- Frontend funktioniert auch ohne Authentifizierung

## Frontend

Das Frontend muss keine Änderungen vornehmen:
- Session-Token wird automatisch aus `localStorage.getItem('session_token')` geladen
- API-Calls funktionieren wie bisher
- Filterung erfolgt automatisch im Backend

### Beispiel API-Call

```javascript
const token = localStorage.getItem('session_token');
const response = await fetch('/api/sessions.php', {
    method: 'GET',
    headers: {
        'Authorization': `Bearer ${token}`
    }
});
// Normaler User erhält nur seine Sessions
// Admin erhält alle Sessions
```

## Fehlerbehandlung

### 403 Forbidden
User versucht auf fremde Session zuzugreifen:
```json
{
    "error": "Keine Berechtigung für diese Session"
}
```

### 401 Unauthorized
Ungültiger oder abgelaufener Token:
```json
{
    "error": "Invalid or expired session"
}
```

## Logging

Sessions werden mit User-ID geloggt:
```php
logMessage("Neue Session erstellt: ID $newId, Dog ID: $dog_id, User ID: " . ($user_id ?? 'NULL'));
```

## Beispiele

### Normale User sehen nur eigene Sessions

**User A (ID=2):**
```sql
SELECT * FROM test_sessions WHERE user_id = 2;
-- Ergebnis: 5 Sessions
```

**User B (ID=3):**
```sql
SELECT * FROM test_sessions WHERE user_id = 3;
-- Ergebnis: 2 Sessions
```

### Admin sieht alle Sessions

**Admin (ID=1, is_admin=TRUE):**
```sql
SELECT * FROM test_sessions;
-- Ergebnis: Alle 7 Sessions (5 + 2)
```

## Testing

### Testszenarien

1. **Als normaler User einloggen**
   - Session erstellen → user_id wird automatisch gesetzt
   - Sessions auflisten → Nur eigene Sessions sichtbar
   - Eigene Session bearbeiten → Erfolgreich
   - Fremde Session bearbeiten → 403 Fehler

2. **Als Admin einloggen**
   - Sessions auflisten → Alle Sessions sichtbar
   - Fremde Session bearbeiten → Erfolgreich
   - Fremde Session löschen → Erfolgreich

3. **Ohne Login**
   - Sessions auflisten → Alle Sessions sichtbar
   - Session erstellen → user_id = NULL
   - Session bearbeiten → Erfolgreich (keine Prüfung)

## Vorteile

- ✅ Multi-User-Unterstützung
- ✅ Datenschutz: User sehen nur ihre Daten
- ✅ Flexibel: Admins haben vollen Zugriff
- ✅ Abwärtskompatibel: Alte Sessions bleiben funktionsfähig
- ✅ Sicher: Berechtigungsprüfung auf API-Ebene

## Weiterentwicklung

Mögliche Erweiterungen:
- Team-Sessions (mehrere User können gemeinsam auf Sessions zugreifen)
- Session-Sharing (User kann Session für andere freigeben)
- Rollen-basierte Zugriffskontrolle (z.B. "Viewer", "Editor", "Owner")
- Activity-Log (wer hat wann welche Session bearbeitet)
