# CI/CD & Produktions-Deployment

Diese Datei beschreibt den automatisierten Produktions-Deploy über
`.github/workflows/deploy.yml`: Zielplattform, benötigte Secrets/Variablen,
Einrichtung des Freigabe-Gates, Erstinbetriebnahme, Regelbetrieb, Rollback
und Notfall-Vorgehen.

## a) Zielplattform

Die Produktion läuft bei **alfahosting** auf einem SSH-fähigen
Managed-Tarif. Der User hat am 2026-08-30 (Spec-Gate) bestätigt, dass auf
dem Server Folgendes verfügbar ist:

- SSH-Zugang
- PHP-CLI mit `ext-mysqli`
- `rsync`

Diese drei Voraussetzungen sind die Grundlage der SSH/rsync-Architektur des
Deploy-Workflows. Composer wird auf dem Server **nicht** benötigt —
`vendor/` wird im Workflow gebaut und mit ausgeliefert.

## b) Benötigte Environment-Secrets

Im GitHub Environment `production` müssen folgende **Secrets** hinterlegt
werden (Namen exakt wie in `.github/workflows/deploy.yml` referenziert):

| Secret | Bedeutung |
| --- | --- |
| `DEPLOY_SSH_KEY` | Privater SSH-Schlüssel (PEM), der Zugriff auf den Deploy-User hat |
| `DEPLOY_HOST` | Hostname bzw. IP des alfahosting-Servers |
| `DEPLOY_USER` | SSH-/SFTP-Benutzername auf dem Server |
| `DEPLOY_PATH` | Absoluter Pfad zum Docroot auf dem Server (z. B. `/kunden/homepages/.../dog-mentality-test`) |

## c) Variablen

Im GitHub Environment `production` können zusätzlich folgende **Variables**
(Repository- oder Environment-Variables, `vars.*`) gesetzt werden — beide
sind optional und haben Defaults im Workflow:

| Variable | Default | Zweck |
| --- | --- | --- |
| `DEPLOY_PORT` | `22` | SSH-Port, falls alfahosting einen abweichenden Port verwendet |
| `DEPLOY_PHP_BIN` | `php` | Name/Pfad des PHP-CLI-Binaries auf dem Server, falls `php` nicht direkt im `PATH` liegt (z. B. `php8.0`) |

## d) GitHub Environment `production` + Required Reviewer einrichten

1. Im Repository: **Settings → Environments → New environment**
2. Name: `production`
3. Unter **Deployment protection rules** → **Required reviewers** aktivieren
   und den User (Projekt-Owner) als Reviewer eintragen
4. Unter **Environment secrets** die vier Secrets aus Abschnitt (b) anlegen
5. Unter **Environment variables** optional die zwei Variablen aus
   Abschnitt (c) anlegen

Ergebnis: Jeder Deploy-Lauf (ob durch grünes CI oder per
`workflow_dispatch` ausgelöst) pausiert im Status „Waiting", bis der
Required Reviewer im Actions-UI auf „Approve" klickt.

## e) Öffentlichen SSH-Key hinterlegen

Der zu `DEPLOY_SSH_KEY` gehörende **öffentliche** Schlüssel muss auf dem
Server in der `~/.ssh/authorized_keys`-Datei des in `DEPLOY_USER`
hinterlegten Benutzers eingetragen werden (z. B. per bestehendem SSH-Zugang
oder über das alfahosting-Kundenmenü). Ohne diesen Eintrag schlägt der
Schritt „SSH einrichten" bzw. der erste `ssh`/`rsync`-Aufruf im Workflow
fehl.

Empfehlung: einen dedizierten Schlüssel ausschließlich für den
CI/CD-Deploy erzeugen (nicht den persönlichen SSH-Key des Users
wiederverwenden).

## f) Erstinbetriebnahme

Die Erstinbetriebnahme läuft in genau drei Schritten ab:

1. **Erster Deploy ist rot.** Der Workflow überträgt die Dateien per rsync
   erfolgreich, aber der anschließende Schritt „Migrationen ausführen"
   (`scripts/migrate.php`) bricht mangels `api/config.local.php` mit
   Exit-Code 1 ab — **das ist erwartet und vom User akzeptiert.** Es gibt
   keine Sonderlogik im Workflow, die diesen Fall abfängt.
2. **`wizard/` einmalig manuell hochladen.** Der Betreiber lädt das
   `wizard/`-Verzeichnis (liegt im Repository bzw. im dist-Paket) per
   SFTP/scp einmalig manuell nach `$DEPLOY_PATH/wizard/` hoch, ruft die
   Installation im Browser auf (`https://<domain>/wizard/`) — dabei wird
   `api/config.local.php` mit den DB-Zugangsdaten erzeugt — und **löscht
   `wizard/` danach wieder serverseitig** (z. B. per SFTP oder SSH
   `rm -rf`).
