## 1. composer.json & composer.lock

- [x] 1.1 `composer.json` um Pflichtfelder erweitern
  - Agent: Developer
  - Dateien: `composer.json`
  - Abhängigkeiten: keine
  - Beschreibung: Felder `name` (`wlmost/web-dog-mentality-test`),
    `description`, `license` (`proprietary`), `type` (`project`) ergänzen.
    `require` und `config.platform.php` (`8.0`) unverändert lassen.
  - Akzeptanz:
    - [ ] `composer validate --strict` läuft lokal (PHP/Composer-Umgebung
      mit `platform.php=8.0`) mit Exit-Code 0 durch
    - [ ] `config.platform.php` ist weiterhin `"8.0"`

- [x] 1.2 `composer.lock` erzeugen und einchecken
  - Agent: Developer
  - Dateien: `composer.lock` (neu), `.gitignore`
  - Abhängigkeiten: 1.1
  - Beschreibung: In einer PHP-8.0-Umgebung `composer install` ausführen,
    `composer.lock` committen; in `.gitignore` die Zeile `composer.lock`
    (im Block `# PHP`, unter `vendor/`) entfernen. `vendor/` bleibt ignoriert.
  - Akzeptanz:
    - [ ] `composer.lock` ist versioniert
    - [ ] `.gitignore` enthält keinen `composer.lock`-Eintrag mehr
    - [ ] `git status` zeigt `vendor/` weiterhin als ignoriert

## 2. build.sh härten

- [x] 2.1 Terminal-Escape-Ausgaben CI-tauglich machen
  - Agent: Developer
  - Dateien: `build.sh`
  - Abhängigkeiten: keine
  - Beschreibung: In den Farb-Helfern `info/ok/warn/section/error`
    (`build.sh:14-18`) sowie den farbcodierten Abschluss-`echo`-Blöcken
    (`build.sh:207` und `build.sh:213`) die `echo "…\033[…m…"`-Aufrufe auf
    `printf '%b\n' "…"` umstellen (oder Farbe bei fehlendem TTY / gesetztem
    `NO_COLOR` weglassen). `set -euo pipefail` (`build.sh:7`) und
    `error() { … exit 1; }` bleiben erhalten.
  - Akzeptanz:
    - [ ] Ausgabe von `bash build.sh` enthält keine wörtliche Zeichenfolge
      `\033[`
    - [ ] `error()` beendet das Skript weiterhin mit Exit-Code 1

- [x] 2.2 Kern-Komponenten als harten Fehler behandeln
  - Agent: Developer
  - Dateien: `build.sh`
  - Abhängigkeiten: 2.1
  - Beschreibung: Sicherstellen, dass ein fehlendes `api/auth.php`,
    `frontend/index.html` oder `wizard/index.php` in `build.sh` zu
    `error ...` (Exit ≠ 0) führt statt nur zu `warn`. Optionale Dateien
    (Debug-/Test-Seiten) bleiben Warnungen.
  - Akzeptanz:
    - [ ] Entfernt man testweise `api/auth.php`, endet `build.sh` mit
      Exit-Code ≠ 0 (danach zurücksetzen)
    - [ ] Bei vollständigem Baum endet `build.sh` mit Exit-Code 0 und
      erzeugt `dist/dog-mentality-test/`

## 3. CI-Workflow

- [x] 3.1 `ci.yml` Grundgerüst + `lint`-Job
  - Agent: Developer
  - Dateien: `.github/workflows/ci.yml` (neu)
  - Abhängigkeiten: keine
  - Beschreibung: `name: CI`; Trigger `push` (`branches: ["**"]`) und
    `pull_request`. Job `lint` auf `ubuntu-latest`:
    `actions/checkout@v4`, `shivammathur/setup-php@v2` mit
    `php-version: '8.0'`, dann
    `find . -name '*.php' -not -path './vendor/*' -not -path './dist/*' -print0 | xargs -0 -r -n1 -P4 php -l`.
  - Akzeptanz:
    - [ ] Workflow trägt exakt `name: CI`
    - [ ] `lint`-Job schlägt fehl, wenn eine geprüfte `*.php`-Datei einen
      Syntaxfehler hat, und nennt den Pfad
    - [ ] `vendor/` und `dist/` werden nicht geprüft

