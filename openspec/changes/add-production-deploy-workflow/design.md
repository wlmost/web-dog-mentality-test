## Context

**Zielplattform:** alfahosting, SSH-fähiger Managed-Tarif. Der User hat am
2026-08-30 (Spec-Gate) bestätigt: **SSH, php-CLI mit `ext-mysqli` und
`rsync` sind serverseitig verfügbar.** Die SSH/rsync-Architektur ist damit
gesetzt. `FTP_DEPLOYMENT.md`/`QUICKSTART_FTP.md`/`prepare-deployment.ps1`
bleiben als dokumentierter Fallback erhalten. alfahosting nutzt auf diesen
Tarifen `.user.ini` für PHP-Overrides.

**Ist-Zustand** (verifiziert):

- Kein `.github/workflows/`. Deployment heute manuell per FTP
  (`FTP_DEPLOYMENT.md`, `QUICKSTART_FTP.md`, `prepare-deployment.ps1`).
- `build.sh` erzeugt `dist/dog-mentality-test/` mit fester Allowlist:
  ausgewählte `api/*.php` (ohne `debug-*`/`test-*`), `frontend/` (ohne
  `test-*.html`, ohne `*.md`), `wizard/` inkl. `.lock`=`inactive`
  (`build.sh:100-104`: `cp -r wizard "$DIST/wizard"` +
  `echo -n 'inactive' > "$DIST/wizard/.lock"`),
  DB-Schemas + `database/migrations/*.sql`, `uploads/avatars/.htaccess`,
  `logs/`, Root: `.htaccess`, `index.php`, `php.ini.example`, `composer.json`,
  danach `vendor/`. Schreibt `DEPLOY_CHECKLIST.txt`.
- `index.php` (**6 Zeilen**): reiner 302-Redirect auf `frontend/index.html`.
- `api/config.php`: `.maintenance`-Guard soll als erste Anweisung nach
  `declare(strict_types=1);` (Zeile 2) stehen — vor `loadEnv()` und weit vor
  dem eager-Connect `new mysqli(...)` in `api/config.php:93`. Wird von jedem
  `api/*.php` **und** von `wizard/update.php` per `require` eingebunden.
- Kein Wartungsmechanismus (kein `artisan down`-Äquivalent).
- `.gitignore` schützt `api/config.local.php`, `uploads/*`, `logs/`,
  `*.zip`; `wizard/.lock` wird bewusst im Repo gehalten
  (Placeholder `inactive`).
- `WizardHelper::isLocked()` (`wizard/WizardHelper.php:10-18`): fehlende
  `.lock`-Datei **oder** Inhalt `inactive` ⇒ „nicht gesperrt". D. h. ein
  serverseitiges `wizard/` **ohne** `.lock` (oder mit `inactive`) ist ein
  offener Web-Installer.
- `php.ini.example:5-11` und `DEPLOY_CHECKLIST.txt` Schritt 5 weisen den
  Betreiber an, serverseitig eine `php.ini` **bzw. `.user.ini`** anzulegen.

**Referenz** `../dog-school-app/.github/workflows/deploy.yml` (verifiziert)
liefert die zu übernehmende **Struktur**:

- `on.workflow_run` (`workflows: ["CI – Build & Test"]`, `types: [completed]`,
  `branches: [main]`) + `on.workflow_dispatch` mit `inputs.ref` (Default
  `main`).
- `concurrency: { group: deploy-production, cancel-in-progress: false }`.
- `job.if`:
  `github.event_name == 'workflow_dispatch' || github.event.workflow_run.conclusion == 'success'`.
- `environment: production` (Secrets `DEPLOY_SSH_KEY`, `DEPLOY_HOST`,
  `DEPLOY_USER`, `DEPLOY_PATH`, Var `DEPLOY_PORT`).
- Checkout mit
  `ref: ${{ github.event_name == 'workflow_run' && github.event.workflow_run.head_sha || (github.event.inputs.ref || github.ref) }}`.
- SSH: Key nach `~/.ssh/deploy_key` (chmod 600), `ssh-keyscan -p $PORT -H $HOST`.
- `rsync -az --delete` mit Schutz-Excludes.
- Maintenance an (best-effort, `|| echo ::warning`), … , Maintenance aus
  (`if: always()`), Key-Cleanup (`if: always()`), Summary nach
  `$GITHUB_STEP_SUMMARY` (`if: always()`).
