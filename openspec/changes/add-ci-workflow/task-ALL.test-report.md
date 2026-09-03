# Test-Report: T-ALL (add-ci-workflow)

**Status:** alle-gruen (mit einer dokumentierten, nicht schließbaren Lücke — siehe unten)

## Vorbemerkung: keine PHPUnit-Suite

`design.md` (Non-Goals) schließt eine PHPUnit-/Integrationstest-Suite für
diesen Change explizit aus — das ist einem Folge-Change vorbehalten. Es
existieren weder `CLAUDE.md` noch `TESTING.md` im Projekt-Root (bereits vom
Reviewer in `task-ALL.review.md` vermerkt), also keine projektspezifischen
Test-Konventionen, von denen abgewichen werden könnte.

„Tests“ bedeuten hier folglich: (a) ein neu geschriebenes, wiederholbares
Bash-Smoke-Skript, das die Verhaltens-Szenarien aus
`specs/continuous-integration/spec.md` automatisiert reproduziert, und
(b) eine eigenständige Verifikation gegen den echten GitHub-Actions-Lauf.
Kein Produktivcode wurde verändert (`ci.yml`, `build.sh`, `composer.json`,
`.gitignore`, `composer.lock` sind unangetastet).

## Hinzugefügte / geänderte Tests

- `tests/ci-workflow-smoke.sh` (neu): 21 Assertions, bash-basiert (analog zu
  `build.sh`, kein neues Framework). Prüft PHP-Lint (Happy Path, Fehlerpfad,
  Ausschluss `vendor/`/`dist/`), `.gitignore`/`composer.lock`-Zustand,
  `build.sh` (vollständiger Baum, drei fehlende-Kern-Datei-Szenarien,
  Escape-Sequenz-Freiheit), statische `ci.yml`-Struktur (Name, Trigger, Jobs,
  `needs`, Artefakt-Upload) und `composer.json`-Pflichtfelder. Räumt über
  `trap cleanup EXIT` alle temporären Verschiebungen/Dateien selbst auf —
  verifiziert per `git status --short` vor/nach Lauf (keine Reste).
  Ausführung: `bash tests/ci-workflow-smoke.sh` aus dem Projekt-Root.

Keine bestehenden Tests vorhanden, folglich keine Änderungen/Löschungen an
bestehenden Testdateien.

## Akzeptanzkriterien-Abdeckung

### Requirement: CI-Workflow bei Push und Pull Request
- [x] Push auf Feature-Branch startet `CI` mit den Jobs `lint`, `composer`,
  `build` — verifiziert am **echten** GitHub-Actions-Lauf
  `33736777974` auf `feature/add-ci-workflow` (`event: push`,
  `conclusion: success`, alle drei Jobs `success`, siehe Ausführungs-Ergebnis)
- [ ] **Pull Request gegen main** — **nicht verifiziert**. `gh run list`
  zeigt ausschließlich `event: push`-Läufe (3 Stück, auf `feat/` bzw.
  `feature/add-ci-workflow`); `gh pr list --state all` zeigt keinen
  offenen/geschlossenen PR für diesen Branch. Der `pull_request`-Trigger ist
  in `ci.yml:6` zwar syntaktisch korrekt konfiguriert (durch das
  Smoke-Skript statisch geprüft), aber **es gibt bislang keinen realen
  GitHub-Actions-Lauf mit `event: pull_request`**. Das ist keine
  Implementierungslücke, sondern ein Verifikations-Gap: der Trigger wurde
  nie scharf geschaltet, weil noch kein PR gegen `main` existiert. Kann erst
  geschlossen werden, sobald der PR für diesen Change eröffnet wird (dann
  sollte der resultierende CI-Lauf nochmal mit `gh run list --json event` auf
  `event: pull_request` geprüft werden).
- [x] Workflow-Name stabil `name: CI` — statisch geprüft
  (`tests/ci-workflow-smoke.sh`) und am echten Lauf bestätigt (Actions-Tab
  zeigt Workflow-Namen `CI`)

### Requirement: PHP-Syntaxprüfung aller Projektdateien
- [x] Alle Dateien syntaktisch korrekt → Exit 0 — reproduziert lokal
  (`find … | xargs -0 -r -n1 -P4 php -l`, 29 Projekt-Dateien, alle „No syntax
  errors“) und am echten `lint`-Job (`success`)
- [x] Eine Datei mit Syntaxfehler → Exit ≠ 0, Pfad wird genannt —
  reproduziert durch gezieltes Einschleusen einer kaputten `api/*.php`-Datei:
  Exit 1, Log nennt exakt den eingeschleusten Pfad
  (`./api/broken_test_injected.php` bzw. im Smoke-Skript
  `./api/broken-syntax.smoketest.php`)
- [x] `vendor/` und `dist/` werden nicht geprüft — durch gezieltes
  Einschleusen kaputter PHP-Dateien in **beide** Verzeichnisse verifiziert:
  Gesamt-Exit bleibt 0, die kaputten Dateien tauchen nicht im Log auf

