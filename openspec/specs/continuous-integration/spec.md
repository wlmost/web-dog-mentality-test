# continuous-integration Specification

## Purpose
TBD - created by archiving change add-ci-workflow. Update Purpose after archive.
## Requirements
### Requirement: CI-Workflow bei Push und Pull Request

Das Repository SHALL einen GitHub-Actions-Workflow in
`.github/workflows/ci.yml` bereitstellen, der bei jedem `push` auf einen
beliebigen Branch und bei jedem `pull_request` ausgeführt wird. Der Workflow
SHALL den Namen `CI` tragen, damit nachgelagerte Workflows ihn über
`on.workflow_run.workflows: ["CI"]` referenzieren können.

#### Scenario: Push auf einen Feature-Branch

- **WHEN** ein Commit auf einen beliebigen Branch gepusht wird
- **THEN** startet der Workflow `CI` automatisch
- **AND** er führt die Jobs `lint`, `composer` und `build` aus

#### Scenario: Pull Request gegen main

- **WHEN** ein Pull Request geöffnet oder aktualisiert wird
- **THEN** startet der Workflow `CI` automatisch für den PR-Head-Commit

#### Scenario: Workflow-Name ist stabil

- **WHEN** `ci.yml` im Repository liegt
- **THEN** enthält es das Schlüsselpaar `name: CI`
- **AND** eine Änderung dieses Namens gilt als Breaking Change für den
  Produktions-Deploy-Workflow

### Requirement: PHP-Syntaxprüfung aller Projektdateien

Der `lint`-Job SHALL `php -l` (PHP 8.0) auf alle `*.php`-Dateien im
Repository anwenden — inklusive Projekt-Root, `api/`, `wizard/` und
`scripts/` — und die Verzeichnisse `vendor/` und `dist/` ausschließen. Der
Job SHALL fehlschlagen, sobald mindestens eine Datei einen Syntaxfehler
enthält.

#### Scenario: Alle Dateien syntaktisch korrekt

- **WHEN** keine `*.php`-Datei außerhalb von `vendor/` und `dist/` einen
  Syntaxfehler hat
- **THEN** endet der `lint`-Job mit Exit-Code 0

#### Scenario: Eine Datei mit Syntaxfehler

- **WHEN** eine beliebige geprüfte `*.php`-Datei einen Parse-Fehler enthält
- **THEN** endet der `lint`-Job mit Exit-Code ungleich 0
- **AND** die Log-Ausgabe nennt den betroffenen Dateipfad

### Requirement: Composer-Konfiguration und reproduzierbarer Install

Der `composer`-Job SHALL `composer validate --strict` ausführen und
anschließend `composer install --no-dev --optimize-autoloader`. Beide
Schritte SHALL fehlerfrei durchlaufen. Das Repository SHALL eine
eingecheckte `composer.lock` enthalten, und `composer.lock` SHALL nicht
mehr in `.gitignore` gelistet sein.

#### Scenario: composer.json ist valide im strict-Modus

- **WHEN** `composer validate --strict` im `composer`-Job läuft
- **THEN** endet der Befehl mit Exit-Code 0 (keine Warnungen)

#### Scenario: composer.json enthält die Pflichtfelder

- **WHEN** `composer.json` geprüft wird
- **THEN** sind die Felder `name`, `description`, `license` und `type` gesetzt
- **AND** `config.platform.php` bleibt `"8.0"`

#### Scenario: Install ist reproduzierbar

- **WHEN** `composer install --no-dev --optimize-autoloader` mit der
  eingechecketen `composer.lock` läuft
- **THEN** endet der Befehl mit Exit-Code 0
- **AND** `vendor/autoload.php` existiert danach

#### Scenario: composer.lock ist versioniert

- **WHEN** das Repository ausgecheckt wird
- **THEN** ist `composer.lock` vorhanden
- **AND** `.gitignore` enthält keinen Eintrag `composer.lock`

### Requirement: Build-Smoke-Test über build.sh

Der `build`-Job SHALL `bash build.sh` ausführen und danach verifizieren,
dass das Verzeichnis `dist/dog-mentality-test/` erzeugt wurde und mindestens
die Dateien `api/auth.php`, `frontend/index.html`, `wizard/index.php` und
`vendor/autoload.php` (jeweils relativ zu `dist/dog-mentality-test/`)
enthält. Der Job SHALL das erzeugte Verzeichnis als Build-Artefakt
hochladen.

#### Scenario: build.sh erzeugt ein vollständiges Paket

- **WHEN** `bash build.sh` im `build`-Job erfolgreich durchläuft
- **THEN** existiert `dist/dog-mentality-test/`
- **AND** die vier Kern-Dateien sind vorhanden
- **AND** der Job endet mit Exit-Code 0

#### Scenario: Kern-Datei fehlt im Paket

- **WHEN** nach `bash build.sh` eine der vier Kern-Dateien fehlt
- **THEN** endet der `build`-Job mit Exit-Code ungleich 0

#### Scenario: build.sh bricht mit Fehler ab

- **WHEN** `build.sh` einen Fehlerpfad erreicht (z. B. fehlende Quelldatei
  für eine Kern-Komponente)
- **THEN** endet `build.sh` mit Exit-Code ungleich 0
- **AND** der `build`-Job schlägt fehl

#### Scenario: Build-Artefakt wird bereitgestellt

- **WHEN** der `build`-Job erfolgreich abschließt
- **THEN** ist das Verzeichnis `dist/dog-mentality-test/` als
  herunterladbares Actions-Artefakt verfügbar

### Requirement: build.sh ist CI-tauglich gehärtet

`build.sh` SHALL im nicht-interaktiven CI-Kontext ohne wörtlich ausgegebene
Terminal-Escape-Sequenzen laufen und auf jedem Fehlerpfad einen Exit-Code
ungleich 0 liefern. Die bestehende `set -euo pipefail`-Zeile SHALL erhalten
bleiben.

#### Scenario: Keine wörtlichen Escape-Sequenzen

- **WHEN** `build.sh` im CI ausgeführt wird
- **THEN** enthält die Ausgabe keine wörtlichen `\033[`-Zeichenfolgen

#### Scenario: Fehler liefert Exit-Code

- **WHEN** die interne `error`-Funktion von `build.sh` aufgerufen wird
- **THEN** terminiert das Skript mit Exit-Code 1

