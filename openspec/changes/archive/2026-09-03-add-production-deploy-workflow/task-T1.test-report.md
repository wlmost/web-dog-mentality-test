# Test-Report: T1 (Tasks 1.1–1.4, Wartungsmodus)

**Status:** alle-gruen

**Geprüfter Commit:** `1d8c48c` auf Branch `feature/add-production-deploy-workflow`.

## Hinweis zur Testmethode

Dieses Projekt hat kein automatisiertes PHP-Testframework (kein PHPUnit,
kein `TESTING.md`) für diese Art von Änderung; die vorhandene Test-
Infrastruktur (`tests/ci-workflow-smoke.sh`, `tests/migration-runner-test.php`)
betrifft CI-Workflow- bzw. Migrations-Runner-Themen, nicht den Wartungsmodus.
Der Entwickler-Agent hat konsequenterweise ebenfalls per Live-Verifikation
gegen den PHP-Built-in-Server getestet statt Unit-Tests zu schreiben. Ich
habe dieselbe Methode **unabhängig wiederholt** (eigener Server-Prozess,
eigener Port, eigenes Log) und zusätzlich um Edge Cases erweitert, statt die
Entwickler-Angaben blind zu übernehmen. Es wurden **keine** Produktivcode-
Dateien geändert; `api/config.local.php` wurde für einen Test temporär
überschrieben und danach byte-identisch wiederhergestellt (per `diff`
verifiziert, `git status`/`git diff` zeigen keine Änderung an Produktivdateien).

## Hinzugefügte / geänderte Tests

Keine neuen automatisierten Testdateien hinzugefügt (siehe Hinweis oben —
keine passende Test-Infrastruktur für diese Änderung vorhanden). Stattdessen:
eigenständige Live-Verifikation gegen `php -S localhost:<port>` mit
Baseline-Vergleich, Byte-Diff, Log-Analyse und drei zusätzlichen Edge-Case-
Proben (siehe unten).

## Akzeptanzkriterien-Abdeckung

### Task 1.1 — `.maintenance`-Guard in `index.php`

- [x] `php -l index.php` fehlerfrei — bestätigt: `No syntax errors detected in index.php`
- [x] Mit vorhandener `.maintenance` liefert Aufruf HTTP 503 + `Retry-After` +
  Inhalt von `maintenance.html` — bestätigt: `HTTP/1.1 503`, Header
  `Retry-After: 120`, Body via `diff` **byte-identisch** zu `maintenance.html`
  (1191 Bytes, exakt gleich)
- [x] Ohne `.maintenance` unverändertes Redirect-Verhalten — bestätigt:
  `HTTP/1.1 302 Found`, `Location: /frontend/index.html`

### Task 1.2 — `.maintenance`-Guard in `api/config.php`

- [x] `php -l api/config.php` fehlerfrei — bestätigt: `No syntax errors detected in api/config.php`
- [x] Mit `.maintenance` liefert API-Aufruf HTTP 503 + JSON-Fehler, **ohne**
  dass `getDbConnection()` erreicht wird — bestätigt für `api/auth.php` und
  `api/dogs.php`: `HTTP/1.1 503`, `Content-Type: application/json; charset=utf-8`,
  Body `{"error":"Wartungsarbeiten – bitte in Kürze erneut versuchen"}`.
  **Zusätzlich verschärft geprüft** (über die Angabe des Entwicklers hinaus,
  nicht nur Log-Abwesenheit): `api/config.local.php` wurde temporär mit
  absichtlich kaputten DB-Zugangsdaten (`DB_HOST=10.255.255.1`,
  falscher User/Pass/DB-Name) überschrieben. Mit `.maintenance` + kaputter
  Konfiguration antwortete `api/auth.php` weiterhin sauber mit `503` in
  **0,013 s** (`time curl`), keine `DB Connection Error`- oder
  `Uncaught Exception`-Zeile im Server-Log. **Negativ-Kontrolle:** derselbe
  kaputte DB_HOST **ohne** `.maintenance` ließ den Request tatsächlich bis zum
  `mysqli`-Connect vordringen und über 8 s hängen (`curl: (28) Operation
  timed out`) — das beweist, dass der Guard ursächlich für den schnellen,
  sauberen 503 ist, und nicht Zufall oder ein anderer Fehlerpfad.
  `config.local.php` danach byte-identisch wiederhergestellt (`diff`
  bestätigt, `git status` zeigt keine Änderung).