### Requirement: Composer-Konfiguration und reproduzierbarer Install
- [x] `composer validate --strict` → Exit 0 — reproduziert via
  `docker run --rm -v "$PWD":/app -w /app composer:2 composer validate --strict`
  (`./composer.json is valid`, Exit 0) und am echten `composer`-Job
  (`success`)
- [x] Pflichtfelder `name`/`description`/`license`/`type` gesetzt,
  `config.platform.php` bleibt `"8.0"` — per Smoke-Skript geprüft
  (`composer.json` gelesen + grep-Assertions)
- [x] Install reproduzierbar (`composer install --no-dev --optimize-autoloader`
  mit Exit 0, `vendor/autoload.php` existiert danach) — **mit Einschränkung
  bei der lokalen Docker-Reproduktion, siehe unten** — und **eindeutig
  verifiziert am echten `composer`-Job** (Schritt „Abhängigkeiten
  installieren“ → `success`, siehe Job-Steps im Ausführungs-Ergebnis)
- [x] `composer.lock` versioniert, kein `.gitignore`-Eintrag mehr — per
  Smoke-Skript geprüft (`git ls-files --error-unmatch composer.lock`,
  `git check-ignore -q composer.lock` liefert `1`/nicht-ignoriert),
  zusätzlich manuell mit `git check-ignore -v` bestätigt; `vendor/` bleibt
  weiterhin ignoriert (Regressions-Check im Smoke-Skript)

**Zu dokumentierende Nebenbeobachtung (kein Befund gegen den Diff):** Das
generische Docker-Image `composer:2` (Alpine-Basis) hat standardmäßig
**kein** `ext-gd` aktiviert (`zip` ist vorhanden, `gd` fehlt). Ein reiner
`docker run composer:2 composer install --no-dev --optimize-autoloader`
schlägt deshalb lokal mit „ext-gd … missing from your system“ fehl — **nicht**
weil `composer.lock`/`composer.json` falsch wären, sondern weil das
Docker-Image nicht dieselben Extensions mitbringt wie der reale CI-Runner
(`shivammathur/setup-php@v2` mit `extensions: mbstring, ctype, gd, zip,
mysqli, curl, json`, exakt wie in `design.md` D9 begründet). Nachdem `gd` im
Container nachinstalliert wurde (`docker-php-ext-install gd`), lief
`composer install` sauber durch (9 Pakete, Exit 0, `vendor/autoload.php`
vorhanden, `composer.lock` blieb byteidentisch — keine Drift). Das bestätigt
D9 empirisch: die explizite `gd`/`zip`-Extension-Liste im Workflow ist
tatsächlich notwendig, nicht nur vorsorglich. Für zukünftige lokale
Verifikationen mit `composer:2` pur reicht das Image für `composer validate`,
aber **nicht** für `composer install` dieses Projekts.

### Requirement: Build-Smoke-Test über build.sh
- [x] `bash build.sh` erzeugt `dist/dog-mentality-test/` inkl. aller vier
  Kern-Dateien (`api/auth.php`, `frontend/index.html`, `wizard/index.php`,
  `vendor/autoload.php`) → Exit 0 — lokal reproduziert (Exit 0, alle vier
  Dateien vorhanden, 1114 Dateien gesamt) und am echten `build`-Job
  bestätigt (`success`, inkl. separatem „Kern-Dateien verifizieren“-Schritt)
- [x] Kern-Datei fehlt → Job/Skript endet mit Exit ≠ 0 — **für alle drei**
  in der Spec genannten Kern-Dateien einzeln reproduziert (nicht nur
  `api/auth.php`, wie in der Aufgabenstellung als bereits verifiziert
  angegeben):
  - `api/auth.php` entfernt → Exit 1, „✗ Kern-Datei fehlt: api/auth.php“
  - `frontend/index.html` entfernt → Exit 1, „✗ Kern-Datei fehlt:
    frontend/index.html“
  - `wizard/index.php` entfernt → Exit 1, „✗ Kern-Datei fehlt:
    wizard/index.php“
  Jeweils danach zurückgesetzt (`git status --short` bestätigt: keine
  bleibenden Änderungen). Zusätzlich die redundante externe Prüfung aus
  `ci.yml:56-60` (Gürtel + Hosenträger) isoliert als Shell-Snippet gegen
  einen synthetischen vollständigen/unvollständigen `dist/`-Baum getestet:
  vollständig → Exit 0, unvollständig (fehlendes `wizard/index.php`) →
  `::error::Kern-Datei fehlt: wizard/index.php`, Exit 1.
- [x] Build-Artefakt wird bereitgestellt — verifiziert über die GitHub
  Actions API am echten Lauf: Artefakt `dog-mentality-test`, `expired: false`,
  `size_in_bytes: 2809269` — tatsächlich herunterladbar, nicht nur behauptet.

### Requirement: build.sh ist CI-tauglich gehärtet
- [x] Keine wörtlichen `\033[`-Zeichenfolgen in der Ausgabe — `grep -c
  '\\033\['` auf die reale `bash build.sh`-Ausgabe liefert `0`; visuell zeigt
  das Terminal echte Farben (ESC-Bytes wurden korrekt interpretiert)
