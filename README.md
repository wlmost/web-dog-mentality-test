# Dog Mentality Test - Web Application

PHP-basierte Web-Anwendung für tierpsychologische OCEAN-Tests auf Hunden.

## 📋 Projekt-Struktur

```
web-dog-mentality-test/
├── api/                    # PHP Backend (RESTful API)
│   ├── config.php          # DB-Verbindung & Helper-Funktionen
│   ├── dogs.php            # CRUD für Stammdaten
│   ├── batteries.php       # CRUD für Testbatterien
│   ├── sessions.php        # CRUD für Test-Sessions
│   ├── results.php         # CRUD für Test-Ergebnisse
│   ├── ocean.php           # OCEAN-Analyse-Berechnung
│   ├── ai.php              # OpenAI-Integration
│   └── import.php          # Excel-Import für Batterien
├── database/               # Datenbank
│   ├── schema.sql          # MySQL Schema mit Tabellen & Views
│   └── example-data.sql    # Beispieldaten (2 Batterien, 3 Hunde)
├── frontend/               # HTML/CSS/JavaScript
│   ├── index.html          # Single-Page Application
│   ├── js/
│   │   ├── api.js          # API Client
│   │   ├── app.js          # Hauptlogik
│   │   └── radar-chart.js  # OCEAN Radar-Diagramm
│   └── css/
│       └── styles.css      # Custom Styles
├── uploads/                # Excel-Uploads (TODO)
├── logs/                   # Log-Dateien (automatisch erstellt)
├── vendor/                 # Composer-Abhängigkeiten (PhpSpreadsheet)
├── composer.json           # PHP-Abhängigkeiten
├── .env.example            # Beispiel-Konfiguration
└── README.md               # Diese Datei
```

## 🚀 Installation

### 1. Voraussetzungen

- **PHP 8.0+** mit Extensions:
  - mysqli
  - json
  - curl
  - xml
  - zip
  - mbstring
- **MySQL 5.7+** oder **MariaDB 10.2+**
- **Webserver**: Apache, Nginx, oder PHP Development Server
- **Composer** (für PhpSpreadsheet)

### 2. Datenbank einrichten

```sql
-- In MySQL/phpMyAdmin:
CREATE DATABASE dog_mentality CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Schema importieren:
SOURCE database/schema.sql;
```

### 3. Konfiguration

```bash
# .env erstellen
cp .env.example .env

# .env bearbeiten:
DB_HOST=localhost
DB_USER=dein_user
DB_PASS=dein_passwort
DB_NAME=dog_mentality

# Optional: OpenAI API Key (für KI-Features)
OPENAI_API_KEY=sk-...
```

### 4. Berechtigungen setzen

```bash
# Logs-Verzeichnis schreibbar machen
mkdir -p logs
chmod 755 logs

# Uploads-Verzeichnis (später für Excel-Import)
mkdir -p uploads/batteries
chmod 755 uploads
```

### 5. Testen

```bash
# PHP Development Server starten
php -S localhost:8000

# API testen:
curl http://localhost:8000/api/dogs.php
```

## 📡 API Endpoints

### Dogs (Stammdaten)

```bash
# Alle Hunde abrufen
GET /api/dogs.php

# Einzelnen Hund abrufen
GET /api/dogs.php?id=1

# Suche
GET /api/dogs.php?search=Golden

# Neuen Hund erstellen
POST /api/dogs.php
Content-Type: application/json
{
  "owner_name": "Max Mustermann",
  "dog_name": "Bello",
  "breed": "Golden Retriever",
  "age_years": 3,
  "age_months": 6,
  "gender": "Rüde",
  "neutered": true,
  "intended_use": "Therapiehund"
}

# Hund aktualisieren
PUT /api/dogs.php?id=1
Content-Type: application/json
{ ... }

# Hund löschen
DELETE /api/dogs.php?id=1
```

### Batteries (Testbatterien)