- Zusätzlicher CI-Job `deploy-workflow-lint`, der per `grep` prüft, dass
  Schutz-Excludes/Steps in `deploy.yml` vorhanden sind.

Laravel-spezifische Schritte (`artisan down/up`, `migrate --force`,
`storage:link`, `config:cache`) werden **nicht** übernommen bzw. durch
projektspezifische Äquivalente ersetzt.

## Goals / Non-Goals

**Goals:**

- Deploy läuft automatisch nach grünem CI auf `main`, **wartet** aber auf
  User-Approval (GitHub Environment `production`).
- `workflow_dispatch` als manueller Fallback mit `ref`-Input.
- Exakt der Commit, der CI bestanden hat, wird deployt.
- Serverseitige Laufzeit-/Hoster-Pfade (`api/config.local.php`, `uploads/`,
  `logs/`, `wizard/`, `php.ini`, `.user.ini`, `.htpasswd`, `.well-known/`)
  werden durch `rsync`-Excludes vor Überschreiben/Löschen geschützt.
- Nach einem regulären Deploy ist `/wizard/` **nicht** ungeschützt
  erreichbar.
- Migrationen laufen automatisch über `scripts/migrate.php` (aus
  `add-db-migration-runner`).
- Kurzer Wartungsmodus während rsync + Migration.
- Nachvollziehbare Deployment-Summary; SSH-Key wird immer entfernt.

**Non-Goals:**

- Atomic/Blue-Green-Deploy, Symlink-Switch von Release-Verzeichnissen.
- Automatisches DB-Backup vor der Migration (empfohlen, als Open Question).
- Rollback-Automatik (Rollback = erneuter Deploy eines früheren Commits per
  `workflow_dispatch` + manuelle DB-Wiederherstellung).
- Serverseitiges Löschen von `wizard/` durch den Deploy (der Deploy fasst
  `wizard/` gar nicht an — D2b).
- Vollständige Ablösung der FTP-Doku (nur ein Hinweis wird ergänzt).

## Decisions

### D1: Freigabe-Gate ausschließlich über GitHub Environment `production`

`environment: production` am Deploy-Job. Der User wird dort als **Required
Reviewer** eingetragen (Settings → Environments → production → Deployment
protection rules). Effekt: Nach grünem CI auf `main` startet der Deploy-Run
automatisch, bleibt aber im Status „Waiting" bis der User im GitHub-UI
„Approve" klickt. Kein zusätzlicher `if:`-Freigabe-Hack nötig.
`workflow_dispatch` unterliegt demselben Environment-Gate.

_Alternative (verworfen):_ Freigabe nur per `workflow_dispatch` — kein
automatischer Start nach CI, mehr manuelle Schritte, kein „pausierter" Lauf
sichtbar.

### D2: Transport SSH + rsync, Quelle = `dist/dog-mentality-test/`

`bash build.sh` im Workflow erzeugt den kuratierten Stand; rsync überträgt
**nur** dieses Verzeichnis. Dadurch landen Repo-interne Dateien (`.git`,
Doku, `debug-*`/`test-*`) gar nicht erst im Transfer. `--delete` entfernt auf
dem Server verwaiste Dateien, die nicht mehr im Build sind.

rsync-Kommando:

```
rsync -az --delete \
  --exclude='.maintenance' \
  --exclude='api/config.local.php' \
  --exclude='uploads/' \
  --exclude='logs/' \
  --exclude='.git' \
  --exclude='wizard/' \
  --exclude='*.zip' \
  --exclude='dist/' \
  --exclude='php.ini' \
  --exclude='.user.ini' \
  --exclude='.htpasswd' \
  --exclude='.well-known/' \
  -e "ssh -i ~/.ssh/deploy_key -p $DEPLOY_PORT -o StrictHostKeyChecking=yes" \
  dist/dog-mentality-test/ \
  "$DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH/"
```

Begründung der Excludes:

- `api/config.local.php` — enthält DB-Zugangsdaten, wird vom Wizard auf dem
  Server erzeugt, ist **nicht** im Build. Exclude verhindert, dass `--delete`
  sie serverseitig löscht.
- `uploads/`, `logs/` — nutzergenerierte bzw. Laufzeit-Inhalte. Der Build
  enthält nur leere Platzhalter; ohne Exclude würde `--delete` echte Uploads
  entfernen.
