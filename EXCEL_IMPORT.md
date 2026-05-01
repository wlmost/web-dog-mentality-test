# Excel-Import für Testbatterien

## Installation

### Composer-Abhängigkeiten installieren

```bash
cd /path/to/web-dog-mentality-test
composer install
```

Dies installiert PhpSpreadsheet für Excel-Import.

## API: import.php

### Endpunkte

#### GET /api/import.php?template
Lädt Excel-Template herunter.

**Response:** Excel-Datei (.xlsx)

**Template-Struktur:**
- **Zeile 0** (optional): Metadaten (Batterie-Name in A0, Beschreibung in B0)
- **Zeile 1**: Header (Test Nr., Testname, OCEAN Dimension, Max. Wert, Beschreibung)
- **Ab Zeile 2**: Test-Daten

**Beispiel:**

| Test Nr. | Testname | OCEAN Dimension | Max. Wert | Beschreibung |
|----------|----------|-----------------|-----------|--------------|
| 1 | Reaktion auf neue Umgebung | Offenheit | 7 | Wie reagiert... |
| 2 | Impulskontrolle | Gewissenhaftigkeit | 7 | Kann der Hund... |

#### POST /api/import.php
Importiert Excel-Datei als Testbatterie.

**Request:**
- Content-Type: `multipart/form-data`
- Field: `file` (Excel-Datei .xlsx oder .xls)

**Validierung:**
- Dateigröße: max. 5 MB
- Format: .xlsx oder .xls
- Header-Zeile muss exakt übereinstimmen
- Max. 1000 Tests pro Batterie
- Test-Nummer: >= 1, eindeutig
- OCEAN Dimension: Einer der 5 Werte (deutsch)
- Max. Wert: 1-100

**Response (Erfolg):**
```json
{
  "message": "Battery imported successfully",
  "battery_id": 3,
  "battery_name": "Meine Testbatterie",
  "test_count": 35
}
```

**Response (Fehler):**
```json
{
  "error": "Invalid OCEAN dimension in row 5: 'Openness'. Expected: Offenheit, Gewissenhaftigkeit, Extraversion, Verträglichkeit, Neurotizismus"
}
```

## Frontend-Integration

### HTML (index.html)

Füge Upload-Formular in Tab "Batterien" hinzu:

```html
<div class="mb-3">
    <label for="battery-file" class="form-label">Excel-Datei hochladen</label>
    <input type="file" class="form-control" id="battery-file" accept=".xlsx,.xls">
    <div class="form-text">
        <a href="#" onclick="downloadTemplate(); return false;">
            <i class="bi bi-download"></i> Excel-Template herunterladen
        </a>
    </div>
</div>
<button class="btn btn-primary btn-lg" onclick="uploadBattery()">
    <i class="bi bi-upload"></i> Batterie importieren
</button>
```

### JavaScript (api.js)

```javascript
// Template herunterladen
async downloadTemplate() {
    window.location.href = `${this.baseUrl}/import.php?template`;
}

// Excel hochladen
async uploadBattery(file) {
    const formData = new FormData();
    formData.append('file', file);
    
    const url = `${this.baseUrl}/import.php`;
    
    try {
        const response = await fetch(url, {
            method: 'POST',
            body: formData
            // KEIN Content-Type Header! (wird automatisch gesetzt)
        });
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || `HTTP ${response.status}`);
        }
        
        return data;
    } catch (error) {
        console.error('Upload Error:', error);
        throw error;
    }
}
```

### JavaScript (app.js)

```javascript
async function downloadTemplate() {
    await api.downloadTemplate();
}

async function uploadBattery() {
    const fileInput = document.getElementById('battery-file');
    
    if (!fileInput.files || fileInput.files.length === 0) {
        showToast('Bitte Datei auswählen', 'warning');
        return;
    }
    
    const file = fileInput.files[0];
    
    showLoading();
    try {
        const result = await api.uploadBattery(file);
        
        showToast(
            `Batterie "${result.battery_name}" mit ${result.test_count} Tests importiert`,
            'success'
        );
        
        // Batterien-Liste neu laden
        await loadBatteries();
        
        // Input leeren
        fileInput.value = '';
        
    } catch (error) {
        showToast(error.message, 'danger');
    } finally {
        hideLoading();
    }
}
```

## Excel-Template erstellen

### Option 1: Template-Download
1. Rufe `GET /api/import.php?template` auf
2. Erhalte vorgefertigtes Excel mit:
   - Header-Zeile
   - Beispieldaten
   - Dropdown für OCEAN-Dimensionen
   - Spaltenbreiten optimiert
   - Anleitung als Kommentar

### Option 2: Manuell erstellen

**Zeile 1 (Header):**
```
A1: Test Nr.
B1: Testname
C1: OCEAN Dimension
D1: Max. Wert
E1: Beschreibung
```

**Ab Zeile 2 (Daten):**
```
A2: 1
B2: Reaktion auf neue Umgebung
C2: Offenheit
D2: 7
E2: Wie reagiert der Hund auf neue Umgebung?
```

