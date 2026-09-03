## Why

Nach grünem CI (`add-ci-workflow`) und mit einem eigenständigen
Migrations-Runner (`add-db-migration-runner`) fehlt der letzte Baustein: ein
**zweistufiger, freigabe-gesteuerter Produktions-Deploy**. Heute wird manuell
per FTP deployt (`FTP_DEPLOYMENT.md`, `prepare-deployment.ps1`) — fehleranfällig
und ohne definierten Freigabepunkt.

**Zielplattform:** Die Produktion läuft bei **alfahosting** (SSH-fähiger
Managed-Tarif). Der User hat am 2026-08-30 (Spec-Gate) bestätigt, dass auf
dem Server **SSH, php-CLI mit `ext-mysqli` und `rsync`** verfügbar sind. Die
SSH/rsync-Architektur ist damit die bestätigte Grundlage; FTP bleibt nur noch
dokumentierter Fallback.

Ziel: Deploy startet **automatisch nach grünem CI auf `main`**, pausiert aber
bis zur **manuellen Freigabe des Users** (GitHub Environment `production` mit
Required Reviewer) und rollt dann per SSH + rsync den von `build.sh` erzeugten,
kuratierten Stand aus, führt Migrationen aus und blendet währenddessen eine
Wartungsseite ein. Struktur (2-stufig via `workflow_run`, Environment-Approval,
`concurrency`, Checkout des exakten CI-Commits, `workflow_dispatch`-Fallback,
Summary, SSH-Key-Cleanup) folgt
`../dog-school-app/.github/workflows/deploy.yml`; die konkreten Build-/Deploy-
Schritte sind projektspezifisch (Vanilla PHP statt Laravel, `build.sh` statt
`artisan`).

## What Changes

- **Neu:** `.github/workflows/deploy.yml`:
  - Trigger: `workflow_run` (`workflows: ["CI"]`, `types: [completed]`,
    `branches: [main]`) **plus** `workflow_dispatch` mit Input `ref`
    (Default `main`).
  - `concurrency: { group: deploy-production, cancel-in-progress: false }`.
  - `job.if`: nur fortfahren, wenn `workflow_dispatch` **oder**
    `github.event.workflow_run.conclusion == 'success'`.
  - `environment: production` → Approval-Gate (Required Reviewer = User).
  - `env.DEPLOY_PORT: ${{ vars.DEPLOY_PORT || 22 }}`;
    `env.DEPLOY_PHP_BIN: ${{ vars.DEPLOY_PHP_BIN || 'php' }}`.
  - Schritte: Checkout des exakten Commits
    (`workflow_run.head_sha` bzw. `inputs.ref || github.ref`) →
    `shivammathur/setup-php@v2` (PHP 8.0) → `rsync` sicherstellen →
    `bash build.sh` → Build-Output-Verifikation (fünf Kern-Dateien inkl.
    `scripts/migrate.php`) → Kopplungs-Guard (`ci.yml` heißt `CI`, alle
    Schutz-Excludes vorhanden) → SSH-Key einrichten + `ssh-keyscan` →
    **Wartungsmodus an** (best-effort)
    → **rsync** `-az --delete` mit Schutz-Excludes → **Migrationen**
    (`ssh … "$DEPLOY_PHP_BIN scripts/migrate.php"`) → **Wartungsmodus aus**
    (`if: always()`) → SSH-Key entfernen (`if: always()`) →
    **Deployment-Summary** nach `$GITHUB_STEP_SUMMARY` (`if: always()`).
  - rsync-Quelle: `dist/dog-mentality-test/`. Excludes: `.maintenance`,
    `api/config.local.php`, `uploads/`, `logs/`, `.git`, `wizard/`, `*.zip`,
    `dist/`, `php.ini`, `.user.ini`, `.htpasswd`, `.well-known/`.
    `wizard/` wird **vollständig** vom Transfer ausgeschlossen (siehe
    design.md D2b — verhindert, dass der Web-Installer nach jedem Deploy
    ungeschützt erreichbar wird). `php.ini` / `.user.ini` / `.htpasswd` /
    `.well-known/` schützen serverseitig angelegte Hoster-/ACME-Dateien vor
    `--delete`.