- [x] 3.2 `composer`-Job
  - Agent: Developer
  - Dateien: `.github/workflows/ci.yml`
  - Abhängigkeiten: 1.1, 1.2, 3.1
  - Beschreibung: Job `composer` auf `ubuntu-latest`: `checkout@v4`,
    `setup-php@v2` (`php-version: '8.0'`,
    `extensions: mbstring, ctype, gd, zip, mysqli, curl, json`),
    `composer validate --strict`, dann
    `composer install --no-dev --optimize-autoloader`.
    Hinweis: `gd` und `zip` sind harte `require` von PhpSpreadsheet 1.29 und
    werden von `setup-php` **nicht** per Default aktiviert (siehe design.md
    D9). `mysqli` statt `pdo_mysql`, da die App durchgängig `mysqli` nutzt.
  - Akzeptanz:
    - [ ] `composer validate --strict` endet mit Exit-Code 0
    - [ ] `composer install --no-dev --optimize-autoloader` endet mit
      Exit-Code 0
    - [ ] Job nutzt PHP 8.0
    - [ ] `extensions:` enthält mindestens `gd` und `zip`; kein `pdo_mysql`

- [x] 3.3 `build`-Job mit Smoke-Test und Artefakt
  - Agent: Developer
  - Dateien: `.github/workflows/ci.yml`
  - Abhängigkeiten: 2.1, 2.2, 3.2
  - Beschreibung: Job `build` mit `needs: [composer]`: `checkout@v4`,
    `setup-php@v2` (PHP 8.0, gleiche Extensions), `rsync` sicherstellen
    (`sudo apt-get update && sudo apt-get install -y rsync`, falls nicht
    vorhanden), `bash build.sh`, danach Shell-Prüfung auf
    `dist/dog-mentality-test/{api/auth.php,frontend/index.html,wizard/index.php,vendor/autoload.php}`
    (fehlt eine → `exit 1`), dann `actions/upload-artifact@v4` mit
    `path: dist/dog-mentality-test`, `retention-days: 3`.
  - Akzeptanz:
    - [ ] Bei vollständigem Repo endet der Job mit Exit-Code 0
    - [ ] Fehlt eine der vier Kern-Dateien, schlägt der Job fehl
    - [ ] Artefakt `dist/dog-mentality-test` ist nach erfolgreichem Lauf
      herunterladbar

- [x] 3.4 CI end-to-end grün
  - Agent: Developer
  - Dateien: — (Verifikation)
  - Abhängigkeiten: 3.1, 3.2, 3.3
  - Beschreibung: Feature-Branch pushen, CI-Lauf beobachten, verbleibende
    Befunde (z. B. weitere `composer validate --strict`-Warnungen,
    fehlende PHP-Extensions im realen Platform-Check von
    `composer install` — `extensions:`-Liste dann nachziehen —,
    `build.sh`-Abbrüche im CI, Lint-Fehler in `api/debug-*.php`) beheben,
    bis alle drei Jobs grün sind.
  - Akzeptanz:
    - [ ] Alle drei Jobs (`lint`, `composer`, `build`) sind im
      GitHub-Actions-Lauf grün
    - [ ] `composer install --no-dev` läuft ohne Platform-Check-Fehler
      (finale `extensions:`-Liste im Workflow dokumentiert)
    - [ ] Der Workflow erscheint unter dem Namen `CI` in der Actions-Übersicht

## 4. Repo-Hygiene (User-Entscheidung Spec-Gate 2026-08-30)

- [x] 4.1 `api-contract.md` aus dem Repo-Root entfernen
  - Agent: Developer
  - Dateien: `api-contract.md` (löschen)
  - Abhängigkeiten: keine
  - Beschreibung: `api-contract.md` im Projekt-Root ist ein versehentlich
    hier abgelegtes Artefakt eines anderen Projekts
    (`add-testresult-upload-api`, dog-school-app-Sync-API) und gehört nicht
    zu `web-dog-mentality-test`. Ersatzlos löschen (`git rm api-contract.md`).
  - Akzeptanz:
    - [ ] `api-contract.md` existiert nicht mehr im Repo
    - [ ] `git status` zeigt die Löschung als staged

- [x] 4.2 `openspec/` und `.claude/` versionieren
  - Agent: Developer
  - Dateien: `.gitignore`, `openspec/**`, `.claude/**`
  - Abhängigkeiten: keine
  - Beschreibung: Laut User-Entscheidung werden das von `openspec init`
    erzeugte `openspec/`-Gerüst (inkl. `openspec/config.yaml`) und das
    `.claude/`-Verzeichnis (10 Skills + 10 Commands) mit dem ersten Change
    committet. **Kein** `.gitignore`-Eintrag für `.claude/` oder `openspec/`
    hinzufügen. Sicherstellen, dass keine sensiblen Laufzeitdaten in
    `.claude/` liegen (nur Skill-/Command-Markdown).
  - Akzeptanz:
    - [ ] `git status` listet `openspec/` und `.claude/` als hinzuzufügen
    - [ ] `.gitignore` enthält keinen `.claude`- oder `openspec`-Eintrag
    - [ ] `.claude/` enthält ausschließlich Skill-/Command-Definitionen