**Wichtig:**
- Header **exakt** wie angegeben (inkl. Leerzeichen, Umlaute)
- OCEAN-Dimensionen auf **Deutsch** (nicht O/C/E/A/N!)
- Test-Nummern fortlaufend, **keine Duplikate**
- Max. Wert zwischen 1 und 100

## Beispiel-Workflow

### 1. Template herunterladen
```bash
curl -o template.xlsx "http://localhost:8000/api/import.php?template"
```

### 2. Template ausfüllen
- In Excel/LibreOffice öffnen
- Batterie-Name in A0 eintragen (optional)
- Tests ab Zeile 2 eintragen
- Als .xlsx speichern

### 3. Import durchführen
```bash
curl -X POST \
  -F "file=@testbatterie.xlsx" \
  http://localhost:8000/api/import.php
```

### 4. Ergebnis prüfen
```bash
curl http://localhost:8000/api/batteries.php
```

## Häufige Fehler

### "Invalid Excel format. Expected headers: ..."
**Ursache:** Header-Zeile stimmt nicht überein

**Lösung:** Template verwenden oder Header exakt kopieren (inkl. Punkte, Leerzeichen)

### "Invalid OCEAN dimension in row X: 'Openness'"
**Ursache:** Englische statt deutsche Dimensionsnamen

**Lösung:** Verwende deutsche Namen:
- ✅ Offenheit, Gewissenhaftigkeit, Extraversion, Verträglichkeit, Neurotizismus
- ❌ Openness, Conscientiousness, Extraversion, Agreeableness, Neuroticism

### "Duplicate test number: X"
**Ursache:** Test-Nummer mehrfach verwendet

**Lösung:** Test-Nummern müssen eindeutig sein (1, 2, 3, ...)

### "Invalid max_value in row X: 0"
**Ursache:** max_value außerhalb 1-100

**Lösung:** Typische Werte sind 7 oder 14

### "PhpSpreadsheet not installed"
**Ursache:** Composer-Abhängigkeiten fehlen

**Lösung:**
```bash
composer install
```

## Migration von Desktop-App

Die Original-Desktop-App nutzt Excel-Dateien für Testbatterien. So migrierst du:

### 1. Excel-Datei aus Desktop-App exportieren
Die Desktop-App kann Excel-Dateien erstellen mit der Testbatterie.

### 2. Excel anpassen
Prüfe ob Header-Zeile dem Template entspricht. Falls nicht:
- Zeile 1 einfügen
- Header aus Template kopieren

### 3. OCEAN-Dimensionen prüfen
Desktop-App nutzt evtl. andere Spaltennamen. Ersetze:
- "O" → "Offenheit"
- "C" → "Gewissenhaftigkeit"
- "E" → "Extraversion"
- "A" → "Verträglichkeit"
- "N" → "Neurotizismus"

### 4. Import durchführen
Via Frontend oder API hochladen.

## Deployment auf Webhosting

### 1. Composer installieren
Falls nicht vorhanden, lokal installieren:
```bash
composer install --no-dev
```

### 2. vendor/ hochladen
Upload via FTP:
```
web-dog-mentality-test/
├── api/
├── vendor/           <- PhpSpreadsheet Libraries
│   └── phpoffice/
└── composer.json
```

### 3. PHP-Version prüfen
PhpSpreadsheet benötigt:
- PHP 8.0+
- Extensions: xml, zip, gd, mbstring

Prüfe mit:
```php
<?php
phpinfo();
```

### 4. Schreibrechte setzen
```bash
chmod 755 uploads/
```

## Best Practices

### Excel-Formatierung
- **Spalte A (Test Nr.)**: Zahlenformat (nicht Text)
- **Spalte D (Max. Wert)**: Zahlenformat
- **Spalte C (Dimension)**: Dropdown verwenden (verhindert Tippfehler)

### Batterie-Organisation
- **Dateiname = Batterie-Name**: `wesenstest_vdh.xlsx` → "wesenstest_vdh"
- **Versionierung**: `wesenstest_v2.xlsx` bei Updates
- **Backup**: Original-Excel aufbewahren

### Fehlerbehandlung
- **Preview-Funktion**: Zeige importierte Tests vor dem finalen Speichern
- **Dry-Run**: Import ohne DB-Commit (für Validierung)
- **Rollback**: Bei Fehler werden keine Daten geschrieben (Transaction)

## Troubleshooting

### PhpSpreadsheet zu langsam
**Problem:** Import großer Dateien dauert lange

**Lösungen:**
- Limit auf 100 Tests pro Batterie
- Cache-Verzeichnis konfigurieren
- `IOFactory::setReadDataOnly(true)` für schnelleres Lesen

### Memory Limit
**Problem:** Fatal error: Allowed memory size exhausted

**Lösung:**
```php
// In import.php
ini_set('memory_limit', '256M');
```

Oder in `.htaccess`:
```apache
php_value memory_limit 256M
```

### Upload-Limit
**Problem:** Datei zu groß

**Lösung in** `php.ini`:
```ini
upload_max_filesize = 10M
post_max_size = 10M
```

Oder `.htaccess`:
```apache
php_value upload_max_filesize 10M
php_value post_max_size 10M
```
