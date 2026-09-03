# Task T1 (1.1–1.4) — Wartungsmodus

## Umfang

Implementiert den `.maintenance`-Guard-Mechanismus aus design.md D7 als
Grundlage für den späteren Deploy-Workflow (Abschnitt "1. Wartungsmodus" in
`tasks.md`, Tasks 1.1–1.4).

## Geänderte / neue Dateien

- `index.php` — Guard ganz am Anfang, vor dem bestehenden 302-Redirect: bei
  vorhandener `.maintenance`-Datei im Projekt-Root `HTTP 503` +
  `Retry-After: 120` + `Content-Type: text/html; charset=utf-8` +
  `readfile(__DIR__ . '/maintenance.html')` + `exit`.
- `api/config.php` — Guard als erste ausführbare Anweisung direkt nach
  `declare(strict_types=1);`, also vor `loadEnv()`, vor dem Laden von
  `config.local.php` und weit vor dem eager `getDbConnection()`-Aufruf am
  Dateiende (`new mysqli(...)`). Bei vorhandener `../.maintenance` `HTTP 503`
  + `Retry-After: 120` + `Content-Type: application/json; charset=utf-8` +
  `{"error":"Wartungsarbeiten – bitte in Kürze erneut versuchen"}` + `exit`.
- `maintenance.html` (neu) — statische, in sich geschlossene HTML-Seite ohne
  PHP, ohne externe Requests/Fonts/Assets (nur Inline-`<style>`), deutschsprachig,
  `<meta name="robots" content="noindex, nofollow">`.
- `.gitignore` — Eintrag `.maintenance` im Block "Temporary files" ergänzt
  (Laufzeit-Flag, wird vom Deploy-Workflow per SSH gesetzt/entfernt, darf nie
  eingecheckt werden).

## Designentscheidungen

- Guard-Reihenfolge in `api/config.php` exakt wie in design.md D7 gefordert:
  vor `loadEnv()`, vor `config.local.php`-Include, vor allen `define()`s und
  vor dem eager-Connect `$conn = getDbConnection();` am Dateiende. Dadurch
  wird während der Wartung garantiert keine DB-Verbindung aufgebaut, auch
  wenn `config.local.php` fehlt oder fehlerhaft ist.
- `readfile()` statt `include`/`require` in `index.php`, weil `maintenance.html`
  reines HTML ist — kein PHP-Parsing nötig, minimale Kopplung.
- `exit;` (Statement) statt `exit();` (Funktionsaufruf) — beide sind
  äquivalent in PHP, die Spec-Vorgabe aus tasks.md/design.md nennt explizit
  `exit;`.

## Verifikation (praktisch durchgeführt, nicht nur Code-Review)

1. `php -l index.php` und `php -l api/config.php` — beide fehlerfrei.
2. `git check-ignore -v .maintenance` → `.gitignore:31:.maintenance
   .maintenance` — Eintrag greift.
3. PHP-Built-in-Server (`php -S localhost:8099`) im Projektroot gestartet:
   - **Ohne** `.maintenance`: `GET /index.php` → `HTTP/1.1 302 Found`
     (unverändertes Redirect-Verhalten). `GET /api/auth.php` → `HTTP 500`
     mit `Uncaught Exception: Datenbankverbindung fehlgeschlagen …` in
     `api/config.php:118` (Baseline-Verhalten ohne lokale DB — bestätigt,
     dass der Request ohne `.maintenance` bis zum `getDbConnection()`-Aufruf
     durchläuft).
   - **Mit** `.maintenance` (`touch .maintenance`): `GET /index.php` →
     `HTTP/1.1 503 Service Unavailable`, Header `Retry-After: 120`,
     `Content-Type: text/html; charset=utf-8`, Body ist byte-identisch zu
     `maintenance.html` (`diff` bestätigt). `GET /api/auth.php` →
     `HTTP/1.1 503 Service Unavailable`, Header `Retry-After: 120`,
     `Content-Type: application/json; charset=utf-8`, Body
     `{"error":"Wartungsarbeiten – bitte in Kürze erneut versuchen"}`
     (JSON-escaped: `–`/`ü`).
   - Mit `.maintenance` erschien **kein** `Uncaught Exception …
     config.php:118`-Eintrag im Server-Log (verglichen mit dem
     Baseline-Log ohne `.maintenance`) — bestätigt, dass der Guard vor
     `getDbConnection()`/`new mysqli(...)` greift und die DB-Verbindung
     während der Wartung nie versucht wird.
   - `.maintenance` nach dem Test wieder entfernt (`rm -f .maintenance`,
     `git status` bestätigt sauberen Arbeitsbaum).

## Abweichungen von der Spec

Keine.

## Hinweise für Folge-Tasks

- Task 2.1 (`build.sh`) muss `maintenance.html` zusätzlich in
  `dist/dog-mentality-test/` kopieren, damit die Seite auch im
  Deploy-Paket vorhanden ist.
- Task 3.3/3.4 (`deploy.yml`) legen `.maintenance` per SSH auf dem Server an
  bzw. entfernen es wieder; der rsync-Schritt muss `.maintenance` als
  `--exclude` führen (bereits in `.gitignore`, noch nicht im rsync-Aufruf,
  da `deploy.yml` nicht Teil dieser Task war).