- **Neu:** `maintenance.html` — schlanke statische Wartungsseite (wird von
  `build.sh` nach `dist/dog-mentality-test/maintenance.html` kopiert).
- **Neu:** `CI_CD.md` — Doku: benötigte Environment-Secrets/Variables,
  Einrichtung des Required Reviewers, Ablauf Freigabe/Rollback,
  Erstinbetriebnahme (1. Deploy rot → Wizard einmalig → 2. Deploy grün),
  einmaliges manuelles Hochladen/Löschen von `wizard/`, manuelles Entfernen
  von `.maintenance` im Notfall.
- **Änderung:** `FTP_DEPLOYMENT.md` — Hinweis ergänzen, dass die Produktion
  bei alfahosting mit SSH läuft und der CI/CD-Deploy der bevorzugte Weg ist;
  die FTP-Anleitung bleibt als Fallback erhalten.
- **Änderung:** `index.php` — am Anfang: wenn `.maintenance` (im gleichen
  Verzeichnis) existiert, `HTTP 503` + `Retry-After` senden und
  `maintenance.html` ausgeben, dann `exit`.
- **Änderung:** `api/config.php` — ganz am Anfang (vor jeder DB-Nutzung):
  wenn `../.maintenance` existiert, `HTTP 503` + JSON
  `{"error":"Wartungsarbeiten …"}` senden und `exit`.
- **Änderung:** `build.sh` — `maintenance.html` in die Root-Datei-Kopie
  aufnehmen.
- **Änderung:** `.gitignore` — `.maintenance` ergänzen (Laufzeit-Flag).

**Abhängigkeiten:** setzt `add-ci-workflow` (Workflow-Name `CI`, gehärtetes
`build.sh`, eingecheckte `composer.lock`) und `add-db-migration-runner`
(`scripts/migrate.php` im Deploy-Paket) voraus. Empfohlene Merge-Reihenfolge
A → B → C.

## Capabilities

### New Capabilities

- `production-deployment`: Freigabe-gesteuerter, zweistufiger Auslieferungs-
  Workflow, der nach grünem CI auf `main` (oder manuell) nach Approval den
  kuratierten Build per SSH/rsync auf die Produktionsumgebung überträgt,
  Datenbankmigrationen ausführt und die Anwendung während der Auslieferung in
  einen Wartungsmodus versetzt.

### Modified Capabilities

_Keine — `openspec/specs/` ist leer._

## Impact

- **Neu:** `.github/workflows/deploy.yml`, `maintenance.html`, `CI_CD.md`
- **Geändert:** `index.php`, `api/config.php`, `build.sh`, `.gitignore`,
  `FTP_DEPLOYMENT.md`
- **Zielplattform:** alfahosting, SSH-fähiger Managed-Tarif (vom User am
  2026-08-30 bestätigt)
- **Infra außerhalb des Repos (User):** GitHub Environment `production` mit
  Required Reviewer = User; Secrets `DEPLOY_SSH_KEY`, `DEPLOY_HOST`,
  `DEPLOY_USER`, `DEPLOY_PATH`; optional `vars.DEPLOY_PORT` (Default 22),
  `vars.DEPLOY_PHP_BIN` (Default `php`); öffentlicher SSH-Key im
  `authorized_keys` des Deploy-Users auf dem Server.
- **Risiko:** `rsync --delete` auf einem Live-Docroot. Schutz-Excludes und
  Wartungsmodus mindern das; die rsync-Quelle ist der kuratierte
  `dist/`-Stand, nicht das Repo.
- **Nicht im Scope:** Blue-Green/Atomic-Swap-Deploy, automatischer
  DB-Backup-Schritt vor Migration, Rollback-Automatik. `wizard/` wird per
  Deploy **nicht** übertragen (nicht per Deploy gelöscht, sondern gar nicht
  erst angefasst — siehe design.md D2b).
