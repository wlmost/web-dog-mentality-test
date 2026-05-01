# Quick Start - FTP Deployment

## 🚀 Schnellanleitung (5 Schritte)

### Vorbereitung (auf deinem PC):

```powershell
# 1. Im Projektverzeichnis:
cd C:\...\web-dog-mentality-test

# 2. PowerShell-Script ausführen:
.\prepare-deployment.ps1

# Folge den Anweisungen des Scripts
```

Das Script erledigt:
- ✅ Composer Install (PhpSpreadsheet)
- ✅ .env Datei erstellen
- ✅ Verzeichnisse anlegen
- ✅ Optional: ZIP-Archive erstellen

---

### Upload (via FTP):

**Mit FileZilla:**

1. **Verbinden:**
   - Host: `ftp.dein-hoster.de`
   - Benutzer: `***`
   - Passwort: `***`

2. **Hochladen** (Drag & Drop):
   ```
   Lokal → Server
   api/       → /httpdocs/dog-test/api/
   frontend/  → /httpdocs/dog-test/frontend/
   vendor/    → /httpdocs/dog-test/vendor/     ⚠️ 5-10 MB!
   .env       → /httpdocs/dog-test/.env
   .htaccess  → /httpdocs/dog-test/.htaccess
   logs/      → /httpdocs/dog-test/logs/       (leer)
   uploads/   → /httpdocs/dog-test/uploads/    (leer)
   ```

3. **Berechtigungen:**
   - Rechtsklick `logs/` → Dateiberechtigungen → `755`
   - Rechtsklick `uploads/` → Dateiberechtigungen → `755`

4. **.env anpassen:**
   - Via FTP herunterladen
   - In Notepad öffnen
   - DB-Zugangsdaten eintragen (vom Hoster)
   - Wieder hochladen

---

### Datenbank (via phpMyAdmin):

1. Öffne: `https://dein-hoster.de/phpmyadmin`
2. "Neu" → Datenbank erstellen
   - Name: `k12345_dogtest` (mit deinem Präfix)
   - Kollation: `utf8mb4_unicode_ci`
3. Datenbank auswählen
4. "Importieren" → `database/schema.sql` hochladen
5. Optional: `database/example-data.sql` importieren

---

### Testen:

Browser öffnen:
```
https://deine-domain.de/dog-test/frontend/
```

**Erwarte:**
- ✅ Hunde-Liste wird angezeigt
- ✅ Keine Fehlermeldungen
- ✅ Tab-Navigation funktioniert

**Bei Problemen:**
- Weiße Seite → `logs/php_error.log` herunterladen
- 500 Fehler → `.htaccess` löschen (nicht erlaubt)
- DB-Fehler → `.env` Zugangsdaten prüfen

---

## ⚡ Schneller Upload (Alternative)

Falls `vendor/` Upload zu lange dauert:

### Option 1: ZIP hochladen

```powershell
# Script mit ZIP-Option ausführen
.\prepare-deployment.ps1
# → "j" bei "ZIP-Archive erstellen"
```

**Upload:**
1. `vendor.zip` hochladen (schneller als Ordner!)
2. Im Hoster File Manager: `vendor.zip` entpacken
3. `vendor.zip` löschen

### Option 2: CSV statt Excel

**OHNE PhpSpreadsheet (ohne vendor/):**

1. `vendor/` NICHT hochladen
2. `api/import.php` NICHT hochladen
3. `api/import-csv.php` hochladen
4. Frontend: CSV-Import nutzen (statt Excel)

Details: Siehe `FTP_DEPLOYMENT.md` → "Variante 2"

---

## 📋 Checkliste

**Vor Upload:**
- [ ] `prepare-deployment.ps1` ausgeführt
- [ ] `vendor/` Ordner existiert (~5-10 MB)
- [ ] `.env` mit DB-Zugangsdaten angepasst

**Nach Upload:**
- [ ] Alle Dateien hochgeladen (inkl. `vendor/`!)
- [ ] Berechtigungen: `logs/` + `uploads/` = 755
- [ ] Datenbank erstellt und `schema.sql` importiert
- [ ] Frontend im Browser getestet

---

## 🆘 Problemlösung

| Problem | Lösung |
|---------|--------|
| Weiße Seite | `logs/php_error.log` herunterladen und lesen |
| 500 Error | `.htaccess` umbenennen/löschen |
| DB-Fehler | `.env` Zugangsdaten prüfen (User, Pass, Host) |
| vendor/ fehlt | Neu hochladen (komplett, nicht abbrechen!) |
| Upload zu langsam | ZIP-Variante nutzen (siehe oben) |

---

## 📞 Support

**Hoster-spezifische Hilfe:**
- All-Inkl: KAS → PHP-Einstellungen
- Hetzner: konsoleH → Einstellungen
- IONOS: Control-Panel → PHP-Version

**Dokumentation:**
- Ausführlich: `FTP_DEPLOYMENT.md`
- Excel-Import: `EXCEL_IMPORT.md`
- Batterien: `BATTERIES.md`

Viel Erfolg! 🚀