```bash
# Alle Batterien abrufen
GET /api/batteries.php

# Einzelne Batterie mit Tests
GET /api/batteries.php?id=1

# Neue Batterie erstellen
POST /api/batteries.php
Content-Type: application/json
{
  "name": "Wesenstest Standard",
  "description": "Standardtests nach VDH",
  "tests": [
    {
      "test_number": 1,
      "test_name": "Freundlichkeit gegenüber Fremden",
      "ocean_dimension": "Verträglichkeit",
      "max_value": 14
    },
    {
      "test_number": 2,
      "test_name": "Reaktion auf Umweltreize",
      "ocean_dimension": "Neurotizismus",
      "max_value": 7
    }
  ]
}

# Batterie aktualisieren
PUT /api/batteries.php?id=1
Content-Type: application/json
{
  "name": "Wesenstest Standard (Update)",
  "description": "Aktualisierte Version"
}

# Batterie löschen (nur wenn keine Sessions vorhanden)
DELETE /api/batteries.php?id=1
```

### Import (Excel-Import)

```bash
# Template herunterladen
GET /api/import.php?template

# Excel-Datei importieren
POST /api/import.php
Content-Type: multipart/form-data
[file: testbatterie.xlsx]

# Response:
{
  "message": "Battery imported successfully",
  "battery_id": 3,
  "battery_name": "Meine Testbatterie",
  "test_count": 35
}
```

Siehe [EXCEL_IMPORT.md](EXCEL_IMPORT.md) für Details.

### Sessions (Test-Sessions)

```bash
# Alle Sessions abrufen
GET /api/sessions.php

# Sessions für einen Hund
GET /api/sessions.php?dog_id=1

# Einzelne Session mit Details
GET /api/sessions.php?id=1

# Neue Session erstellen
POST /api/sessions.php
Content-Type: application/json
{
  "dog_id": 1,
  "battery_id": 1,
  "session_notes": "Test im Freigelände",
  "ideal_profile": {"O": 10, "C": 12, "E": 8, "A": 14, "N": -10},
  "owner_profile": {"O": 9, "C": 11, "E": 7, "A": 13, "N": -8}
}

# Session aktualisieren
PUT /api/sessions.php?id=1
Content-Type: application/json
{
  "session_notes": "Aktualisierte Notizen",
  "ai_assessment": "KI-Bewertung hier..."
}

# Session löschen
DELETE /api/sessions.php?id=1
```

### Results (Test-Ergebnisse)

```bash
# Alle Results einer Session
GET /api/results.php?session_id=1

# Test-Ergebnis speichern/aktualisieren
POST /api/results.php
Content-Type: application/json
{
  "session_id": 1,
  "test_number": 1,
  "score": 2,
  "notes": "Hund war sehr aufmerksam"
}

# Test-Ergebnis löschen
DELETE /api/results.php?id=1
```

### OCEAN-Analyse

```bash
# OCEAN-Scores für Session berechnen
GET /api/ocean.php?session_id=1

# Response:
{
  "session_id": 1,
  "ocean_scores": {
    "O": 12,
    "C": 8,
    "E": 10,
    "A": 15,
    "N": -6
  },
  "test_counts": {
    "O": 7,
    "C": 7,
    "E": 7,
    "A": 7,
    "N": 7
  },
  "averages": {
    "O": 1.71,
    "C": 1.14,
    "E": 1.43,
    "A": 2.14,
    "N": -0.86
  },
  "total_completed_tests": 35,
  "profiles": {
    "ist": {...},
    "ideal": {...},
    "owner": {...}
  }
}
```

### AI-Features (OpenAI)

