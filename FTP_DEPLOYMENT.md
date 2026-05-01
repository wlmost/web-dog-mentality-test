# FTP-Only Deployment (ohne Shell-Zugriff)

## Übersicht

Diese Anleitung beschreibt das Deployment auf Shared Webhosting **nur mit FTP-Zugriff** (kein SSH, kein Composer).

## Voraussetzungen

- FTP-Client (FileZilla, WinSCP, Cyberduck)
- Lokaler PC mit PHP 8.0+ und Composer (für Vorbereitung)
- phpMyAdmin-Zugriff beim Webhoster

---

## Variante 1: Mit PhpSpreadsheet (Excel-Import)

### 1. Lokale Vorbereitung

**Auf deinem Windows-PC:**

```powershell
# Im Projektverzeichnis
cd C:\...\web-dog-mentality-test

# Composer-Abhängigkeiten installieren
composer install --no-dev --optimize-autoloader
```

**Was passiert:**
- Erstellt `vendor/` Ordner (~5-10 MB)
- Lädt PhpSpreadsheet herunter
- Optimiert Autoloader für Produktion

**Prüfen:**
```powershell
dir vendor\
# Sollte zeigen: autoload.php, composer/, phpoffice/
```

### 2. FTP-Upload

**FileZilla-Anleitung:**

1. **Verbindung herstellen:**
   - Host: `ftp.dein-hoster.de`
   - Benutzer: `dein-ftp-user`
   - Passwort: `***`
   - Port: `21` (oder `22` für SFTP)

2. **Verzeichnisstruktur erstellen:**
   ```
   /httpdocs/                    (oder /public_html/)
   ├── dog-test/                 (Unterordner erstellen)
   │   ├── api/
   │   ├── frontend/
   │   ├── vendor/               ← WICHTIG!
   │   ├── logs/
   │   └── uploads/
   ```

3. **Dateien hochladen:**
   - Rechte Seite (Server): `/httpdocs/dog-test/`
   - Linke Seite (Lokal): `C:\...\web-dog-mentality-test\`
   - **Drag & Drop:**
     * `api/` → kompletter Ordner
     * `frontend/` → kompletter Ordner
     * `vendor/` → **kompletter Ordner** (Geduld, 5-10 MB!)
     * `database/` → nur zum Referenz (SQL-Dateien)
   
4. **Leere Ordner erstellen:**
   - Rechtsklick → "Verzeichnis erstellen"
   - `logs/`
   - `uploads/`

5. **Berechtigungen setzen:**
   - Rechtsklick auf `logs/` → "Dateiberechtigungen"
   - Numerischer Wert: `755`
   - ✓ "In Unterverzeichnisse übernehmen"
   - OK
   - Gleich für `uploads/`

**Transfer-Dauer:**
- Bei DSL 16.000: ~10-15 Minuten
- Bei DSL 100.000: ~2-5 Minuten
- WICHTIG: Nicht abbrechen während `vendor/` Upload!

### 3. .env Datei erstellen

**Option A: Lokal bearbeiten, dann hochladen**

```powershell
# Lokal
copy .env.example .env
notepad .env
```

Inhalt anpassen:
```ini
DB_HOST=localhost
DB_USER=k12345_user        # Vom Hoster
DB_PASS=GeheimesPasswort   # Vom Hoster
DB_NAME=k12345_dogtest     # Vom Hoster
OPENAI_API_KEY=sk-...
```

Hochladen via FTP in Root (`/httpdocs/dog-test/.env`)

**Option B: Direkt auf Server bearbeiten**

Manche FTP-Clients (WinSCP) erlauben direktes Editieren:
- Rechtsklick auf `.env.example` → "Bearbeiten"
- Umbenennen zu `.env`
- Speichern

### 4. Datenbank via phpMyAdmin

**Im Browser:**
1. `https://dein-hoster.de/phpmyadmin` (URL vom Hoster)
2. Einloggen
3. Linke Seite: "Neu" → Datenbank erstellen
   - Name: `k12345_dogtest`
   - Kollation: `utf8mb4_unicode_ci`
