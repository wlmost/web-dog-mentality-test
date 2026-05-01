# Excel/CSV Import - Formatspezifikation

## ✅ Unterstützte Formate

Die Import-Funktionen unterstützen jetzt das **Original-Format** aus `dog-mentality-test`:

### Excel (.xlsx, .xls)
- **Datei:** `Testbatterie_Tiergestuetzte_Arbeit_OCEAN_Freigelaende_V4.xlsx`
- **Format:** 10 Spalten (erweitert)
- **Endpoint:** `api/import.php`

### CSV (.csv)
- **Separator:** Semikolon (`;`) oder Komma (`,`)
- **Encoding:** UTF-8
- **Endpoint:** `api/import-csv.php`

---

## 📋 Spalten-Schema

### Pflichtfelder (erste 3 Spalten):

| Spalte | Name | Typ | Beschreibung | Beispiel |
|--------|------|-----|--------------|----------|
| A | **Test-Nr.** | Integer | Eindeutige Testnummer | `1`, `2`, `3` |
| B | **OCEAN-Dimension** | String | OCEAN-Kategorie | `Extraversion` |
| C | **Testname** | String | Bezeichnung des Tests | `Begrüßung & Annäherung` |

### Optionale Felder (Spalten 4-10):

| Spalte | Name | Beschreibung | Beispiel |
|--------|------|--------------|----------|
| D | **Setting & Durchführung** | Testaufbau und Ablauf | `Die Figurant:in nähert sich dem Hund an.` |
| E | **Material** | Benötigtes Material | `Diverses Spielzeug` oder `-` |
| F | **Dauer** | Zeitdauer des Tests | `1 min`, `1.5 min` |
| G | **Rolle der Figurant:in** | Was tut die Testperson | `Nähert sich ruhig dem Hund` |
| H | **Beobachtungskriterien** | Was wird beobachtet | `Anlehnung: Sucht der Hund Kontakt?` |
| I | **Bewertungsskala** | Skalenbeschreibung | `-2 bis +2, mit Ankern` |
| J | **Score** | Aktuelles Testergebnis | Bleibt leer beim Import |

---

## 🔧 Automatische Verarbeitung

### Beschreibung zusammenbauen

Die Spalten D-I werden automatisch zu einer Beschreibung kombiniert:

**Beispiel:**
```
Setting: Die Figurant:in nähert sich dem Hund an. | 
Material: - | 
Dauer: 1 min | 
Figurant: Nähert sich ruhig dem Hund | 
Kriterien: Anlehnung: Sucht der Hund Kontakt? | 
Skala: -2 bis +2, mit Ankern
```

### Max. Wert extrahieren

Aus der Bewertungsskala wird automatisch der max. Wert extrahiert:

| Skala | Extrahierter Wert |
|-------|-------------------|
| `-2 bis +2` | `2` |
| `-3 bis +3` | `3` |
| `0 bis 5` | `5` |
| `1-10` | `10` |

**Default:** `5` (falls keine Zahl gefunden)

---

## 📊 OCEAN-Dimensionen

**Erlaubte Werte:**
- `Offenheit`
- `Gewissenhaftigkeit`
- `Extraversion`
- `Verträglichkeit`
- `Neurotizismus`

⚠️ **Groß-/Kleinschreibung beachten!**

---

## 📁 Beispiel-Dateien

### Excel-Format (.xlsx)

Verwende die Original-Datei:
```
dog-mentality-test/Testbatterie_Tiergestuetzte_Arbeit_OCEAN_Freigelaende_V4.xlsx
```

### CSV-Format (.csv)

Siehe: `database/example-battery-import.csv`

**Header:**
```csv
Test-Nr.;OCEAN-Dimension;Testname;Setting & Durchführung (Praxisform);Material;Dauer;Rolle der Figurant:in;Beobachtungskriterien (Checkliste);Bewertungsskala (-2 bis +2, mit Ankern);Score (-2..2)
```

**Datenzeile:**
```csv
1;Extraversion;Begrüßung & Annäherung;Die Figurant:in nähert sich dem Hund an.;-;1 min;Nähert sich ruhig dem Hund;Anlehnung: Sucht der Hund Kontakt?;-2 bis +2;
```

---

## 🚀 Import durchführen

### Via Frontend (index.html)

1. Tab **"Batterien"** öffnen
2. **"Batterie importieren"** klicken
3. Excel-Datei (.xlsx) auswählen
4. **Upload** - Import erfolgt automatisch
5. Erfolgsmeldung mit Anzahl importierter Tests

### Via cURL (API direkt)

**Excel-Import:**
```bash
curl -X POST \
  -F "file=@Testbatterie_Tiergestuetzte_Arbeit_OCEAN_Freigelaende_V4.xlsx" \
  http://localhost/api/import.php
```