```bash
# Idealprofil generieren
POST /api/ai.php?action=ideal_profile
Content-Type: application/json
{
  "breed": "Golden Retriever",
  "age_years": 3,
  "age_months": 6,
  "gender": "Rüde",
  "intended_use": "Therapiehund",
  "test_count": 7
}

# Response:
{
  "ideal_profile": {
    "O": 10,
    "C": 12,
    "E": 8,
    "A": 14,
    "N": -10
  },
  "metadata": {...}
}

# 3-Profil-Bewertung erstellen
POST /api/ai.php?action=assessment
Content-Type: application/json
{
  "ist_profile": {"O": 8, "C": 10, ...},
  "ideal_profile": {"O": 10, "C": 12, ...},
  "owner_profile": {"O": 9, "C": 11, ...},
  "dog_data": {
    "dog_name": "Bello",
    "breed": "Golden Retriever",
    "intended_use": "Therapiehund"
  }
}

# Response:
{
  "ai_assessment": "Detaillierte deutsche Bewertung...",
  "profiles_compared": {...}
}
```

## 🗄️ Datenbank-Schema

### Haupttabellen

- **`dogs`**: Stammdaten (Hund + Halter)
- **`test_batteries`**: Testbatterien (importiert aus Excel)
- **`battery_tests`**: Einzelne Tests mit OCEAN-Zuordnung
- **`test_sessions`**: Test-Sessions mit KI-Profilen (JSON)
- **`test_results`**: Test-Ergebnisse (Scores -2 bis +2)

### Views

- **`v_session_overview`**: Vollständige Session-Info mit Dog + Battery
- **`v_ocean_scores`**: Automatische OCEAN-Berechnung pro Session

### Constraints

- Automatische Validierung: Alter (0-20 Jahre, 0-11 Monate)
- Score-Range: -2 bis +2
- CASCADE Delete: Sessions → Results
- RESTRICT Delete: Batteries (nur wenn keine Sessions existieren)

## 🔒 Sicherheit

- **Input-Validierung**: Alle Eingaben werden validiert (Type, Range, Required)
- **SQL Injection Prevention**: Prepared Statements in allen Queries
- **CORS**: Konfigurierbar in `config.php`
- **Error Handling**: Strukturierte JSON-Responses
- **Logging**: Alle Aktionen in `logs/app.log`

## 🚧 TODO: Nächste Schritte

1. **Frontend entwickeln** (HTML/CSS/JavaScript mit Chart.js)
2. **Excel-Import** implementieren (PhpSpreadsheet)
3. **PDF-Export** implementieren (TCPDF/mPDF)
4. **Authentifizierung** (Session-Management, Login)
5. **Deployment** auf Webhosting

## 📝 Entwicklungs-Workflow

```bash
# 1. Lokalen Server starten
php -S localhost:8000

# 2. API testen mit curl/Postman
curl -X POST http://localhost:8000/api/dogs.php \
  -H "Content-Type: application/json" \
  -d '{"owner_name":"Test","dog_name":"Rex",...}'

# 3. Logs prüfen
tail -f logs/app.log

# 4. Datenbank prüfen
mysql -u user -p dog_mentality
SELECT * FROM v_session_overview;
```

## 🐛 Fehlersuche

**Problem: Datenbankverbindung fehlschlägt**
```bash
# .env prüfen
cat .env

# MySQL-Zugriff testen
mysql -u DB_USER -p -h DB_HOST
```

**Problem: OpenAI-Fehler**
```bash
# API Key prüfen
grep OPENAI_API_KEY .env

# Test-Request
curl https://api.openai.com/v1/models \
  -H "Authorization: Bearer $OPENAI_API_KEY"
```

**Problem: 500 Internal Server Error**
```bash
# PHP Error Log prüfen
tail /var/log/php_errors.log

# Oder in Browser: 
# php.ini: display_errors = On (nur Entwicklung!)
```

## 📦 Deployment auf Webhosting

1. **Dateien hochladen** (via FTP/SFTP)
2. **Datenbank erstellen** (phpMyAdmin)
3. **Schema importieren** (`database/schema.sql`)
4. **`.env` konfigurieren** (DB-Zugangsdaten)
5. **Berechtigungen setzen** (`logs/`, `uploads/`)
6. **HTTPS aktivieren** (für OpenAI API erforderlich)

## 📄 Lizenz

Siehe `LICENSE` im Haupt-Repository

---

**Status:** ✅ SQL-Schema & PHP-API komplett | ⏳ Frontend ausstehend
