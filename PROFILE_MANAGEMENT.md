# Profilverwaltung

## Übersicht

Die Anwendung wurde um eine Profilverwaltung erweitert, die es Benutzern ermöglicht:
- **Avatar-Bild** hochzuladen und zu verwalten
- **E-Mail-Adresse** zu ändern
- **Vollständigen Namen** zu aktualisieren
- **Passwort** zu ändern

## Neue Dateien

### Backend
- **`api/profile.php`** - REST API für Profilverwaltung
  - `GET` - Profil abrufen
  - `POST` - Avatar hochladen
  - `PUT` - Profil aktualisieren oder Passwort ändern

### Frontend
- **`frontend/profile.html`** - Profilbearbeitungs-Seite
- **`frontend/js/profile.js`** - JavaScript-Client für Profile API

### Datenbank
- **`database/migration-avatar.sql`** - Migration für Avatar-Spalte (für bestehende Datenbanken)
- **`database/schema-auth.sql`** - Aktualisiert mit Avatar-Spalte

### Uploads
- **`uploads/avatars/`** - Verzeichnis für hochgeladene Avatar-Bilder
- **`uploads/avatars/.gitignore`** - Verhindert Versionierung von Uploads

## Installation / Setup

### 1. Datenbank aktualisieren

Für **neue Installationen** ist keine zusätzliche Migration nötig, da `schema-auth.sql` bereits die Avatar-Spalte enthält.

Für **bestehende Datenbanken** führen Sie die Migration aus:

```sql
mysql -u username -p datenbankname < database/migration-avatar.sql
```

Oder manuell:

```sql
ALTER TABLE auth_users 
ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER full_name;

CREATE INDEX idx_avatar ON auth_users(avatar);
```

### 2. Upload-Verzeichnis prüfen

Stellen Sie sicher, dass das Verzeichnis `uploads/avatars/` existiert und beschreibbar ist:

```bash
# Linux/Mac
mkdir -p uploads/avatars
chmod 755 uploads/avatars

# Windows (PowerShell)
New-Item -ItemType Directory -Force -Path uploads\avatars
```

### 3. PHP-Konfiguration

Überprüfen Sie in Ihrer `php.ini`:
```ini
upload_max_filesize = 5M
post_max_size = 6M
file_uploads = On
```

## Funktionen

### 1. Avatar hochladen

- Unterstützte Formate: JPG, PNG, GIF, WebP
- Maximale Größe: 5 MB
- Automatische Größenanpassung: 150x150px (im Frontend)
- Beim Upload wird der alte Avatar automatisch gelöscht

### 2. E-Mail-Adresse ändern

- Validierung auf gültiges E-Mail-Format
- Duplikat-Prüfung (E-Mail muss eindeutig sein)
- Optional (kann leer bleiben)

### 3. Vollständigen Namen ändern

- Freitextfeld
- Optional (kann leer bleiben)

### 4. Passwort ändern

- Aktuelles Passwort muss eingegeben werden
- Neues Passwort muss mindestens 8 Zeichen lang sein
- Passwort-Bestätigung erforderlich
- Nach Änderung werden alle anderen Sessions des Benutzers automatisch beendet
- Benutzer wird nach 2 Sekunden automatisch ausgeloggt

## API-Endpunkte

### GET /api/profile.php
Profil des angemeldeten Benutzers abrufen

**Response:**
```json
{
  "success": true,
  "profile": {
    "id": 1,
    "username": "admin",
    "email": "admin@example.com",
    "full_name": "Administrator",
    "avatar": "uploads/avatars/avatar_1_1234567890.jpg",
    "totp_enabled": false,
    "last_login": "2025-12-17 10:30:00",
    "created_at": "2025-01-01 00:00:00"
  }
}
```

### POST /api/profile.php
Avatar hochladen

**Request:** `multipart/form-data` mit Datei "avatar"

**Response:**
```json
{
  "success": true,
  "avatar": "uploads/avatars/avatar_1_1234567890.jpg",
  "message": "Avatar uploaded successfully"
}
```

### PUT /api/profile.php (action: update_info)
E-Mail und Name aktualisieren

**Request:**
```json
{
  "action": "update_info",
  "email": "neue@email.com",
  "full_name": "Neuer Name"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Profile updated successfully"
}
```

### PUT /api/profile.php (action: change_password)
Passwort ändern

**Request:**
```json
{
  "action": "change_password",
  "current_password": "altes_passwort",
  "new_password": "neues_passwort"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Password changed successfully"
}
```

### PUT /api/profile.php (action: delete_avatar)
Avatar löschen

**Request:**
```json
{
  "action": "delete_avatar"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Avatar deleted successfully"
}
```

## Sicherheit

### Authentifizierung
- Alle API-Calls erfordern einen gültigen Session-Token im `Authorization: Bearer <token>` Header
- Benutzer können nur ihr eigenes Profil bearbeiten

### Avatar-Upload
- Dateityp-Validierung (nur Bilder)
- Größen-Limit (5 MB)
- Eindeutige Dateinamen (verhindert Überschreiben)
- Alte Avatare werden automatisch gelöscht

