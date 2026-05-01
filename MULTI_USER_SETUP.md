# Schnellstart: Multi-User System aktivieren

## Problem

Ihr Testuser sieht alle Daten? Das liegt daran, dass bestehende Hunde und Sessions keine `user_id` haben.

## Lösung in 3 Schritten

### 1️⃣ Datenbank migrieren

```sql
-- Hunde
ALTER TABLE dogs 
ADD COLUMN user_id INT DEFAULT NULL AFTER id,
ADD INDEX idx_user_id (user_id);

-- Sessions
ALTER TABLE test_sessions 
ADD COLUMN user_id INT DEFAULT NULL AFTER battery_id,
ADD INDEX idx_user_id (user_id);
```

**Oder nutzen Sie die Migrations-Scripts:**
```bash
mysql -u username -p datenbankname < database/migration-dog-user.sql
mysql -u username -p datenbankname < database/migration-session-user.sql
```

### 2️⃣ Bestehende Daten zuordnen

**Alle alten Daten dem Admin zuordnen:**
```bash
mysql -u username -p datenbankname < database/assign-to-admin.sql
```

**Oder manuell:**
```sql
-- Hunde
UPDATE dogs
SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1)
WHERE user_id IS NULL;

-- Sessions
UPDATE test_sessions
SET user_id = (SELECT id FROM auth_users WHERE is_admin = TRUE LIMIT 1)
WHERE user_id IS NULL;
```

### 3️⃣ API-Dateien deployen

Aktualisieren Sie diese Dateien auf dem Server:
- `api/dogs.php` (erweitert um User-Filterung)
- `api/sessions.php` (bereits erweitert)
- `database/schema.sql` (für neue Installationen)

## Fertig! ✅

**Nach diesen Schritten:**

### Normaler User (z.B. Testuser)
- ✅ Sieht nur seine eigenen Hunde
- ✅ Sieht nur seine eigenen Sessions
- ❌ Kann fremde Hunde nicht sehen
- ❌ Kann fremde Sessions nicht bearbeiten

### Administrator
- ✅ Sieht alle Hunde aller Benutzer
- ✅ Sieht alle Sessions aller Benutzer
- ✅ Kann alles bearbeiten und löschen
- ✅ Kann Benutzer verwalten

## Testen

### Als normaler User:
1. Login mit Testuser
2. Neue Hunde erstellen → Nur diese sind sichtbar
3. Alte Hunde sollten NICHT sichtbar sein (gehören jetzt Admin)

### Als Admin:
1. Login als Admin
2. Alle Hunde und Sessions sichtbar (alte + neue)
3. Kann fremde Daten bearbeiten/löschen

## Workflow

```
User erstellt Hund → user_id wird automatisch gesetzt
         ↓
User erstellt Session → user_id wird automatisch gesetzt
         ↓
User sieht nur eigene Hunde/Sessions
         ↓
Admin sieht alle Daten
```

## Wichtig

- **Testbatterien** sind GLOBAL (keine user_id)
- Alle User können alle Testbatterien nutzen
- Nur Admins können Testbatterien hochladen/verwalten

## Frontend

Keine Änderungen nötig! Die Filterung erfolgt automatisch im Backend.

## Troubleshooting

**Q: Testuser sieht immer noch alles?**
A: Haben Sie Schritt 2 (assign-to-admin.sql) ausgeführt?

**Q: Admin sieht nichts mehr?**
A: Prüfen Sie ob `is_admin = TRUE` in auth_users gesetzt ist.

**Q: Neue Hunde haben keine user_id?**
A: Sind Sie eingeloggt? Token wird benötigt für automatische Zuordnung.

## Support

Detaillierte Dokumentation: [MULTI_USER_SYSTEM.md](MULTI_USER_SYSTEM.md)
