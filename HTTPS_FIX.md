# HTTPS-Fix und Deployment

## Problem
- Wenn die Seite über **HTTPS** aufgerufen wird, aber API-Aufrufe **HTTP** verwenden, blockiert der Browser diese (Mixed Content)
- Relative Pfade wie `../api/auth.php` können je nach Ordnerstruktur fehlschlagen

## Lösung
Alle API-Client-URLs verwenden jetzt **automatische Protokoll-Erkennung**:

```javascript
const protocol = window.location.protocol; // https: oder http:
const host = window.location.host;         // www.leisoft.de
const basePath = window.location.pathname.split('/').slice(0, -2).join('/');
const apiUrl = `${protocol}//${host}${basePath}/api/auth.php`;
```

Dies funktioniert sowohl für:
- ✅ `http://localhost/dog-test/frontend/login.html` → `http://localhost/dog-test/api/auth.php`
- ✅ `https://www.leisoft.de/dog-test/frontend/login.html` → `https://www.leisoft.de/dog-test/api/auth.php`

## Aktualisierte Dateien

### Backend (PHP)
1. **api/config.php** - display_errors=0, besseres Error-Handling
2. **api/auth.php** - DB-Verbindung in try-catch, JSON-Validierung
3. **api/users.php** - DB-Verbindung in try-catch
4. **api/test-db.php** - Diagnose-Tool (NEU)

### Frontend (JavaScript)
1. **frontend/js/auth.js** - HTTPS-kompatible URLs + parseResponse()
2. **frontend/js/api.js** - HTTPS-kompatible URLs
3. **frontend/js/users.js** - HTTPS-kompatible URLs
4. **frontend/test-api.html** - API-Test-Tool (NEU)

## Deployment-Schritte

### 1. Dateien hochladen (FTP)
```
api/config.php       → /httpdocs/dog-test/api/
api/auth.php         → /httpdocs/dog-test/api/
api/users.php        → /httpdocs/dog-test/api/
api/test-db.php      → /httpdocs/dog-test/api/

frontend/js/auth.js  → /httpdocs/dog-test/frontend/js/
frontend/js/api.js   → /httpdocs/dog-test/frontend/js/
frontend/js/users.js → /httpdocs/dog-test/frontend/js/
frontend/test-api.html → /httpdocs/dog-test/frontend/
```

### 2. Tests durchführen

#### Test 1: Datenbank-Verbindung
URL: `https://www.leisoft.de/dog-test/api/test-db.php`

Erwartetes Ergebnis:
- ✅ .env Datei gefunden
- ✅ Datenbankverbindung erfolgreich
- ✅ Alle Tabellen existieren
- ✅ Admin-User gefunden

#### Test 2: API-Kommunikation
URL: `https://www.leisoft.de/dog-test/frontend/test-api.html`

Button "Test starten" klicken.

Erwartetes Ergebnis:
- ✅ Protokoll: https:
- ✅ Resolved API URL: https://www.leisoft.de/dog-test/api/auth.php
- ✅ Content-Type: application/json
- ✅ JSON Parse: OK
- ✅ Login-Response mit success oder error

#### Test 3: Login-Seite
URL: `https://www.leisoft.de/dog-test/frontend/login.html`

Login mit: `admin` / `admin123`

**Browser-Konsole öffnen (F12)** und prüfen:
- Console sollte zeigen: "Auth API URL: https://www.leisoft.de/dog-test/api/auth.php"
- Bei Fehler: Genaue Fehlermeldung wird angezeigt

## Debugging

### Console-Logs prüfen
Nach dem Laden der Seite sollten in der Browser-Console erscheinen:
```
Auth API URL: https://www.leisoft.de/dog-test/api/auth.php
API Base URL: https://www.leisoft.de/dog-test/api
User API URL: https://www.leisoft.de/dog-test/api/users.php
```

### Network-Tab prüfen
1. Browser DevTools öffnen (F12)
2. Tab "Network" (Netzwerk) öffnen
3. Login versuchen
4. Request zu `auth.php` anklicken
5. Prüfen:
   - Request URL (sollte HTTPS sein)
   - Status Code (200 = OK, 400 = Fehler, 500 = Server-Fehler)
   - Response Headers → Content-Type (sollte application/json sein)
   - Response Body (sollte JSON sein, kein HTML)

### Mögliche Fehler

**Mixed Content Warning:**
```
Mixed Content: The page at 'https://...' was loaded over HTTPS, 
but requested an insecure resource 'http://...'. 
This request has been blocked.
```
→ **Gelöst durch automatische Protokoll-Erkennung**

**CORS Error:**
```
Access to fetch at '...' from origin '...' has been blocked by CORS policy
```
→ Prüfe ob `api/config.php` korrekt hochgeladen wurde (enthält CORS-Header)

**JSON Parse Error:**
```
Unexpected token < in JSON at position 0
```
→ Server gibt HTML statt JSON zurück
→ Prüfe `api/test-db.php` ob Datenbankverbindung funktioniert

**404 Not Found:**
```
GET https://www.leisoft.de/dog-test/api/auth.php 404
```
→ Datei nicht hochgeladen oder falscher Pfad
→ Prüfe FTP-Upload und Ordnerstruktur

## Server-Anforderungen

- PHP 7.4 oder höher
- MySQL 5.7 oder höher
- HTTPS aktiviert (SSL-Zertifikat)
- mod_rewrite (für .htaccess)
- allow_url_fopen = On (für OpenAI API)

## Sicherheit

Nach erfolgreichem Login:
1. **Admin-Passwort sofort ändern!**
   - Login als admin
   - Tab "Benutzer" öffnen
   - Admin bearbeiten
   - Neues Passwort setzen

2. **display_errors deaktiviert**
   - Verhindert Anzeige sensibler Informationen
   - Fehler werden ins PHP Error Log geschrieben

3. **HTTPS erzwungen**
   - Passwörter werden verschlüsselt übertragen
   - Session-Tokens sind geschützt