4. Datenbank auswählen (linke Seite anklicken)
5. Tab "Importieren"
6. "Datei auswählen" → `database/schema.sql` (von deinem PC)
7. "OK" klicken
8. ✓ Erfolg: "Import erfolgreich beendet"
9. Optional: `example-data.sql` importieren

### 5. .htaccess für PHP-Settings

**Neue Datei erstellen** (lokal):

```apache
# .htaccess

# PHP-Settings (falls erlaubt)
<IfModule mod_php.c>
    php_value upload_max_filesize 10M
    php_value post_max_size 10M
    php_value memory_limit 256M
    php_value max_execution_time 120
</IfModule>

# Security
<Files ".env">
    Order allow,deny
    Deny from all
</Files>

# Logging
php_flag display_errors Off
php_flag log_errors On
php_value error_log logs/php_error.log
```

**Hochladen:** In Root (`/httpdocs/dog-test/.htaccess`)

**WICHTIG:** Falls nach Upload "500 Internal Server Error":
- `.htaccess` wieder löschen
- Hoster erlaubt keine `php_value` Direktiven
- Limits über Hoster-Panel einstellen

### 6. Testen

**Im Browser:**
```
https://deine-domain.de/dog-test/frontend/
```

**Sollte zeigen:**
- Login-Maske oder Hunde-Liste
- Keine PHP-Fehler

**Fehlersuche:**
- Weiße Seite → `logs/php_error.log` via FTP herunterladen
- 500 Fehler → `.htaccess` Problem (siehe oben)
- DB-Fehler → `.env` Zugangsdaten prüfen

---

## Variante 2: OHNE PhpSpreadsheet (CSV statt Excel)

Falls `vendor/` Upload zu groß oder zu langsam:

### Vorteile
- Kein `composer install` nötig
- Kein `vendor/` Upload (spart 5-10 MB)
- Schnelleres Deployment
- Funktioniert überall

### Nachteile
- Kein Excel (.xlsx) Import
- Nur CSV-Dateien
- Weniger komfortabel

### Setup

1. **`import.php` NICHT hochladen**
2. **`import-csv.php` hochladen** (stattdessen)
3. **Frontend anpassen:**

```javascript
// In api.js - ERSETZE uploadBattery():
async uploadBattery(file) {
    const formData = new FormData();
    formData.append('file', file);
    
    const url = `${this.baseUrl}/import-csv.php`; // ← CSV statt import.php
    
    // ... Rest bleibt gleich
}
```

4. **HTML anpassen:**

```html
<!-- In index.html - accept ändern: -->
<input type="file" id="battery-file" accept=".csv">
                                              ^^^^
```

### CSV-Format

**Datei:** `testbatterie.csv`

```csv
Test Nr.;Testname;OCEAN Dimension;Max. Wert;Beschreibung
1;Reaktion auf neue Umgebung;Offenheit;7;Wie reagiert der Hund?
2;Impulskontrolle;Gewissenhaftigkeit;7;Kann Impulse kontrollieren?
3;Aktivitätslevel;Extraversion;7;Wie aktiv ist der Hund?
4;Freundlichkeit;Verträglichkeit;14;Besonders wichtig
5;Ängstlichkeit;Neurotizismus;7;Zeigt ängstliches Verhalten?
```

**Wichtig:**
- Separator: **Semikolon** (`;`) oder Komma (`,`)
- Header-Zeile MUSS exakt stimmen
- UTF-8 Encoding
- Excel: "Speichern unter" → CSV (Trennzeichen-getrennt)

### CSV in Excel erstellen

1. Excel öffnen
2. Tabelle ausfüllen (wie gehabt)
3. "Speichern unter"
4. Format: **CSV (Trennzeichen-getrennt) (*.csv)**
5. Bei Warnung: "Ja" klicken
6. Hochladen in Web-App

---

## Troubleshooting

### Problem: vendor/ Upload dauert zu lange

**Lösung 1:** Komprimieren
```powershell
# Lokal
Compress-Archive -Path vendor -DestinationPath vendor.zip

# Upload vendor.zip via FTP (schneller!)
# Auf Server entpacken via File Manager (Hoster-Panel)
```