- [x] Ohne `.maintenance` unverändertes Verhalten — bestätigt: `api/auth.php`
  und `api/dogs.php` liefern wie vor der Änderung `HTTP/1.0 500` mit
  `Uncaught Exception: Datenbankverbindung fehlgeschlagen …` im Log (Baseline
  ohne lokale DB, identisch zur Entwickler-Angabe).

### Task 1.3 — `maintenance.html`

- [x] Datei ist valides HTML ohne externe Requests — `grep -inE
  'http://|https://|<link|<script[^>]*src' maintenance.html` liefert keine
  Treffer. `tidy -q -e maintenance.html` meldet nur drei Warnungen
  (`<meta charset>`-Attribut, fehlendes `<style type>`) — beides HTML5-
  korrekte, veraltete-Regelwarnungen von `tidy` (HTML4-Regelwerk), **keine
  Fehler** (Exit-Code 1 = nur Warnungen, nicht 2 = Fehler).
- [x] Wird ohne Server-Fehler direkt ausgeliefert — bestätigt über
  `readfile()` in `index.php` (siehe Task 1.1), Content-Type korrekt gesetzt.

### Task 1.4 — `.gitignore`

- [x] `git check-ignore .maintenance` bestätigt den Eintrag — Ausgabe:
  `.gitignore:31:.maintenance    .maintenance`

## Zusätzliche Edge Cases (nicht vom Entwickler-Agent getestet)

1. **`.maintenance` als leeres Verzeichnis statt Datei:** `mkdir .maintenance`
   statt `touch .maintenance`. Ergebnis: `is_file()` liefert für ein
   Verzeichnis `false` (PHP-Doku-konform, `is_file()` prüft explizit auf
   reguläre Dateien). `index.php` → `302` (Normalbetrieb), `api/auth.php` →
   `500` (Normalbetrieb, DB-Connect wird versucht). **Kein Fehlverhalten** —
   ein versehentlich als Verzeichnis angelegtes `.maintenance` löst den
   Wartungsmodus nicht aus, sondern lässt die App normal weiterlaufen. Das
   ist eine sichere Fail-Open-Eigenschaft von `is_file()`, sollte aber in
   `CI_CD.md` (Folge-Task) erwähnt werden, falls der Deploy-Workflow jemals
   `mkdir` statt `touch` verwendet.
2. **Groß-/Kleinschreibung (`.Maintenance` statt `.maintenance`):** Auf dem
   hier verwendeten macOS-APFS-Volume (case-insensitive, per
   `touch .case-test-lower` + Prüfung auf `.CASE-TEST-LOWER` verifiziert)
   wurde `.Maintenance` fälschlich als Treffer erkannt und löste den
   503-Wartungsmodus aus. **Dies ist kein Bug im PHP-Code** — `is_file()`
   delegiert die Pfadauflösung an das Dateisystem; auf Linux (case-sensitive,
   die tatsächliche Zielplattform alfahosting) würde `.Maintenance`
   **nicht** mit `.maintenance` übereinstimmen und den Guard **nicht**
   auslösen. Empfehlung für den Deploy-Workflow (`CI_CD.md`/`deploy.yml`,
   Folge-Task): konsequent exakt `.maintenance` (Kleinschreibung) verwenden;
   dieses Verhalten ist plattformabhängig und sollte nicht überprüft, sondern
   nur dokumentiert werden.