- [x] `error()`-Funktion terminiert mit Exit-Code 1 — an drei unabhängigen
  Fehlerpfaden reproduziert (siehe oben, jeweils Exit 1)

## Ausführungs-Ergebnis

### `tests/ci-workflow-smoke.sh`

```
== Requirement: PHP-Syntaxprüfung aller Projektdateien ==
  ✓ lint: alle Dateien syntaktisch korrekt -> Exit 0
  ✓ lint: Datei mit Syntaxfehler -> Exit != 0, Pfad in Log genannt
  ✓ lint: vendor/ und dist/ werden ausgeschlossen (kaputte Datei dort ignoriert)

== Requirement: .gitignore / composer.lock ==
  ✓ composer.lock ist NICHT (mehr) von .gitignore erfasst
  ✓ vendor/ ist weiterhin von .gitignore erfasst
  ✓ composer.lock ist als Datei im Git-Index versioniert

== Requirement: Build-Smoke-Test über build.sh ==
  ✓ build.sh: vollständiger Baum -> Exit 0, alle vier Kern-Dateien vorhanden
  ✓ build.sh: Ausgabe enthält KEINE wörtlichen \033[-Zeichenfolgen
  ✓ build.sh: fehlende Kern-Datei api/auth.php -> Exit != 0
  ✓ build.sh: fehlende Kern-Datei frontend/index.html -> Exit != 0
  ✓ build.sh: fehlende Kern-Datei wizard/index.php -> Exit != 0

== Requirement: CI-Workflow-Datei (statische Prüfung) ==
  ✓ ci.yml enthält exakt 'name: CI'
  ✓ ci.yml definiert Trigger push (alle Branches) UND pull_request
  ✓ ci.yml definiert die drei Jobs lint, composer, build
  ✓ ci.yml: build-Job hängt via needs: [composer] vom composer-Job ab
  ✓ ci.yml: build-Job lädt ein Artefakt hoch (actions/upload-artifact@v4)

== Requirement: composer.json Pflichtfelder ==
  ✓ composer.json enthält Feld "name"
  ✓ composer.json enthält Feld "description"
  ✓ composer.json enthält Feld "license"
  ✓ composer.json enthält Feld "type"
  ✓ composer.json: config.platform.php ist weiterhin "8.0"

==============================================================
Ergebnis: 21 bestanden, 0 fehlgeschlagen
==============================================================
```

Zweiter Lauf zur Idempotenz-Kontrolle: identisches Ergebnis (21/0),
`git status --short` zeigt danach keine Reste außer dem neu angelegten
`tests/`-Verzeichnis selbst.

### `composer validate --strict` (Docker, `composer:2`)

```
./composer.json is valid
EXIT=0
```

### `composer install --no-dev --optimize-autoloader` (Docker, `composer:2` + nachinstalliertem `ext-gd`)

```
Installing dependencies from lock file
Verifying lock file contents can be installed on current platform.
Package operations: 9 installs, 0 updates, 0 removals
...
Generating optimized autoload files
EXIT=0
LOCK UNCHANGED after install
```

### Echter GitHub-Actions-Lauf (`gh run view 33736777974`)

```
lint      -> success  (Set up job, checkout, setup-php, PHP-Syntaxprüfung, ...)
composer  -> success  (checkout, setup-php, Composer validieren, Abhängigkeiten installieren, ...)
build     -> success  (checkout, setup-php, rsync sicherstellen, Build ausführen,
                        Kern-Dateien verifizieren, upload-artifact@v4, ...)
```

Artefakt-Check (`gh api .../artifacts`):

```
{"name":"dog-mentality-test","expired":false,"size_in_bytes":2809269}
```

`gh run list` (alle Läufe dieses Repos, relevant gefiltert):

```
success  push  feature/add-ci-workflow  33736777974
success  push  feat/add-ci-workflow     33736696256
success  push  feat/add-ci-workflow     33736582643
```

Kein `event: pull_request`-Lauf vorhanden. `gh pr list --state all` zeigt
keinen PR für diesen Branch.

## Fehler (falls vorhanden)

Keine Fehlschläge. Alle 21 Assertions im Smoke-Skript grün, alle manuell
reproduzierten Szenarien verhalten sich spezifikationskonform, der reale
CI-Lauf ist grün.

Eine Lücke bleibt offen (siehe oben, Requirement „CI-Workflow bei Push und
Pull Request“): der `pull_request`-Trigger ist **konfiguriert und statisch
korrekt**, aber noch **nie live ausgelöst** worden, weil kein PR gegen `main`
existiert. Das ist kein Fehler des Diffs, sondern ein noch ausstehender
Verifikationsschritt außerhalb des Tester-Scopes (Öffnen eines echten PRs ist
eine Workflow-Entscheidung des Users/Architekten, kein Testartefakt). Empfehlung
für Schritt 14 (PR-Vorschlag) bzw. Schritt 11 (Architekt Modus B): nach
Eröffnung des PRs `gh run list --json event -R wlmost/web-dog-mentality-test`
prüfen, ob ein Lauf mit `"event":"pull_request"` erscheint und grün ist,
bevor der Change endgültig als vollständig verifiziert gilt.