- `wizard/` — **vollständig ausgeschlossen**, siehe D2b. Der Web-Installer
  wird nie per Deploy übertragen, damit er nach einem regulären Deploy nicht
  ungeschützt erreichbar ist.
- `php.ini`, `.user.ini` — der Betreiber legt diese laut `php.ini.example:5-11`
  und `DEPLOY_CHECKLIST.txt` Schritt 5 serverseitig an (alfahosting nutzt
  `.user.ini` für PHP-Overrides). Der Build enthält nur `php.ini.example`;
  ohne Exclude würde `--delete` die echte `php.ini`/`.user.ini` löschen.
- `.htpasswd` — mögliche serverseitige Basic-Auth-Datei (z. B. Schutz von
  `/wizard/` oder Staging); darf nicht gelöscht werden.
- `.well-known/` — ACME/Let's-Encrypt-Challenge- und `security.txt`-Pfade,
  die der Hoster bzw. Zertifikats-Renewal serverseitig im Docroot ablegt;
  `--delete` würde sie sonst entfernen.
- `.maintenance` — Laufzeit-Flag, das der Workflow selbst per SSH setzt/
  entfernt; darf von rsync nicht angefasst werden.
- `.git`, `*.zip`, `dist/` — sollten in der Quelle ohnehin nicht auftauchen;
  defensive Excludes.

### D2b: `wizard/` wird nicht per Deploy übertragen