**Lösung 2:** Nur essentials hochladen
```powershell
# Nur PhpSpreadsheet (ohne andere Abhängigkeiten):
vendor/
├── autoload.php
├── composer/
│   ├── autoload_*.php
│   └── installed.json
└── phpoffice/
    └── phpspreadsheet/
```

**Lösung 3:** CSV-Variante nutzen (siehe oben)

### Problem: 500 Internal Server Error

**Ursachen:**
1. `.htaccess` mit unerlaubten Direktiven
2. Falsche Berechtigungen
3. PHP-Syntax-Fehler

**Lösung:**
1. `.htaccess` umbenennen zu `.htaccess.bak` (testen ohne)
2. Berechtigungen prüfen: `755` für Ordner, `644` für Dateien
3. `logs/php_error.log` herunterladen und lesen

### Problem: Blank Page (weiße Seite)

**Lösung:**
```php
// In api/config.php temporär aktivieren:
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

Fehler anzeigen lassen, dann wieder deaktivieren.

### Problem: "PhpSpreadsheet not found"

**Ursache:** `vendor/` nicht hochgeladen oder unvollständig

**Lösung:**
1. Prüfen ob `vendor/autoload.php` existiert
2. Prüfen ob `vendor/phpoffice/` existiert
3. Neu hochladen (komplett, nicht abbrechen!)

### Problem: Upload-Limit zu klein

**Ursache:** Hoster erlaubt keine `.htaccess` Änderungen

**Lösung:**
1. **Hoster-Panel** durchsuchen nach "PHP-Einstellungen" oder "PHP-Konfiguration"
2. Limits dort erhöhen:
   - `upload_max_filesize`: 10 MB
   - `post_max_size`: 10 MB
   - `memory_limit`: 256 MB
3. Falls nicht möglich: Support kontaktieren

### Problem: Datenbank-Verbindung fehlgeschlagen

**Ursache:** Falsche DB-Zugangsdaten in `.env`

**Lösung:**
1. Hoster-Panel → MySQL-Datenbanken
2. Zugangsdaten kopieren (exakt!)
3. Häufige Abweichungen:
   - Host: `localhost` oder `127.0.0.1` oder `mysqlXX.hoster.de`
   - User: oft mit Präfix (z.B. `k12345_user`)
   - DB-Name: oft mit Präfix (z.B. `k12345_dogtest`)

---

## Bekannte Hoster und ihre Eigenheiten

### All-Inkl.de
- PHP-Settings: Über KAS (Kunden-Login)
- Composer: Nicht verfügbar
- Lösung: Lokale Installation + FTP-Upload

### Hetzner Webhosting
- PHP-Settings: Via `.htaccess` möglich
- Composer: Nicht verfügbar
- SSH: Nur in teureren Paketen

### IONOS (1&1)
- PHP-Settings: Über Control-Panel
- `.htaccess`: Teilweise eingeschränkt
- Tipp: PHP 8.x explizit aktivieren

### Strato
- PHP-Settings: Via `.htaccess` meist erlaubt
- File Manager: ZIP-Entpacken möglich (für `vendor.zip`)

---

## Checkliste Deployment

- [ ] Lokal `composer install` ausgeführt
- [ ] `vendor/` Ordner vorhanden (5-10 MB)
- [ ] FTP-Verbindung funktioniert
- [ ] Ordner erstellt: `api/`, `frontend/`, `vendor/`, `logs/`, `uploads/`
- [ ] Alle Dateien hochgeladen (inkl. `vendor/`!)
- [ ] Berechtigungen gesetzt: `logs/` und `uploads/` → 755
- [ ] `.env` Datei erstellt und hochgeladen
- [ ] Datenbank in phpMyAdmin angelegt
- [ ] `schema.sql` importiert
- [ ] Optional: `example-data.sql` importiert
- [ ] `.htaccess` hochgeladen (oder weggelassen bei Fehler 500)
- [ ] Frontend im Browser geöffnet
- [ ] Login/Hunde-Liste wird angezeigt
- [ ] Keine PHP-Fehler sichtbar

**Fertig!** 🎉
