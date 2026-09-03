## Why

Das Projekt hat aktuell **keine** automatisierte Qualitätssicherung: kein
`.github/workflows/`, keine Test-Infrastruktur, und `composer.lock` ist
`.gitignore`-t (Zeile `composer.lock` unter `# PHP`), sodass Builds nicht
reproduzierbar sind. Jede Änderung wird ungeprüft nach `main` gemergt.
Bevor ein automatischer Produktions-Deploy (Folge-Change
`add-production-deploy-workflow`) sinnvoll ist, braucht es ein grünes,
verlässliches CI-Signal auf `main`.

Dieser Change baut den **ersten, bewusst leichtgewichtigen** CI-Workflow
(Syntax-Lint + Composer-Prüfung + Build-Smoke-Test). Eine echte PHPUnit-Suite
ist ausdrücklich einem späteren Change vorbehalten.

## What Changes

- **Neu:** `.github/workflows/ci.yml` mit dem Workflow-Namen `CI`
  (Trigger: `push` auf alle Branches + `pull_request`). Jobs:
  - `lint`: `php -l` rekursiv über alle `*.php` (Projekt-Root, `api/`,
    `wizard/`, `scripts/`), ausgenommen `vendor/` und `dist/`.
  - `composer`: `composer validate --strict` und
    `composer install --no-dev --optimize-autoloader` (muss fehlerfrei laufen).
  - `build`: `bash build.sh` ausführen und verifizieren, dass
    `dist/dog-mentality-test/` erzeugt wurde und die Kern-Dateien
    `api/auth.php`, `frontend/index.html`, `wizard/index.php`,
    `vendor/autoload.php` enthält; Ergebnis als Build-Artefakt hochladen.
- **Neu (eingecheckt):** `composer.lock` — erzeugt via `composer install`,
  ab jetzt versioniert für reproduzierbare Builds.
- **Änderung:** `.gitignore` — Zeile `composer.lock` entfernen.
- **Änderung:** `composer.json` — Pflichtfelder `name`, `description`,
  `license`, `type` ergänzen, damit `composer validate --strict` grün ist;
  `config.platform.php` (`8.0`) bleibt unverändert.
- **Änderung:** `build.sh` — CI-Härtung: die farbcodierten `echo`-Aufrufe
  mit `\033`-Escapes auf `printf` umstellen (aktueller Code gibt die
  Escape-Sequenzen wörtlich aus, da `echo` ohne `-e` verwendet wird —
  Farb-Helfer `build.sh:14-18`, Abschluss-Blöcke `build.sh:207` /
  `build.sh:213`; `set -euo pipefail` steht in `build.sh:7`), und
  sicherstellen, dass jeder Fehlerpfad einen Exit-Code ≠ 0 liefert.
- **Löschung:** `api-contract.md` im Repo-Root — Fremd-Artefakt eines anderen
  Projekts, gehört nicht hierher (User-Entscheidung Spec-Gate 2026-08-30).
- **Versionierung:** Das von `openspec init` erzeugte `openspec/`-Gerüst und
  das `.claude/`-Verzeichnis werden mit diesem Change committet; **kein**
  `.gitignore`-Eintrag dafür (User-Entscheidung).
- **PHP-Version:** `8.0` (via `shivammathur/setup-php`), passend zu
  `composer.json` `config.platform.php` und README ("PHP 8.0+"). Extensions
  in `setup-php`: `mbstring, ctype, gd, zip, mysqli, curl, json` — `gd` und
  `zip` sind harte `require` von PhpSpreadsheet 1.29 und werden von
  `setup-php` nicht per Default aktiviert; `mysqli` statt `pdo_mysql`, da die
  App durchgängig `mysqli` nutzt.

Kein Frontend-Build (rein statische Assets, kein npm). Kein MySQL-Service im
CI (keine Tests greifen auf die DB zu). **Keine** PHPUnit-Suite in diesem
Change.

## Capabilities

### New Capabilities

- `continuous-integration`: Automatisierter Build- und Prüf-Workflow, der bei
  jedem Push und PR den PHP-Syntax, die Composer-Konfiguration und den
  `build.sh`-Paketbau verifiziert und ein benanntes CI-Signal (`CI`) für
  nachgelagerte Workflows bereitstellt.

### Modified Capabilities

_Keine — `openspec/specs/` ist leer._

## Impact

- **Neu:** `.github/workflows/ci.yml`, `composer.lock`
- **Geändert:** `.gitignore`, `composer.json`, `build.sh`
- **Gelöscht:** `api-contract.md`
- **Erstmals versioniert:** `openspec/**`, `.claude/**`
- **Infra außerhalb des Repos:** keine (CI benötigt keine Secrets)
- **Nachgelagert:** `add-production-deploy-workflow` konsumiert das
  CI-Signal `CI` via `workflow_run` und den gehärteten `build.sh`.
- **Merge-Reihenfolge:** `build.sh` und `.gitignore` werden auch von
  `add-db-migration-runner` angefasst — empfohlene Reihenfolge
  A → B → C, um Merge-Konflikte zu vermeiden.
