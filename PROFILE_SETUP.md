# Schnellstart: Profilverwaltung einrichten

## Für bestehende Installationen

### Schritt 1: Datenbank migrieren

```sql
-- Für bestehende auth_users Tabelle:
ALTER TABLE auth_users 
ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER full_name;

CREATE INDEX idx_avatar ON auth_users(avatar);
```

### Schritt 2: Upload-Verzeichnis erstellen

**Windows (PowerShell):**
```powershell
New-Item -ItemType Directory -Force -Path uploads\avatars
```

**Linux/Mac:**
```bash
mkdir -p uploads/avatars
chmod 755 uploads/avatars
```

### Schritt 3: Dateien deployen

Die folgenden neuen Dateien müssen deployed werden:
- `api/profile.php`
- `frontend/profile.html`
- `frontend/js/profile.js`
- `uploads/avatars/.gitignore` (optional)

### Schritt 4: Frontend aktualisieren

Die Datei `frontend/index.html` wurde um den Profil-Menüpunkt erweitert. Aktualisieren Sie diese Datei auf dem Server.

### Schritt 5: Testen

1. Login in die Anwendung
2. Klick auf "Profil" in der Navigation
3. Avatar hochladen testen
4. E-Mail-Adresse ändern testen
5. Passwort ändern testen

## Für neue Installationen

Verwenden Sie das aktualisierte `database/schema-auth.sql` - es enthält bereits die Avatar-Spalte.

## Fertig! 🎉

Die Profilverwaltung ist nun einsatzbereit.
