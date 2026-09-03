# production-deployment Specification

## Purpose
TBD - created by archiving change add-production-deploy-workflow. Update Purpose after archive.
## Requirements
### Requirement: Zweistufiger Deploy-Trigger nach CI und manuell

Das Repository SHALL einen Workflow `.github/workflows/deploy.yml`
bereitstellen, der über `on.workflow_run` (`workflows: ["CI"]`,
`types: [completed]`, `branches: [main]`) **und** über `on.workflow_dispatch`
mit dem Input `ref` (Default `main`) ausgelöst wird. Der Deploy-Job SHALL nur
fortfahren, wenn das Ereignis `workflow_dispatch` ist **oder**
`github.event.workflow_run.conclusion == 'success'` gilt.

#### Scenario: CI auf main erfolgreich

- **WHEN** der Workflow `CI` auf `main` erfolgreich abschließt
- **THEN** wird `deploy.yml` ausgelöst
- **AND** der Deploy-Job fährt fort (vorbehaltlich Approval)

#### Scenario: CI auf main fehlgeschlagen

- **WHEN** der Workflow `CI` auf `main` mit `failure` abschließt
- **THEN** wird der Deploy-Job nicht ausgeführt

#### Scenario: CI auf einem Nicht-main-Branch

- **WHEN** `CI` auf einem Branch ungleich `main` abschließt
- **THEN** löst dies `deploy.yml` nicht aus

#### Scenario: Manueller Start

- **WHEN** ein Nutzer `deploy.yml` per `workflow_dispatch` startet
- **THEN** läuft der Deploy-Job unabhängig vom CI-Ergebnis (vorbehaltlich
  Approval)
- **AND** er verwendet den `ref`-Input (Default `main`)

### Requirement: Freigabe-Gate über GitHub Environment production

Der Deploy-Job SHALL das GitHub Environment `production` verwenden. Der
Deploy SHALL erst nach expliziter Freigabe eines Required Reviewers im
GitHub-UI ausgeführt werden; bis dahin SHALL der Lauf im Status „Waiting"
pausieren. Dies gilt für `workflow_run`- **und** `workflow_dispatch`-Läufe.

#### Scenario: Lauf pausiert bis Approval

- **WHEN** der Deploy-Job startet und ein Required Reviewer für `production`
  konfiguriert ist
- **THEN** pausiert der Lauf, ohne Dateien zu übertragen
- **AND** er wird erst nach „Approve" fortgesetzt

#### Scenario: Ablehnung

- **WHEN** der Reviewer die Freigabe ablehnt
- **THEN** wird kein Schritt ausgeführt, der den Produktionsserver verändert

### Requirement: Nebenläufigkeit ohne Abbruch laufender Deploys

`deploy.yml` SHALL `concurrency` mit `group: deploy-production` und
`cancel-in-progress: false` setzen.

#### Scenario: Zweiter Deploy während laufendem Deploy

- **WHEN** ein Deploy läuft und ein weiterer ausgelöst wird
- **THEN** wird der laufende Deploy nicht abgebrochen
- **AND** der neue Deploy wartet, bis der laufende beendet ist

### Requirement: Checkout des exakten CI-Commits

Der Deploy-Job SHALL bei `workflow_run` den Commit
`github.event.workflow_run.head_sha` auschecken und bei `workflow_dispatch`
den Wert aus `inputs.ref` (bzw. `github.ref` als Fallback).

#### Scenario: Deploy nach workflow_run

- **WHEN** der Deploy durch `workflow_run` ausgelöst wurde
- **THEN** checkt der Job exakt den Commit aus, der CI bestanden hat, auch
  wenn `main` inzwischen weitere Commits hat

#### Scenario: Deploy nach workflow_dispatch mit ref

- **WHEN** der Deploy per `workflow_dispatch` mit `ref` = einem Commit-SHA
  gestartet wurde
- **THEN** checkt der Job diesen SHA aus

### Requirement: Build des Auslieferungspakets im Workflow