3. **Gleichzeitiges Toggeln von `.maintenance` während laufender Requests
   (Race-Simulation):** 20 parallele `curl`-Requests an `index.php`,
   abwechselnd mit `touch`/`rm` von `.maintenance` unmittelbar davor
   gestartet. Ergebnis: jede der 20 Antworten war **entweder** eine
   vollständige 503-Antwort mit exakt 1191 Byte (= vollständiges
   `maintenance.html`) **oder** eine vollständige 302-Antwort mit 0 Byte
   Body — **keine** abgeschnittenen, gemischten oder korrupten Antworten.
   Erklärungsansatz: `is_file()` wird einmal pro Request-Ausführung geprüft;
   PHPs Request-Lebenszyklus ist pro Request in sich abgeschlossen, sodass
   ein Toggle zwischen zwei Requests keinen Zwischenzustand innerhalb einer
   Antwort erzeugen kann. Kein Fehlverhalten gefunden.

## Ausführungs-Ergebnis

```
$ php -l index.php
No syntax errors detected in index.php
$ php -l api/config.php
No syntax errors detected in api/config.php

# Baseline (ohne .maintenance)
GET /index.php      -> HTTP/1.1 302 Found, Location: /frontend/index.html
GET /api/auth.php   -> HTTP/1.0 500, Log: "Uncaught Exception: Datenbankverbindung fehlgeschlagen …"
GET /api/dogs.php   -> HTTP/1.0 500, Log: "Uncaught Exception: Datenbankverbindung fehlgeschlagen …"

# Mit .maintenance
GET /index.php      -> HTTP/1.1 503, Retry-After: 120, Content-Type: text/html; charset=utf-8
                        Body == maintenance.html (diff: identisch, 1191 Bytes)
GET /api/auth.php   -> HTTP/1.1 503, Retry-After: 120, Content-Type: application/json; charset=utf-8
                        Body: {"error":"Wartungsarbeiten – bitte in Kürze erneut versuchen"}
GET /api/dogs.php   -> identisch zu auth.php
Log: keine "DB Connection Error" / "Uncaught Exception" Zeilen für diese drei Requests

# Negativ-Kontrolle: .maintenance + absichtlich kaputte DB_HOST
GET /api/auth.php   -> HTTP/1.1 503 in 0,013s (kein DB-Connect-Versuch im Log)
# ohne .maintenance, gleiche kaputte DB_HOST:
GET /api/auth.php   -> curl: (28) Operation timed out after 8006 ms (DB-Connect wurde versucht, hängt)

$ git check-ignore -v .maintenance
.gitignore:31:.maintenance	.maintenance

$ grep -inE 'http://|https://|<link|<script[^>]*src' maintenance.html
(keine Treffer)

$ tidy -q -e maintenance.html
line 4 column 1 - Warning: <meta> proprietary attribute "charset"
line 4 column 1 - Warning: <meta> lacks "content" attribute
line 8 column 1 - Warning: <style> inserting "type" attribute
(Exit-Code 1 = nur Warnungen, keine Fehler)

# Edge Case: .maintenance als Verzeichnis
GET /index.php      -> HTTP/1.1 302 (Normalbetrieb, kein Fehlverhalten)
GET /api/auth.php   -> HTTP/1.0 500 (Normalbetrieb)

# Edge Case: Race/Toggle (20 parallele Requests)
Alle 20 Antworten vollständig und konsistent (keine Truncation/Mischung)
```

## Fehler (falls vorhanden)

Keine. Alle elf Akzeptanzkriterien aus `tasks.md` Abschnitt 1 (Tasks
1.1–1.4) sind erfüllt und wurden eigenständig live nachgewiesen, nicht nur
aus `task-T1.notes.md` übernommen. Zusätzlich wurden drei Edge Cases
geprüft, die über die Entwickler-Verifikation hinausgehen; keiner davon
deckt ein Fehlverhalten im Produktivcode auf. Zwei davon sind reine
Dokumentations-/Betriebs-Hinweise für Folge-Tasks (Groß-/Kleinschreibung ist
plattformabhängig; Verzeichnis-statt-Datei-Fall verhält sich sicher
fail-open).