3. **Deploy erneut starten.** Z. B. über `workflow_dispatch` gegen `main`.
   Nach erneuter Freigabe (Approval) läuft der Deploy nun grün durch, weil
   `api/config.local.php` existiert und `scripts/migrate.php` die
   Migrationen anwenden kann.

Ab diesem Zeitpunkt ist der Runner `scripts/migrate.php` für alle
weiteren Migrationen zuständig; der Wizard wird nicht mehr benötigt.

## g) Regelbetrieb: „grünes CI → Approval → Deploy"

Im Alltag läuft der Deploy automatisiert, aber mit menschlichem
Freigabepunkt ab:

1. Ein Push/PR-Merge auf `main` löst den Workflow `CI` aus.
2. Schließt `CI` auf `main` erfolgreich ab, löst dies (`workflow_run`)
   automatisch `deploy.yml` aus.
3. Der Deploy-Job pausiert sofort im Status „Waiting", weil er das
   Environment `production` mit Required Reviewer nutzt (siehe d)).
4. Der Required Reviewer prüft im Actions-UI und klickt „Approve".
5. Erst danach laufen die eigentlichen Schritte: Build, Wartungsmodus an,
   rsync-Übertragung, Migrationen, Wartungsmodus aus, Summary.

Alternativ kann jederzeit manuell per `workflow_dispatch` (mit optionalem
`ref`-Input, Default `main`) ein Deploy gestartet werden — auch dieser
unterliegt demselben Approval-Gate.

## h) Rollback

Es gibt keine automatische Rollback-Funktion. Um einen fehlerhaften Deploy
rückgängig zu machen:

1. `deploy.yml` per `workflow_dispatch` erneut starten, dabei im
   `ref`-Input den Commit-SHA des letzten bekannten guten Stands angeben.
2. Nach Approval überträgt rsync den Dateistand dieses Commits und führt
   `scripts/migrate.php` erneut aus.

**Wichtig:** Die Datenbank wird dabei **nicht automatisch zurückgerollt**.
Datenbank-Änderungen (Migrationen) sind grundsätzlich vorwärtsgerichtet;
ein Rollback der Datenbank muss manuell erfolgen, z. B. durch Einspielen
eines vorherigen Backups. Vor jedem Deploy mit Migrationen sollte daher
nach Möglichkeit ein aktuelles DB-Backup vorliegen.

## i) Notfall: Wartungsmodus hängt fest

Der Workflow schaltet vor der Übertragung `$DEPLOY_PATH/.maintenance` an
und danach (mit `if: always()`) wieder aus. Bricht ein Job jedoch vorzeitig
ab (z. B. Runner-Absturz, Netzwerkfehler vor dem Cleanup-Step), kann die
Datei stehen bleiben und die Seite dauerhaft mit HTTP 503 antworten. In
diesem Fall per SSH manuell entfernen:

```bash
ssh "$DEPLOY_USER@$DEPLOY_HOST" "rm -f '$DEPLOY_PATH/.maintenance'"
```

bzw. lokal am Server ausgeführt:

```bash
rm -f $DEPLOY_PATH/.maintenance
```

## j) `wizard/` wird per Deploy nie übertragen

Der rsync-Schritt in `deploy.yml` schließt `wizard/` vollständig aus
(`--exclude='wizard/'`). Der reguläre Deploy fasst `wizard/` **nie** an —
weder Upload noch `--delete`.

**Begründung:** `wizard/`-Dateien im dist-Paket enthalten den Web-Installer
ohne aktives `.lock` (`WizardHelper::isLocked()` wertet eine fehlende oder
auf `inactive` gesetzte `.lock`-Datei als „nicht gesperrt"). Würde
`wizard/` bei jedem Deploy mit übertragen, wäre der Web-Installer nach
jedem regulären Deploy erneut ungeschützt unter `/wizard/` erreichbar —
mit dem Risiko einer erneuten Installation bzw. eines DB-Resets durch
Dritte. Deshalb wird `wizard/` ausschließlich einmalig zur
Erstinbetriebnahme manuell hochgeladen und danach wieder gelöscht (siehe
Abschnitt f)).

## Siehe auch

- `.github/workflows/deploy.yml` — der Deploy-Workflow selbst
- `.github/workflows/ci.yml` — der vorgelagerte CI-Workflow (`name: CI`)
- `FTP_DEPLOYMENT.md` — FTP-Fallback ohne CI/CD
- `openspec/changes/add-production-deploy-workflow/design.md` — Entscheidungen und Begründungen (D1–D9)