Der Deploy-Job SHALL PHP 8.0 via `shivammathur/setup-php@v2` einrichten,
sicherstellen, dass `rsync` verfügbar ist, `bash build.sh` ausführen und
anschließend verifizieren, dass `dist/dog-mentality-test/` die Dateien
`api/auth.php`, `frontend/index.html`, `wizard/index.php`,
`vendor/autoload.php` und `scripts/migrate.php` enthält. Bei fehlender Datei
SHALL der Job mit Exit-Code ungleich 0 abbrechen, bevor irgendetwas
übertragen wird. Die Prüfung auf `wizard/index.php` validiert nur die
`build.sh`-Integrität; `wizard/` wird anschließend **nicht** übertragen
(rsync-Exclude, siehe folgende Anforderung).

#### Scenario: Vollständiges Paket

- **WHEN** `bash build.sh` im Deploy-Job durchläuft
- **THEN** sind alle geforderten Kern-Dateien in `dist/dog-mentality-test/`
  vorhanden
- **AND** der Job fährt mit der Übertragung fort

#### Scenario: Unvollständiges Paket

- **WHEN** nach `bash build.sh` eine geforderte Datei fehlt
- **THEN** bricht der Job ab, ohne SSH-Verbindung zum Server aufzunehmen

### Requirement: Übertragung per SSH und rsync mit Schutz-Excludes

Der Deploy-Job SHALL den privaten Schlüssel aus dem Secret `DEPLOY_SSH_KEY`
nach `~/.ssh/deploy_key` (Rechte 600) schreiben, den Host-Key des Servers per
`ssh-keyscan` in `~/.ssh/known_hosts` aufnehmen und mit
`StrictHostKeyChecking=yes` arbeiten. Die Übertragung SHALL per
`rsync -az --delete` vom Quellverzeichnis `dist/dog-mentality-test/` nach
`"$DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH/"` erfolgen, über den Port aus
`vars.DEPLOY_PORT` (Default 22). Der rsync-Aufruf SHALL mindestens folgende
`--exclude`-Muster enthalten: `.maintenance`, `api/config.local.php`,
`uploads/`, `logs/`, `.git`, `wizard/`, `*.zip`, `dist/`, `php.ini`,
`.user.ini`, `.htpasswd`, `.well-known/`.

#### Scenario: Alle Schutz-Excludes vorhanden

- **WHEN** `deploy.yml` im Repository liegt
- **THEN** enthält der rsync-Schritt `--exclude`-Einträge für
  `.maintenance`, `api/config.local.php`, `uploads/`, `logs/`, `.git`,
  `wizard/`, `*.zip`, `dist/`, `php.ini`, `.user.ini`, `.htpasswd` und
  `.well-known/`

#### Scenario: Serverseitige config.local.php bleibt erhalten

- **WHEN** der rsync-Schritt mit `--delete` läuft und auf dem Server
  `api/config.local.php` existiert
- **THEN** wird `api/config.local.php` weder überschrieben noch gelöscht

#### Scenario: Wizard wird nicht mit-deployt

- **WHEN** der Deploy-Job den rsync-Schritt ausführt
- **THEN** wird `wizard/` weder auf den Server übertragen noch dort per
  `--delete` verändert
- **AND** nach einem regulären Deploy ist `/wizard/` nicht ungeschützt
  erreichbar (kein `wizard/`-Verzeichnis ohne gültige `.lock` durch den
  Deploy entstanden)

#### Scenario: Serverseitige PHP-Overrides bleiben erhalten

- **WHEN** der rsync-Schritt mit `--delete` läuft und auf dem Server eine
  vom Betreiber angelegte `php.ini` bzw. `.user.ini` existiert
- **THEN** werden `php.ini` und `.user.ini` weder überschrieben noch gelöscht

#### Scenario: Hoster-/ACME-Pfade bleiben erhalten

- **WHEN** der rsync-Schritt mit `--delete` läuft und auf dem Server
  `.htpasswd` oder `.well-known/` existiert
