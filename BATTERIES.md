# Testbatterie-Verwaltung

## Übersicht

Die Testbatterie-Verwaltung ermöglicht das Erstellen, Bearbeiten und Löschen von Testbatterien mit ihren zugehörigen Tests.

## API: batteries.php

### Endpunkte

#### GET /api/batteries.php
Listet alle Testbatterien auf.

**Response:**
```json
{
  "batteries": [
    {
      "id": 1,
      "name": "Wesenstest Standard VDH",
      "description": "Standardisierte Testbatterie...",
      "test_count": 35,
      "created_at": "2025-12-15 10:00:00"
    }
  ]
}
```

#### GET /api/batteries.php?id=1
Lädt eine einzelne Batterie mit allen Tests.

**Response:**
```json
{
  "id": 1,
  "name": "Wesenstest Standard VDH",
  "description": "...",
  "created_at": "2025-12-15 10:00:00",
  "tests": [
    {
      "id": 1,
      "test_number": 1,
      "test_name": "Reaktion auf neue Umgebung",
      "ocean_dimension": "Offenheit",
      "max_value": 7
    },
    ...
  ]
}
```

#### POST /api/batteries.php
Erstellt eine neue Testbatterie mit Tests.

**Request:**
```json
{
  "name": "Meine Testbatterie",
  "description": "Beschreibung (optional)",
  "tests": [
    {
      "test_number": 1,
      "test_name": "Test 1",
      "ocean_dimension": "Offenheit",
      "max_value": 7
    },
    {
      "test_number": 2,
      "test_name": "Test 2",
      "ocean_dimension": "Gewissenhaftigkeit",
      "max_value": 14
    }
  ]
}
```

**Validierung:**
- `name`: Pflichtfeld
- `tests`: Muss Array mit mindestens 1 Test sein
- `test_number`: Integer >= 1
- `max_value`: Integer zwischen 1 und 100
- `ocean_dimension`: Muss einer der folgenden Werte sein:
  - Offenheit
  - Gewissenhaftigkeit
  - Extraversion
  - Verträglichkeit
  - Neurotizismus

#### PUT /api/batteries.php?id=1
Aktualisiert eine Batterie (Metadaten und/oder Tests).

**Request (nur Metadaten):**
```json
{
  "name": "Neuer Name",
  "description": "Neue Beschreibung"
}
```

**Request (mit Tests - ersetzt ALLE Tests):**
```json
{
  "name": "Aktualisierte Batterie",
  "tests": [
    { ... neue Tests ... }
  ]
}
```

**Wichtig:** Wenn `tests` im Request enthalten ist, werden ALLE alten Tests gelöscht und durch die neuen ersetzt!

#### DELETE /api/batteries.php?id=1
Löscht eine Batterie (und alle zugehörigen Tests via CASCADE).

**Fehler:**
- HTTP 409 Conflict: Falls die Batterie in Sessions verwendet wird

## Frontend-Integration

### API-Client (api.js)

```javascript
// Alle Batterien laden
const batteries = await api.getBatteries();

// Einzelne Batterie laden
const battery = await api.getBattery(1);

// Neue Batterie erstellen
const newBattery = await api.createBattery({
  name: "Meine Batterie",
  tests: [...]
});

// Batterie aktualisieren
await api.updateBattery(1, { name: "Neuer Name" });

// Batterie löschen
await api.deleteBattery(1);
```

### Session-Erstellung mit Batterieauswahl

Die Funktion `createNewSession()` in `app.js` zeigt ein Modal zur Auswahl der Testbatterie:

1. Lädt alle verfügbaren Batterien
2. Zeigt Modal mit Dropdown
3. Erstellt Session mit ausgewählter Batterie
4. Lädt Batterie-Tests und rendert Testtabelle

### Test-Tabelle Rendering

Die Funktion `renderTestsTable()` erstellt die Test-Eingabetabelle:

- Zeigt alle Tests der Batterie
- Score-Buttons (-2 bis +2)
- Automatische Normierung auf `max_value`
- Notizen-Feld pro Test
- OCEAN-Dimension als Badge (farbcodiert)

### Score-Speicherung

```javascript
// Wird bei Klick auf Score-Button aufgerufen
await setTestScore(testNumber, score, maxValue);

// Normiert Score: (-2 bis +2) → (-max_value bis +max_value)
const normalizedScore = Math.round((score / 2) * maxValue);

// Speichert via Results API
await api.saveResult({
  session_id: currentSession.session_id,
  test_number: testNumber,
  score: normalizedScore,
  notes: "..."
});
```

## Beispieldaten

Zum Laden der Beispiel-Testbatterien:

```bash
mysql -u root -p dog_mentality < database/example-data.sql
```

Enthält:
- **Batterie 1**: Wesenstest Standard VDH (35 Tests)
- **Batterie 2**: Kurz-Screening (5 Tests)
- 3 Beispiel-Hunde
- 1 Beispiel-Session

## Best Practices

### Batterie-Design

1. **Test-Nummerierung**: Fortlaufend ab 1
2. **OCEAN-Balance**: Gleiche Anzahl Tests pro Dimension (empfohlen: 7 pro Dimension)
3. **max_value**:
   - Standard: 7 (für -7 bis +7 Wertebereich)
   - Ausnahme: 14 für besonders wichtige Tests (z.B. "Freundlichkeit gegenüber Fremden")

### Löschen von Batterien

- **Vor dem Löschen prüfen**: Ob Sessions existieren (API gibt HTTP 409 zurück)
- **Alternative**: Batterie umbenennen (z.B. "VERALTET - Alte Version")
- **Best Practice**: Neue Version als neue Batterie anlegen, alte behalten für Historien

### Migration von Desktop-App

Die Original-Desktop-App nutzt Excel-Import für Batterien. Für die Web-App:

1. **Option A**: Testbatterien manuell via phpMyAdmin in `test_batteries` + `battery_tests` einfügen
2. **Option B**: Excel-Import-Feature entwickeln (TODO)
3. **Option C**: SQL-Script aus Excel generieren (z.B. mit Python-Script)

## Troubleshooting

### Fehler: "Invalid ocean_dimension"

Stelle sicher, dass die **deutschen** Dimensionsnamen verwendet werden:
- ✅ "Offenheit", "Gewissenhaftigkeit", "Extraversion", "Verträglichkeit", "Neurotizismus"
- ❌ "O", "C", "E", "A", "N" (nur für OCEAN-Scores, nicht für Dimensionsnamen!)

### Fehler: "Cannot delete battery: X sessions are using it"

Die Batterie wird in Sessions verwendet. Lösungen:
1. Sessions löschen (VORSICHT: Datenverlust!)
2. Batterie behalten und nur umbenennen
3. In Session andere Batterie zuweisen (erfordert manuelle DB-Änderung)

### Tests werden nicht angezeigt

Prüfe in Browser-Konsole:
1. API-Call erfolgreich? (Network Tab)
2. Batterie hat Tests? (`battery.tests.length > 0`)
3. JavaScript-Fehler? (Console Tab)