### Passwort-Änderung
- Aktuelles Passwort muss korrekt sein
- Mindestlänge: 8 Zeichen
- BCrypt-Hashing
- Alle anderen Sessions werden beendet

### E-Mail-Validierung
- Filter_var mit FILTER_VALIDATE_EMAIL
- Duplikat-Prüfung in Datenbank

## Navigation

Die Profil-Seite ist über die Hauptnavigation erreichbar:
- Neuer Menüpunkt: **"Profil"** (zwischen "Benutzer" und "Logout")
- Icon: `bi-person-circle`
- Link zu `profile.html`

## Benutzeroberfläche

Die Profil-Seite ist in folgende Bereiche unterteilt:

1. **Profil-Header**
   - Avatar-Vorschau (150x150px, rund)
   - Benutzername
   - E-Mail
   - "Kamera"-Button zum Avatar-Upload

2. **Persönliche Informationen**
   - E-Mail-Adresse Eingabefeld
   - Vollständiger Name Eingabefeld
   - "Informationen speichern" Button

3. **Passwort ändern**
   - Aktuelles Passwort
   - Neues Passwort
   - Passwort bestätigen
   - "Passwort ändern" Button

4. **Avatar löschen**
   - "Avatar löschen" Button
   - Mit Bestätigungs-Dialog

5. **Konto-Informationen** (Read-only)
   - Benutzername
   - 2FA Status
   - Letzter Login
   - Konto erstellt

## Fehlerbehandlung

Alle Formulare zeigen Fehler- und Erfolgsmeldungen in einem Alert-Bereich über dem jeweiligen Abschnitt an.

Typische Fehlermeldungen:
- "Invalid email format" - E-Mail-Format ungültig
- "Email already in use" - E-Mail wird bereits verwendet
- "Current password is incorrect" - Aktuelles Passwort falsch
- "Password must be at least 8 characters" - Passwort zu kurz
- "File too large. Maximum 5 MB allowed" - Datei zu groß
- "Invalid file type..." - Falscher Dateityp

## Technische Details

### Datei-Benennung
Avatar-Dateien werden nach folgendem Schema benannt:
```
avatar_{user_id}_{timestamp}.{extension}
```

Beispiel: `avatar_1_1702812345.jpg`

### Datei-Pfade
- Im Backend: Absolute Pfade (`__DIR__ . '/../uploads/avatars/'`)
- In Datenbank: Relative Pfade (`uploads/avatars/filename.jpg`)
- Im Frontend: Relative Pfade (`../uploads/avatars/filename.jpg`)

### Cache-Busting
Beim Laden von Avataren wird ein Timestamp-Parameter angehängt:
```javascript
`../${profile.avatar}?t=${Date.now()}`
```

Dies verhindert Browser-Caching-Probleme nach Avatar-Updates.

## Browser-Kompatibilität

- Chrome/Edge: ✅ Vollständig unterstützt
- Firefox: ✅ Vollständig unterstützt
- Safari: ✅ Vollständig unterstützt
- Mobile Browser: ✅ Responsive Design

## Testing

### Manueller Test-Ablauf

1. **Login** auf der Anwendung
2. **Navigation** zu "Profil"
3. **Avatar hochladen**
   - Bild auswählen (< 5 MB)
   - Upload überprüfen
   - Avatar-Vorschau aktualisiert?
4. **E-Mail ändern**
   - Neue E-Mail eingeben
   - Speichern
   - Profil neu laden → E-Mail korrekt?
5. **Passwort ändern**
   - Altes Passwort eingeben
   - Neues Passwort eingeben (2x)
   - Speichern
   - Automatischer Logout nach 2 Sekunden?
   - Mit neuem Passwort einloggen?
6. **Avatar löschen**
   - "Avatar löschen" klicken
   - Bestätigung
   - Avatar-Vorschau zurückgesetzt?

## Troubleshooting

### Upload funktioniert nicht
- Prüfen: `uploads/avatars/` Verzeichnis existiert
- Prüfen: Schreibrechte auf Verzeichnis
- Prüfen: PHP `upload_max_filesize` Einstellung
- PHP Error-Log prüfen

### Avatar wird nicht angezeigt
- Prüfen: Datei existiert in `uploads/avatars/`
- Prüfen: Pfad in Datenbank korrekt
- Browser-Console auf 404-Fehler prüfen
- Cache leeren (Hard Reload: Ctrl+Shift+R)

### Passwort-Änderung schlägt fehl
- Aktuelles Passwort korrekt eingegeben?
- Neues Passwort mindestens 8 Zeichen?
- Passwörter stimmen überein?

### E-Mail kann nicht geändert werden
- E-Mail-Format gültig?
- E-Mail bereits von anderem Benutzer verwendet?
- Datenbank-Verbindung OK?

## Weiterentwicklung

Mögliche Erweiterungen:
- Profilbild-Crop/Resize im Frontend
- Passwort-Stärke-Anzeige
- E-Mail-Bestätigung bei E-Mail-Änderung
- Benutzername ändern
- Account löschen
- Aktivitätsprotokoll