- **THEN** werden diese Pfade nicht gelöscht

#### Scenario: Nutzer-Uploads bleiben erhalten

- **WHEN** der rsync-Schritt mit `--delete` läuft
- **THEN** werden `uploads/` und `logs/` auf dem Server nicht geleert

#### Scenario: Host-Key wird geprüft

- **WHEN** der Deploy eine SSH-Verbindung aufbaut
- **THEN** ist der Host-Key zuvor per `ssh-keyscan` hinterlegt
- **AND** `StrictHostKeyChecking` ist nicht deaktiviert

### Requirement: Datenbankmigrationen im Deploy

Nach der Übertragung SHALL der Deploy-Job die Migrationen per SSH mit
`<DEPLOY_PHP_BIN> scripts/migrate.php` im Verzeichnis `$DEPLOY_PATH`
ausführen, wobei `DEPLOY_PHP_BIN` aus `vars.DEPLOY_PHP_BIN` (Default `php`)
stammt. Ein Exit-Code ungleich 0 des Runners SHALL den Deploy-Job als
fehlgeschlagen markieren.

#### Scenario: Migrationen laufen nach rsync

- **WHEN** die Dateiübertragung erfolgreich war
- **THEN** ruft der Job `scripts/migrate.php` auf dem Server auf
- **AND** ausstehende Migrationen werden angewendet

#### Scenario: Migration schlägt fehl

- **WHEN** `scripts/migrate.php` mit Exit-Code 1 endet
- **THEN** ist der Deploy-Job rot
- **AND** der Schritt zum Deaktivieren des Wartungsmodus läuft trotzdem

#### Scenario: Konfigurierbarer PHP-Binary-Name

- **WHEN** `vars.DEPLOY_PHP_BIN` auf `php8.0` gesetzt ist
- **THEN** ruft der Job `php8.0 scripts/migrate.php` auf

### Requirement: Wartungsmodus während der Auslieferung

Der Deploy-Job SHALL vor dem rsync per SSH die Datei
`$DEPLOY_PATH/.maintenance` anlegen (best-effort; ein Fehlschlag beim ersten
Deploy SHALL den Job nicht abbrechen, nur eine Warnung erzeugen) und SHALL in
einem Schritt mit `if: always()` nach den Migrationen `$DEPLOY_PATH/.maintenance`
wieder entfernen (best-effort mit Warnung bei Fehlschlag). Die Anwendung
SHALL bei vorhandener `.maintenance`-Datei mit HTTP 503 und
`Retry-After`-Header antworten: `index.php` gibt `maintenance.html` aus,
`api/config.php` gibt eine JSON-Fehlermeldung aus und bricht vor jeder
Datenbanknutzung ab. `.maintenance` SHALL in `.gitignore` stehen und in den
rsync-Excludes enthalten sein.

#### Scenario: Wartungsseite während Deploy

- **WHEN** `.maintenance` im Projekt-Root des Servers existiert und ein
  Besucher `index.php` aufruft
- **THEN** antwortet der Server mit HTTP 503
- **AND** sendet einen `Retry-After`-Header
- **AND** gibt den Inhalt von `maintenance.html` aus

#### Scenario: API während Wartung

- **WHEN** `.maintenance` existiert und ein Client einen `api/*.php`-Endpunkt
  aufruft (der `api/config.php` einbindet)
- **THEN** antwortet der Server mit HTTP 503 und einer JSON-Fehlermeldung
- **AND** es wird keine Datenbankverbindung aufgebaut

#### Scenario: Normalbetrieb nach Deploy

- **WHEN** der Deploy abgeschlossen ist und `.maintenance` entfernt wurde
- **THEN** antworten `index.php` und die API wieder normal

#### Scenario: Wartungsflag wird nicht mit-deployt

- **WHEN** der rsync-Schritt läuft
- **THEN** überträgt er `.maintenance` nicht und entfernt es nicht per
  `--delete`

#### Scenario: Maintenance-Aus läuft auch bei vorherigem Fehler

