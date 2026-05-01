# OpenAI KI-Features Einrichtung

## Problem
Bei Klick auf "KI-Bewertung generieren" erscheint ein Fehler:
```
POST /api/ai.php 500 (Internal Server Error)
```

## Lösung

### 1. `.env` Datei erstellen
Die `.env` Datei fehlt noch. Erstellen Sie diese aus der Vorlage:

```bash
# In PowerShell/CMD
Copy-Item .env.example .env
```

### 2. OpenAI API-Key eintragen
Öffnen Sie die neue `.env` Datei und tragen Sie Ihren OpenAI API-Key ein:

```env
# Ersetzen Sie "sk-...dein-key-hier..." mit Ihrem echten API-Key
OPENAI_API_KEY=sk-proj-xxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

**API-Key erhalten:**
1. Gehen Sie zu: https://platform.openai.com/api-keys
2. Melden Sie sich mit Ihrem OpenAI-Account an
3. Klicken Sie auf "Create new secret key"
4. Kopieren Sie den Key (beginnt mit `sk-proj-...`)
5. Fügen Sie ihn in die `.env` Datei ein

### 3. Datenbank-Zugangsdaten ergänzen
Tragen Sie auch Ihre Datenbank-Credentials ein:

```env
DB_HOST=mysql3.netbeat.de
DB_USER=ihr_username
DB_PASS=ihr_passwort
DB_NAME=ihr_datenbankname
```

### 4. Testen
Nach dem Speichern der `.env` Datei sollten die KI-Features funktionieren:
- ✅ Idealprofil generieren
- ✅ KI-Bewertung erstellen

## Hinweise

### Kosten
- OpenAI API-Calls kosten Geld (sehr günstig mit gpt-4o-mini)
- Ca. $0.001 pro Bewertung (1000 Bewertungen = $1)
- Überwachen Sie Ihre Nutzung: https://platform.openai.com/usage

### Ohne OpenAI arbeiten
Wenn Sie die KI-Features nicht nutzen möchten:
- Lassen Sie `OPENAI_API_KEY` leer
- Die Buttons werden deaktiviert
- Alle anderen Features funktionieren normal

### Sicherheit
- **NIEMALS** die `.env` Datei ins Git-Repository committen!
- Die `.env` ist bereits in `.gitignore` eingetragen
- Teilen Sie Ihren API-Key niemals öffentlich
