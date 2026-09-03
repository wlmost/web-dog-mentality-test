# Review: T1 (Tasks 1.1–1.4, Commit 1d8c48c)

**Gesamtempfehlung:** ok

## Muss (blockiert Abnahme)

_Keine._

## Sollte (vor Merge erledigen, kann diskutiert werden)

- **[Robustheit/DRY]** `index.php:2-11` und `api/config.php:9-18`: Die
  Guard-Logik (Existenzcheck, `http_response_code(503)`,
  `header('Retry-After: 120')`) ist in beiden Dateien fast wortgleich
  dupliziert, inkl. des Magic-Number-Literals `120`. Wenn der `Retry-After`-
  Wert oder die Guard-Logik künftig geändert wird (z. B. anderer Wert,
  zusätzliches Logging beim Greifen des Guards), muss das an zwei Stellen
  synchron gepflegt werden — ein klassisches DRY-Risiko, das bei genau der
  Art von "künftiger Änderung" greift, vor der hier explizit gewarnt wird.
  Vorschlag: eine kleine gemeinsame Datei (z. B.
  `maintenance-guard.php`) mit einer Funktion
  `checkMaintenanceMode(string $flagPath, string $contentType, callable $emitBody): void`,
  die beide Call-Sites (`index.php`, `api/config.php`) per `require_once`
  einbinden. Das zentralisiert auch den `Retry-After`-Wert an einer Stelle.

- **[Robustheit]** `api/config.php:12-18` vs. `api/config.php:292`: Der
  Guard ist korrekt als erste ausführbare Anweisung platziert und damit
  aktuell wirksam vor `getDbConnection()` (Zeile 292) sowie vor jedem
  Aufrufer, der `getDbConnection()`/`sanitizeString()` nutzt. Die Absicherung
  beruht aber ausschließlich auf der linearen Statement-Reihenfolge in einer
  prozeduralen Datei — es gibt keinen strukturellen Schutz dagegen, dass ein
  künftiger Edit oberhalb der Zeile 12 neuen Code mit Seiteneffekten einfügt
  (z. B. beim Zusammenführen mit anderen PRs) oder dass `getDbConnection()`
  künftig aus einem Kontext aufgerufen wird, der nicht den kompletten
  Dateikopf durchlaufen hat. Da `getDbConnection()` der einzige Ort ist, an
  dem tatsächlich `new mysqli(...)` aufgerufen wird, wäre eine zusätzliche
  (redundante) Prüfung direkt am Anfang von `getDbConnection()` selbst eine
  einfache Defense-in-Depth-Maßnahme, die unabhängig von der Position des
  Top-Level-Guards greift, ohne dass die aktuelle Lösung dafür entfernt
  werden müsste. Nicht blockierend, da die aktuelle Platzierung die Spec
  exakt erfüllt und praktisch verifiziert wurde (siehe
  `task-T1.notes.md:61-65`), aber erwähnenswert, da explizit nach
  "leicht kaputtzumachen durch künftige Änderungen" gefragt wurde.

## Könnte (optional, Verbesserung)

- **[Konsistenz]** `api/config.php:12`: Der neue Guard nutzt `is_file()`,
  während die direkt darunterliegende `loadEnv()`-Funktion (`api/config.php:27`)
  `file_exists()` verwendet. Beide sind hier funktional gleichwertig (kein
  Verzeichnis-Fall relevant), `is_file()` ist sogar die präzisere Wahl; die
  Doppelverwendung zweier ähnlicher Funktionen in derselben Datei ist aber
  ein kleiner Stilbruch, der bei einer künftigen Vereinheitlichung auffallen
  könnte.
- **[Robustheit, geringes Risiko]** `api/config.php:12`/`index.php:5`: Der
  Guard prüft `.maintenance` nur einmal pro Request (kein TOCTOU-Schutz nötig,
  da der Wert nicht sicherheitskritisch im engeren Sinn ist — ein Wechsel des
  Datei-Zustands zwischen Check und Verbindungsaufbau führt im schlimmsten
  Fall zu einer regulären Anfrage während des Deploy-Fensters, nicht zu einem
  Sicherheitsproblem). Keine Aktion nötig, nur zur Vollständigkeit der
  angefragten Bewertung: kein Pfad-Traversal-Risiko, da der Pfad
  (`__DIR__ . '/.maintenance'` bzw. `'/../.maintenance'`) fest verdrahtet ist
  und keinen Nutzer-Input enthält.
- **[CORS/UX bei Wartungsmodus]** `api/config.php:12-18`: Der Guard antwortet
  auch auf `OPTIONS`-Preflight-Requests mit 503, bevor die
  `CORS_ORIGIN`-Header (Zeile 78 ff.) gesetzt werden. Solange Frontend und API
  same-origin ausgeliefert werden (laut Kommentar `Zeile 72` der Standardfall
  bei diesem Shared-Hosting-Setup), ist das unkritisch. Bei einem künftigen
  Cross-Origin-Setup würde der Browser die 503-JSON-Antwort dem Frontend-JS
  ggf. gar nicht zustellen (fehlgeschlagener Preflight statt lesbarer
  Fehlermeldung). Nicht Teil dieser Spec, nur als Hinweis für spätere
  CORS-Änderungen.

## Lob (kurz, was gut gelöst wurde)

- Platzierung des Guards in `api/config.php` ist exakt spec-konform (erste
  ausführbare Anweisung nach `declare(strict_types=1);`, vor `loadEnv()`,
  vor `config.local.php`-Include, weit vor dem eager `getDbConnection()` in
  Zeile 292) und wurde laut `task-T1.notes.md:41-67` nicht nur gelesen,
  sondern live gegen den PHP-Built-in-Server verifiziert (inkl. Log-Beleg,
  dass beim Greifen des Guards **kein** `mysqli`-Verbindungsversuch mehr
  auftritt) — das ist genau die Art von Nachweis, die bei einer
  sicherheits-/verfügbarkeitskritischen Anforderung wie dieser zählt.
- `maintenance.html` ist tatsächlich vollständig autark: keine externen
  Requests, Fonts, Bilder oder Scripts — nur Inline-`<style>`, plus
  `<meta name="robots" content="noindex, nofollow">`, was sinnvoll verhindert,
  dass die Wartungsseite versehentlich indexiert wird. `php -l` für beide
  PHP-Dateien fehlerfrei, `git check-ignore .maintenance` bestätigt den
  `.gitignore`-Eintrag — beides in diesem Review reproduziert.
- Beide 503-Antworten sind vollständig und leak-frei: `exit;` direkt nach
  Body-Ausgabe, keine nachfolgende Ausgabe möglich (kein BOM/Whitespace vor
  `<?php`, verifiziert), `Retry-After: 120` und `Content-Type` konsistent in
  beiden Guards gesetzt, JSON-Fehlermeldung ohne Stacktrace/Interna — passt
  zum bestehenden `sendError()`-Antwortformat (`{"error": ...}`).
- `.gitignore`-Ergänzung fügt sich sauber in den bestehenden
  Block-Kommentar-Stil der Datei ein (eigener Abschnitt mit erklärendem
  Kommentar, analog zu den Nachbar-Blöcken „Environment", „Wizard lock file").