- **WHEN** ein Schritt vor „Maintenance aus" fehlschlägt
- **THEN** wird der Schritt zum Entfernen von `.maintenance` dennoch
  ausgeführt (`if: always()`)

### Requirement: SSH-Key-Cleanup und Deployment-Summary

Der Deploy-Job SHALL in einem Schritt mit `if: always()` `~/.ssh/deploy_key`
entfernen und in einem weiteren Schritt mit `if: always()` eine
Zusammenfassung nach `$GITHUB_STEP_SUMMARY` schreiben, die mindestens
Auslöser (`github.event_name`), deployten Commit, Actor, UTC-Zeitpunkt und
das Migrations-Ergebnis enthält.

#### Scenario: Key wird immer entfernt

- **WHEN** der Deploy-Job endet (erfolgreich oder mit Fehler)
- **THEN** existiert `~/.ssh/deploy_key` auf dem Runner nicht mehr

#### Scenario: Summary wird immer geschrieben

- **WHEN** der Deploy-Job endet
- **THEN** enthält die Run-Summary Auslöser, Commit-SHA, Actor, UTC-Zeit und
  Migrations-Ergebnis

### Requirement: Kopplungs-Guard und Dokumentation

`deploy.yml` SHALL einen Schritt enthalten, der prüft, dass
`.github/workflows/ci.yml` `name: CI` enthält und dass der rsync-Schritt in
`deploy.yml` **alle** geforderten Schutz-Excludes (`.maintenance`,
`api/config.local.php`, `uploads/`, `logs/`, `.git`, `wizard/`, `*.zip`,
`dist/`, `php.ini`, `.user.ini`, `.htpasswd`, `.well-known/`) enthält; bei
Verletzung SHALL der Schritt fehlschlagen. Das Repository SHALL eine Datei
`CI_CD.md` enthalten, die die Zielplattform (alfahosting, SSH-Managed-Tarif),
die benötigten Environment-Secrets (`DEPLOY_SSH_KEY`, `DEPLOY_HOST`,
`DEPLOY_USER`, `DEPLOY_PATH`), Variablen (`DEPLOY_PORT`, `DEPLOY_PHP_BIN`),
die Einrichtung des Required Reviewers, den Erstinbetriebnahme-Ablauf
(1. Deploy rot → `wizard/` einmalig hochladen + Installation + `wizard/`
löschen → 2. Deploy grün), das Rollback-Vorgehen und das manuelle Entfernen
von `.maintenance` beschreibt. `FTP_DEPLOYMENT.md` SHALL einen Hinweis
enthalten, dass die Produktion SSH bietet und CI/CD der bevorzugte
Deploy-Weg ist (FTP als Fallback).

#### Scenario: Guard erkennt Namens-Drift

- **WHEN** `ci.yml` nicht mehr `name: CI` enthält
- **THEN** schlägt der Guard-Schritt in `deploy.yml` fehl

#### Scenario: Guard erkennt fehlendes Exclude

- **WHEN** ein Schutz-Exclude aus dem rsync-Schritt entfernt wird
- **THEN** schlägt der Guard-Schritt fehl

#### Scenario: Guard erkennt fehlendes wizard/-Exclude

- **WHEN** `--exclude='wizard/'` aus dem rsync-Schritt fehlt
- **THEN** schlägt der Guard-Schritt fehl

#### Scenario: CI_CD.md vorhanden

- **WHEN** das Repository ausgecheckt wird
- **THEN** existiert `CI_CD.md` mit den geforderten Abschnitten (Zielplattform,
  Secrets, Variablen, Required Reviewer, Erstinbetriebnahme, Rollback,
  `.maintenance`-Notfall)

#### Scenario: FTP_DEPLOYMENT.md-Hinweis vorhanden

- **WHEN** `FTP_DEPLOYMENT.md` gelesen wird
- **THEN** enthält es einen Hinweis, dass die Produktion (alfahosting) SSH
  bietet und der CI/CD-Deploy bevorzugt ist, FTP nur Fallback