**CSV-Import:**
```bash
curl -X POST \
  -F "file=@example-battery-import.csv" \
  http://localhost/api/import-csv.php
```

---

## ✅ Validierungen

### Beim Import werden geprüft:

1. **Header-Zeile:**
   - Erste 3 Spalten müssen exakt sein: `Test-Nr.`, `OCEAN-Dimension`, `Testname`

2. **Test-Nummer:**
   - Muss eine Zahl ≥ 1 sein
   - Keine Duplikate innerhalb der Batterie

3. **OCEAN-Dimension:**
   - Muss eine der 5 erlaubten Dimensionen sein
   - Korrekte Schreibweise erforderlich

4. **Testname:**
   - Darf nicht leer sein
   - Keine Längenbeschränkung

5. **Datei-Größe:**
   - Maximum: 5 MB (Excel) / 2 MB (CSV)

6. **Zeilen-Limit:**
   - Maximum: 1000 Tests pro Batterie

---

## 🔄 Excel → CSV Konvertierung

Falls du nur CSV-Import nutzen möchtest (ohne PhpSpreadsheet):

### In Excel:

1. Datei öffnen: `Testbatterie_*.xlsx`
2. **Datei** → **Speichern unter**
3. Format wählen: **CSV UTF-8 (durch Trennzeichen getrennt) (*.csv)**
4. Speichern

### In LibreOffice Calc:

1. Datei öffnen
2. **Datei** → **Speichern unter**
3. Dateityp: **Text CSV (.csv)**
4. **Feldtrenner:** Semikolon (`;`)
5. **Zeichensatz:** UTF-8
6. Speichern

---

## 🆘 Troubleshooting

### Problem: "Invalid header row"

**Ursache:** Header-Spalten stimmen nicht überein

**Lösung:**
- Prüfe erste 3 Spalten: `Test-Nr.`, `OCEAN-Dimension`, `Testname`
- Exakte Schreibweise erforderlich (inkl. Bindestriche)
- Keine Leerzeichen vor/nach den Namen

### Problem: "Invalid OCEAN dimension"

**Fehlermeldung:** `Invalid OCEAN dimension in row 5: 'extraversion'`

**Ursache:** Kleinschreibung oder Tippfehler

**Lösung:**
- Großschreibung beachten: `Extraversion` (nicht `extraversion`)
- Umlaute korrekt: `Verträglichkeit` (nicht `Vertraeglichkeit`)

### Problem: Import bricht bei Zeile X ab

**Ursache:** Leere Zellen oder ungültige Werte

**Lösung:**
- Spalte A (Test-Nr.) darf nicht leer sein
- Spalte B (OCEAN-Dimension) muss gültig sein
- Spalte C (Testname) darf nicht leer sein
- Alle anderen Spalten können leer sein (`-` oder leer lassen)

### Problem: Umlaute werden falsch dargestellt

**Ursache:** Encoding-Problem (meist bei CSV)

**Lösung:**
- CSV-Datei mit **UTF-8** Encoding speichern
- In Excel: "CSV UTF-8" Format wählen
- Alternativ: Excel-Format (.xlsx) nutzen

---

## 📊 Beispiel-Ausgabe

**Erfolgreicher Import:**
```json
{
  "success": true,
  "battery_id": 1,
  "battery_name": "Testbatterie_Tiergestuetzte_Arbeit_OCEAN_Freigelaende_V4",
  "test_count": 35,
  "message": "Battery imported successfully"
}
```

**Fehler:**
```json
{
  "success": false,
  "error": "Invalid OCEAN dimension in row 12: 'Extraverion'",
  "details": {
    "row": 12,
    "value": "Extraverion",
    "expected": ["Offenheit", "Gewissenhaftigkeit", "Extraversion", "Verträglichkeit", "Neurotizismus"]
  }
}
```

---

## 🎯 Zusammenfassung

**Unterstützte Formate:**
- ✅ Excel (.xlsx, .xls) - 10 Spalten
- ✅ CSV (.csv) - 10 Spalten, UTF-8, Semikolon/Komma

**Pflichtfelder:**
- Test-Nr. (Spalte A)
- OCEAN-Dimension (Spalte B)
- Testname (Spalte C)

**Automatische Verarbeitung:**
- Beschreibung aus Spalten D-I
- Max. Wert aus Bewertungsskala extrahiert
- Batteriename aus Dateinamen

**Validierung:**
- Header-Check
- OCEAN-Dimensionen
- Test-Nummern (keine Duplikate)
- Datei-Größe (5 MB / 2 MB)

Viel Erfolg beim Import! 🎉