**Problem:** `build.sh` packt das komplette `wizard/`-Verzeichnis ins
dist-Paket. Der frühere Entwurf excludierte nur `wizard/.lock` vom rsync —
damit landete `wizard/` nach **jedem** Deploy **ohne** `.lock` auf dem
Server. Wegen `WizardHelper::isLocked()` (`wizard/WizardHelper.php:10-18`:
fehlende Datei **oder** Inhalt `inactive` ⇒ „nicht gesperrt") wäre der
Web-Installer damit nach jedem Deploy ungeschützt unter `/wizard/`
erreichbar — inakzeptabel (Neuinstallation/DB-Drop durch Dritte möglich).

**Entscheidung:** `--exclude='wizard/'` im rsync-Deploy-Step. Der Wizard ist
ein reines Installations-Werkzeug (`DEPLOY_CHECKLIST.txt` Schritt 4:
„`/wizard/`-Verzeichnis nach der Installation löschen"). Konsequenzen:

- Regulärer Deploy fasst `wizard/` **nie** an — weder Upload noch `--delete`.
  Ein vom Betreiber bewusst behaltenes serverseitiges `wizard/` bleibt
  unangetastet; ein gelöschtes bleibt gelöscht.
- **Erstinstallation:** Der Betreiber lädt `wizard/` **einmalig** manuell per
  SFTP/scp hoch (es liegt zu diesem Zweck im dist-Paket bzw. im Repo), führt
  die Installation aus und **löscht `wizard/` danach serverseitig**. Ab dann
  ist der Runner `scripts/migrate.php` für alle weiteren Migrationen
  zuständig — der Update-Wizard wird nicht mehr benötigt.
- Der Deploy-Build-Check (D8, Schritt 5) prüft weiterhin, dass
  `dist/dog-mentality-test/wizard/index.php` existiert — das validiert nur
  die `build.sh`-Integrität; übertragen wird es nicht.

_Alternative (verworfen):_ `build.sh` schreibt `wizard/.lock` mit
gesperrtem Inhalt statt `inactive` und `.lock` wird **nicht** excludiert.
Funktioniert, hängt aber an der `.lock`-Inhaltssemantik und würde den Wizard
schon für die Erstinstallation sperren (Betreiber müsste `.lock` erst manuell
auf `inactive` setzen). Fragiler.

_Alternative (verworfen):_ Deploy-Step, der serverseitig `rm -rf wizard/`
ausführt. Automatisiertes `rm -rf` mit interpoliertem Pfad ist riskant; und
ein bewusst behaltenes `wizard/update.php` als Fallback ginge verloren.

### D3: Kopplung an CI über den Workflow-Namen `CI`

`on.workflow_run.workflows: ["CI"]` muss exakt dem `name:` in
`.github/workflows/ci.yml` entsprechen (dort in `add-ci-workflow` als `CI`
festgelegt). Zusätzlich: ein Guard im **CI**-Workflow (`add-ci-workflow`
liefert `ci.yml`; hier wird die Erwartung nur dokumentiert) bzw. ein
`grep`-Selbstcheck-Step in `deploy.yml`, der prüft, dass `ci.yml`
`name: CI` enthält und dass **alle** Schutz-Excludes aus D2
(`.maintenance`, `api/config.local.php`, `uploads/`, `logs/`, `.git`,
`wizard/`, `*.zip`, `dist/`, `php.ini`, `.user.ini`, `.htpasswd`,
`.well-known/`) im rsync-Step vorhanden sind (analog Referenz
`deploy-workflow-lint`). Namensänderung = Breaking Change.

### D4: Checkout des exakten CI-Commits

```
ref: >-
  ${{ github.event_name == 'workflow_run'
      && github.event.workflow_run.head_sha
      || (github.event.inputs.ref || github.ref) }}
```

Bei `workflow_run` wird der Commit deployt, der CI bestanden hat (nicht der
inzwischen ggf. neuere `main`-HEAD). Bei `workflow_dispatch` gilt der
`ref`-Input (Default `main`).

### D5: PHP 8.0 im Deploy, `build.sh` erzeugt `vendor/`

`shivammathur/setup-php@v2`, `php-version: '8.0'`,
`extensions: mbstring, ctype, gd, zip, mysqli, curl, json` — **identisch zur
CI-Liste** (`add-ci-workflow` design.md D9). `gd` und `zip` sind harte
`require` von PhpSpreadsheet 1.29 und werden von `setup-php` nicht per
Default aktiviert; `mysqli` statt `pdo_mysql` (die App nutzt durchgängig
`mysqli`). `build.sh` ruft intern `composer install --no-dev
--optimize-autoloader` auf und kopiert `vendor/` ins Paket. Damit wird
`vendor/` auf dem GitHub-Runner gebaut und per rsync übertragen — der Server
braucht **kein** Composer.

### D6: Migrationen via `scripts/migrate.php` über SSH

```
ssh -i ~/.ssh/deploy_key -p $DEPLOY_PORT "$DEPLOY_USER@$DEPLOY_HOST" \
  "cd '$DEPLOY_PATH' && $DEPLOY_PHP_BIN scripts/migrate.php"
```

Läuft **nach** dem rsync (der Runner-Code muss zuerst auf dem Server sein).
Nicht-null Exit → der Step schlägt fehl → der Workflow ist rot; der
Maintenance-Off-Step läuft trotzdem (`if: always()`).
`DEPLOY_PHP_BIN` (Default `php`, überschreibbar via `vars.DEPLOY_PHP_BIN`)
deckt Hoster ab, die `php8.0`/`php81` o. ä. verlangen. Für alfahosting hat
der User `php` im PATH mit `ext-mysqli` bestätigt (Spec-Gate 2026-08-30);
`DEPLOY_PHP_BIN` bleibt als Sicherheitsnetz.

_Alternative (verworfen):_ roher `mysql < dump` über SSH — siehe
`add-db-migration-runner` design.md D2 (Binary-Verfügbarkeit,
Passwort-Handling, Buchführung).

### D7: Leichtgewichtiger Wartungsmodus über `.maintenance`-Flag

**Entscheidung: implementieren** (nicht weglassen). Begründung: `rsync -az
--delete` über ein Live-Docroot erzeugt ein Zeitfenster mit teilweise
aktualisierten PHP-Dateien; parallel kann `scripts/migrate.php` `ALTER TABLE`
ausführen, während Requests die DB treffen. Ein 503-Fenster von wenigen
Sekunden ist deutlich sicherer. Kosten: ~6 Zeilen PHP + eine statische Seite
— KISS-konform.

Mechanik:

- `index.php`, ganz oben:

  ```php
  if (is_file(__DIR__ . '/.maintenance')) {
      http_response_code(503);
      header('Retry-After: 120');
      header('Content-Type: text/html; charset=utf-8');
      readfile(__DIR__ . '/maintenance.html');
      exit;
  }
  ```

- `api/config.php`, **vor** dem `.env`-Laden / vor jeder DB-Nutzung:

  ```php
  if (is_file(__DIR__ . '/../.maintenance')) {
      http_response_code(503);
      header('Retry-After: 120');
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['error' => 'Wartungsarbeiten – bitte in Kürze erneut versuchen']);
      exit;
  }
  ```

- `maintenance.html` — statische Seite (kein PHP, keine externen Assets),
  von `build.sh` nach `dist/dog-mentality-test/maintenance.html` kopiert.
- Workflow: „Maintenance an" =
  `ssh … "touch '$DEPLOY_PATH/.maintenance'"` (best-effort,
  `|| echo '::warning::…'` — schlägt beim allerersten Deploy fehl, wenn
  `$DEPLOY_PATH` noch nicht existiert; dann greift stattdessen der spätere
  rsync). „Maintenance aus" =
  `ssh … "rm -f '$DEPLOY_PATH/.maintenance'"` mit `if: always()` und
  `|| echo '::warning::…'`.
- `.maintenance` steht in `.gitignore` und in den rsync-Excludes → wird nie
  aus dem Build übertragen und nie von `--delete` entfernt.

_Alternative (verworfen):_ ganz weglassen — inakzeptables Risiko halb
deployter Zustände + Migration unter Last. _Alternative (verworfen):_
`.htaccess`-Rewrite auf Wartungsseite — fragiler (mod_rewrite-Annahmen,
betrifft auch statische Assets), schwerer zurückzunehmen.

### D8: Reihenfolge der Steps

1. Checkout (exakter Commit)
2. setup-php 8.0
3. `rsync` sicherstellen (`command -v rsync || sudo apt-get install -y rsync`)
4. `bash build.sh`
5. Build-Output verifizieren (fünf Kern-Dateien: `api/auth.php`,
   `frontend/index.html`, `wizard/index.php`, `vendor/autoload.php`,
   `scripts/migrate.php`; sonst `exit 1`)
6. Kopplungs-Guard (D3): `ci.yml` heißt `CI`, alle Schutz-Excludes vorhanden
7. SSH einrichten (Key schreiben, `chmod 600`, `ssh-keyscan`)
8. **Maintenance an** (best-effort)
9. **rsync** `-az --delete` mit Excludes (D2, inkl. `wizard/`)
10. **Migrationen** (D6)
11. **Maintenance aus** — `if: always()`
12. **SSH-Key entfernen** — `if: always()`
13. **Deployment-Summary** nach `$GITHUB_STEP_SUMMARY` — `if: always()`
    (Trigger, Commit-SHA, Actor, UTC-Zeit, Migrations-Ergebnis)

### D9: `known_hosts` statt `StrictHostKeyChecking=no`

`ssh-keyscan -p "$DEPLOY_PORT" -H "$DEPLOY_HOST" >> ~/.ssh/known_hosts` und
`-o StrictHostKeyChecking=yes` bei allen `ssh`/`rsync -e`-Aufrufen. Verhindert
stilles Vertrauen auf einen wechselnden Host-Key (MITM-Härtung). Entspricht
der Referenz.

## Risks / Trade-offs

- **[`rsync --delete` löscht ungewollt serverseitige Dateien]** →
  Mitigation: Quelle ist der kuratierte `dist/`-Stand; explizite Excludes für
  alle bekannten Laufzeitpfade; `grep`-Guard-Step, der die Excludes in
  `deploy.yml` erzwingt; erster Lauf idealerweise mit `--dry-run`
  (Task-Option) bzw. genaue Prüfung der Summary.
- **[Wartungsmodus bleibt hängen, Seite dauerhaft 503]** → Mitigation:
  „Maintenance aus" mit `if: always()`; `Retry-After`-Header;
  Summary-Warnung; `CI_CD.md` dokumentiert manuelles
  `rm -f $DEPLOY_PATH/.maintenance`.
- **[Erster Deploy ist rot: `$DEPLOY_PATH` bzw. `api/config.local.php`
  existiert noch nicht]** → **vom User am 2026-08-30 akzeptiert.** Ablauf:
  1. erster Deploy überträgt die Dateien, `scripts/migrate.php` bricht ohne
  `config.local.php` sauber mit Exit 1 ab (Deploy rot); 2. Betreiber lädt
  `wizard/` einmalig per SFTP hoch, führt die Installation aus (erzeugt
  `api/config.local.php`), löscht `wizard/` serverseitig; 3. Deploy erneut
  starten → grün. Keine Sonderlogik im Workflow. In `CI_CD.md` als
  „Erstinbetriebnahme" Schritt für Schritt beschrieben.
- **[PHP-CLI auf dem Server nicht als `php` erreichbar]** → für alfahosting
  vom User bestätigt (Spec-Gate 2026-08-30); `vars.DEPLOY_PHP_BIN`
  (Default `php`) bleibt als Sicherheitsnetz.
- **[`wizard/` nach Deploy ungeschützt erreichbar]** → Mitigation:
  `--exclude='wizard/'` (D2b); Guard-Step erzwingt das Exclude;
  Erstinstallations-Wizard wird manuell hoch- und wieder abgeräumt.
- **[Migration schlägt fehl → DB in Zwischenzustand]** → Mitigation:
  Migrationen sind wiederholungssicher (Guards / `IF NOT EXISTS`, siehe
  `add-db-migration-runner`); Wartungsseite bleibt bis zum manuellen Eingriff
  sinnvoll; Open Question: automatisches DB-Backup vor Migration.
- **[Workflow-Name-Drift `CI` ↔ `deploy.yml`]** → Mitigation: D3 + Guard-Step.
- **[Secrets fehlen / falsch konfiguriert]** → Mitigation: erste Steps geben
  klare Fehlermeldung; `CI_CD.md` listet alle benötigten Secrets/Variables
  und die Required-Reviewer-Einrichtung.
- **[`build.sh` `DEPLOY_CHECKLIST.txt` nennt weiterhin FTP]** → optionaler
  Task passt die Formulierung transport-neutral an; `FTP_DEPLOYMENT.md`
  erhält einen Hinweis auf SSH/CI-CD als bevorzugten Weg.
- **[`--delete` löscht serverseitige `php.ini`/`.user.ini`/`.htpasswd`/
  `.well-known/`]** → Mitigation: alle vier als `--exclude` in D2; Guard-Step
  erzwingt sie; Skeptiker prüft, ob weitere hoster-spezifische Docroot-Pfade
  (z. B. `cgi-bin/`, `.lscache/`) bei alfahosting relevant sind.

## Migration Plan

1. `add-ci-workflow` und `add-db-migration-runner` gemergt.
2. `.maintenance`-Guards in `index.php` / `api/config.php`, `maintenance.html`,
   `build.sh`-Kopie, `.gitignore`-Eintrag.
3. `deploy.yml` hinzufügen.
4. User richtet Environment `production` + Secrets + Required Reviewer ein
   (nach `CI_CD.md`).
5. Erstinbetriebnahme (User-akzeptiert, dass Schritt 5a rot ist):
   a. `workflow_dispatch` gegen `main`, Approval → Dateien übertragen,
      Migrations-Step rot mangels `api/config.local.php`.
   b. `wizard/` einmalig per SFTP hochladen, Installation ausführen
      (erzeugt `api/config.local.php`), `wizard/` serverseitig löschen.
   c. Deploy erneut starten → grün.
6. Danach: automatischer Deploy-Lauf nach jedem grünen CI auf `main` (mit
   Approval). `wizard/` wird nie wieder übertragen (D2b).

Rollback eines fehlerhaften Deploys: `workflow_dispatch` mit `ref` = letzter
guter Commit-SHA; DB nur manuell/aus Backup. `.maintenance` ggf. manuell
entfernen.

## Open Questions

- Automatischer DB-Dump vor `scripts/migrate.php` (z. B.
  `mysqldump` via SSH nach `$DEPLOY_PATH/../backups/`)? Erhöht Sicherheit,
  setzt `mysqldump` auf dem Server voraus. Empfehlung: als Folge-Change.
- ~~`wizard/` nach Erstinstallation durch den Deploy entfernen?~~
  **Entschieden (D2b):** `wizard/` wird per `--exclude` gar nicht erst
  angefasst; Erstinstallations-Wizard wird manuell hoch- und abgeräumt.
- Prüfen (Skeptiker): weitere alfahosting-spezifische Docroot-Pfade, die vor
  `--delete` geschützt werden müssen (`cgi-bin/`, `.lscache/`, o. ä.).
- `Retry-After`-Wert (Vorschlag 120 s) und Inhalt/Branding von
  `maintenance.html` — vom User bestätigungsfähig.
- Soll `deploy.yml` einen vorgeschalteten `--dry-run`-rsync-Step (nur
  Logging) dauerhaft behalten?
