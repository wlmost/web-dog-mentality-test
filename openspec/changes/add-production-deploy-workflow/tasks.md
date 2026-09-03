## 1. Wartungsmodus

- [x] 1.1 `.maintenance`-Guard in `index.php`
  - Agent: Developer
  - Dateien: `index.php`
  - Abhängigkeiten: keine
  - Beschreibung: Ganz am Anfang (vor dem 302-Redirect): wenn
    `is_file(__DIR__ . '/.maintenance')`, `http_response_code(503)`,
    `header('Retry-After: 120')`, `header('Content-Type: text/html; charset=utf-8')`,
    `readfile(__DIR__ . '/maintenance.html')`, `exit;`.
  - Akzeptanz:
    - [x] `php -l index.php` fehlerfrei
    - [x] Mit vorhandener `.maintenance` liefert ein Aufruf HTTP 503 +
      `Retry-After` + Inhalt von `maintenance.html`
    - [x] Ohne `.maintenance` unverändertes Redirect-Verhalten

- [x] 1.2 `.maintenance`-Guard in `api/config.php`
  - Agent: Developer
  - Dateien: `api/config.php`
  - Abhängigkeiten: keine
  - Beschreibung: Als erste ausführbare Anweisung nach
    `declare(strict_types=1);` (`api/config.php:2`), vor `loadEnv()` und weit
    vor `new mysqli(...)` (`api/config.php:93`): wenn
    `is_file(__DIR__ . '/../.maintenance')`, `http_response_code(503)`,
    `header('Retry-After: 120')`,
    `header('Content-Type: application/json; charset=utf-8')`,
    `echo json_encode(['error' => 'Wartungsarbeiten – bitte in Kürze erneut versuchen'])`,
    `exit;`.
  - Akzeptanz:
    - [x] `php -l api/config.php` fehlerfrei
    - [x] Mit `.maintenance` liefert ein API-Aufruf HTTP 503 + JSON-Fehler,
      **ohne** dass `getDbConnection()` erreicht wird
    - [x] Ohne `.maintenance` unverändertes Verhalten

- [x] 1.3 `maintenance.html` anlegen
  - Agent: Developer
  - Dateien: `maintenance.html` (neu)
  - Abhängigkeiten: keine
  - Beschreibung: Schlanke, in sich geschlossene HTML-Seite (kein PHP, keine
    externen Assets/Fonts), deutschsprachig, kurzer Hinweis „Wartungsarbeiten".
  - Akzeptanz:
    - [x] Datei ist valides HTML ohne externe Requests
    - [x] Wird ohne Server-Fehler direkt ausgeliefert

- [x] 1.4 `.gitignore` um `.maintenance` ergänzen
  - Agent: Developer
  - Dateien: `.gitignore`
  - Abhängigkeiten: keine
  - Beschreibung: Eintrag `.maintenance` (Laufzeit-Flag) ergänzen, sinnvoll
    im Block nahe `# Temporary files` oder eigener Kommentarblock.
  - Akzeptanz:
    - [x] `git check-ignore .maintenance` bestätigt den Eintrag

## 2. build.sh

- [x] 2.1 `maintenance.html` ins Deploy-Paket aufnehmen
  - Agent: Developer
  - Dateien: `build.sh`
  - Abhängigkeiten: 1.3
  - Beschreibung: Im Root-Datei-Abschnitt von `build.sh` (dort, wo
    `.htaccess`, `index.php`, `php.ini.example`, `composer.json` kopiert
    werden) `maintenance.html` mit aufnehmen:
    `[[ -f "maintenance.html" ]] && cp "maintenance.html" "$DIST/maintenance.html"`.
  - Akzeptanz:
    - [x] Nach `bash build.sh` existiert
      `dist/dog-mentality-test/maintenance.html`
    - [x] `bash build.sh` endet mit Exit-Code 0

- [x] 2.2 (optional) `DEPLOY_CHECKLIST.txt` transport-neutral formulieren
  - Agent: Developer
  - Dateien: `build.sh`
  - Abhängigkeiten: keine
  - Beschreibung: Kosmetisch — die FTP-spezifischen Formulierungen im
    Here-Doc `DEPLOY_CHECKLIST.txt` neutral halten (SSH/rsync-Deploy statt
    „per FTP übertragen"). Kein funktionaler Umbau.
  - Akzeptanz:
    - [x] `DEPLOY_CHECKLIST.txt` im Build nennt kein FTP als einzigen Weg

## 3. deploy.yml

- [ ] 3.1 Grundgerüst: Trigger, Concurrency, Environment, Checkout
  - Agent: Developer
  - Dateien: `.github/workflows/deploy.yml` (neu)
  - Abhängigkeiten: keine (setzt `add-ci-workflow` gemergt voraus)
  - Beschreibung: `on.workflow_run` (`workflows: ["CI"]`,
    `types: [completed]`, `branches: [main]`) + `on.workflow_dispatch`
    (`inputs.ref`, Default `main`). `concurrency: { group: deploy-production,
    cancel-in-progress: false }`. Ein Job `deploy` auf `ubuntu-latest` mit
    `environment: production`,
    `if: github.event_name == 'workflow_dispatch' || github.event.workflow_run.conclusion == 'success'`,
    `env: { DEPLOY_PORT: ${{ vars.DEPLOY_PORT || 22 }}, DEPLOY_PHP_BIN: ${{ vars.DEPLOY_PHP_BIN || 'php' }} }`.
    Checkout-Step mit
    `ref: ${{ github.event_name == 'workflow_run' && github.event.workflow_run.head_sha || (github.event.inputs.ref || github.ref) }}`.
  - Akzeptanz:
    - [ ] `workflow_run` referenziert exakt `"CI"`
    - [ ] `concurrency` mit `cancel-in-progress: false` gesetzt
    - [ ] Job nutzt `environment: production`
    - [ ] Checkout-`ref`-Ausdruck wie spezifiziert

- [ ] 3.2 Build-Steps im Workflow
  - Agent: Developer
  - Dateien: `.github/workflows/deploy.yml`
  - Abhängigkeiten: 3.1
  - Beschreibung: `shivammathur/setup-php@v2` (`php-version: '8.0'`,
    `extensions: mbstring, ctype, gd, zip, mysqli, curl, json` — **identisch
    zur CI-Liste**, siehe `add-ci-workflow` design.md D9; `gd`/`zip` sind
    harte PhpSpreadsheet-1.29-`require` und nicht setup-php-Default;
    `mysqli` statt `pdo_mysql`); `rsync` sicherstellen
    (`command -v rsync || sudo apt-get update && sudo apt-get install -y rsync`);
    `bash build.sh`; danach Shell-Verifikation auf
    `dist/dog-mentality-test/{api/auth.php,frontend/index.html,wizard/index.php,vendor/autoload.php,scripts/migrate.php}`
    → fehlt eine, `exit 1` **vor** jedem SSH-Schritt. Hinweis:
    `wizard/index.php` validiert nur die `build.sh`-Integrität; `wizard/`
    wird später vom rsync ausgeschlossen (Task 3.4).
  - Akzeptanz:
    - [ ] Fehlt eine Kern-Datei, bricht der Job vor SSH ab
    - [ ] `scripts/migrate.php` ist Teil der Verifikation
    - [ ] `extensions:` enthält `gd` und `zip`; kein `pdo_mysql`

- [ ] 3.3 SSH einrichten + Wartungsmodus an
  - Agent: Developer
  - Dateien: `.github/workflows/deploy.yml`
  - Abhängigkeiten: 3.2
  - Beschreibung: `mkdir -p ~/.ssh && chmod 700 ~/.ssh`; Key aus
    `secrets.DEPLOY_SSH_KEY` nach `~/.ssh/deploy_key`, `chmod 600`;
    `ssh-keyscan -p "$DEPLOY_PORT" -H "$secrets.DEPLOY_HOST" >> ~/.ssh/known_hosts`.
    Danach Step „Maintenance an":
    `ssh -i ~/.ssh/deploy_key -p "$DEPLOY_PORT" -o StrictHostKeyChecking=yes "$USER@$HOST" "touch '$DEPLOY_PATH/.maintenance'" || echo "::warning::Wartungsmodus konnte nicht aktiviert werden (evtl. Erst-Deploy)"`.
  - Akzeptanz:
    - [ ] Kein `StrictHostKeyChecking=no` / `-o StrictHostKeyChecking=accept-new`
    - [ ] „Maintenance an" ist best-effort (Fehlschlag → nur Warnung)

- [ ] 3.4 rsync mit Schutz-Excludes
  - Agent: Developer
  - Dateien: `.github/workflows/deploy.yml`
  - Abhängigkeiten: 3.3
  - Beschreibung: `rsync -az --delete` mit `--exclude` für **alle zwölf**
    Muster: `.maintenance`, `api/config.local.php`, `uploads/`, `logs/`,
    `.git`, `wizard/`, `*.zip`, `dist/`, `php.ini`, `.user.ini`,
    `.htpasswd`, `.well-known/`;
    `-e "ssh -i ~/.ssh/deploy_key -p $DEPLOY_PORT -o StrictHostKeyChecking=yes"`;
    Quelle `dist/dog-mentality-test/`, Ziel
    `"$DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH/"`. `wizard/` wird **vollständig**
    ausgeschlossen (Begründung: design.md D2b — sonst ist der Web-Installer
    nach jedem Deploy ohne `.lock` und damit ungeschützt erreichbar).
    `php.ini`/`.user.ini`/`.htpasswd`/`.well-known/` schützen serverseitig
    angelegte Hoster-/ACME-Dateien vor `--delete`.
  - Akzeptanz:
    - [ ] Alle zwölf Schutz-Excludes im Aufruf vorhanden (inkl. `wizard/`,
      `php.ini`, `.user.ini`, `.htpasswd`, `.well-known/`)
    - [ ] Kein `wizard/.lock`-Einzelexclude mehr (durch `wizard/` abgedeckt)
    - [ ] Quelle ist `dist/dog-mentality-test/` (mit Trailing Slash)
    - [ ] `--delete` aktiv

- [ ] 3.5 Migrationen per SSH
  - Agent: Developer
  - Dateien: `.github/workflows/deploy.yml`
  - Abhängigkeiten: 3.4
  - Beschreibung: Step nach rsync:
    `ssh -i ~/.ssh/deploy_key -p "$DEPLOY_PORT" -o StrictHostKeyChecking=yes "$USER@$HOST" "cd '$DEPLOY_PATH' && $DEPLOY_PHP_BIN scripts/migrate.php"`.
    Kein `|| true` — Exit ≠ 0 lässt den Job fehlschlagen.
  - Akzeptanz:
    - [ ] Migrations-Fehler macht den Job rot
    - [ ] Nutzt `$DEPLOY_PHP_BIN`

- [ ] 3.6 Wartungsmodus aus, Key-Cleanup, Summary (alle `if: always()`)
  - Agent: Developer
  - Dateien: `.github/workflows/deploy.yml`
  - Abhängigkeiten: 3.5
  - Beschreibung: Step „Maintenance aus" (`if: always()`):
    `ssh … "rm -f '$DEPLOY_PATH/.maintenance'" || echo "::warning::…"`.
    Step „Remove SSH key" (`if: always()`): `rm -f ~/.ssh/deploy_key`.
    Step „Deployment summary" (`if: always()`): Tabelle nach
    `$GITHUB_STEP_SUMMARY` mit Auslöser, deployten Commit (head_sha bzw.
    ref), Actor, UTC-Zeit, Migrations-Ergebnis.
  - Akzeptanz:
    - [ ] Alle drei Steps haben `if: always()`
    - [ ] Summary enthält die fünf geforderten Felder

- [ ] 3.7 Kopplungs-Guard-Step
  - Agent: Developer
  - Dateien: `.github/workflows/deploy.yml`
  - Abhängigkeiten: 3.4
  - Beschreibung: Früher Step (nach Checkout, vor Build): `set -e`;
    `grep -q '^name: CI$' .github/workflows/ci.yml || { echo "::error::ci.yml heißt nicht mehr 'CI'"; exit 1; }`;
    für **jedes** der zwölf Schutz-Excludes aus Task 3.4
    `grep -q "exclude='<muster>'" .github/workflows/deploy.yml` (an das
    tatsächliche Quoting angepasst) — fehlt eines, `echo "::error::…"; exit 1`.
  - Akzeptanz:
    - [ ] Umbenennen von `ci.yml` `name:` lässt den Guard fehlschlagen
    - [ ] Entfernen eines beliebigen der zwölf Excludes (insb. `wizard/`)
      lässt den Guard fehlschlagen

## 4. Dokumentation

- [ ] 4.1 `CI_CD.md` schreiben
  - Agent: Developer
  - Dateien: `CI_CD.md` (neu), ggf. Verweis aus `README.md`
  - Abhängigkeiten: 3.1–3.7
  - Beschreibung: Abschnitte: (a) Zielplattform: **alfahosting**,
    SSH-fähiger Managed-Tarif (SSH + php-CLI mit `ext-mysqli` + `rsync`
    verfügbar, vom User am 2026-08-30 bestätigt); (b) benötigte
    Environment-Secrets `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_USER`,
    `DEPLOY_PATH`; (c) Variablen `DEPLOY_PORT` (Default 22), `DEPLOY_PHP_BIN`
    (Default `php`); (d) Environment `production` + Required Reviewer
    einrichten; (e) öffentlichen SSH-Key in `authorized_keys` des
    Deploy-Users; (f) **Erstinbetriebnahme** ausdrücklich als
    3-Schritt-Ablauf: 1. erster Deploy ist **rot** (Dateien übertragen,
    `scripts/migrate.php` bricht mangels `api/config.local.php` mit Exit 1
    ab) — **das ist erwartet und vom User akzeptiert**; 2. `wizard/` einmalig
    per SFTP/scp hochladen, Installation im Browser ausführen (erzeugt
    `api/config.local.php`), danach `wizard/` serverseitig löschen;
    3. Deploy erneut starten → grün; (g) Regelbetrieb „grünes CI → Approval
    → Deploy"; (h) Rollback via `workflow_dispatch` + `ref` = letzter guter
    Commit-SHA (DB manuell/aus Backup); (i) Notfall:
    `rm -f $DEPLOY_PATH/.maintenance`; (j) Hinweis, dass `wizard/` per Deploy
    nie übertragen wird (D2b).
  - Akzeptanz:
    - [ ] Alle Abschnitte (a)–(j) vorhanden
    - [ ] Erstinbetriebnahme nennt explizit „1. Deploy rot ist erwartet"
    - [ ] Secret-/Variablennamen stimmen mit `deploy.yml` überein

- [ ] 4.2 `FTP_DEPLOYMENT.md` um SSH-/CI-CD-Hinweis ergänzen
  - Agent: Developer
  - Dateien: `FTP_DEPLOYMENT.md`
  - Abhängigkeiten: keine
  - Beschreibung: Am Anfang des Dokuments einen kurzen Hinweiskasten
    einfügen: Die Produktion läuft bei **alfahosting** mit SSH-Zugang; der
    automatische **CI/CD-Deploy** (`.github/workflows/deploy.yml`, siehe
    `CI_CD.md`) ist der bevorzugte Weg. Die folgende FTP-Anleitung bleibt als
    **Fallback** erhalten (z. B. für Notfälle ohne CI).
  - Akzeptanz:
    - [ ] `FTP_DEPLOYMENT.md` nennt alfahosting + SSH + CI/CD als bevorzugt
    - [ ] Die bestehende FTP-Anleitung bleibt vollständig erhalten

## 5. Verifikation

- [ ] 5.1 Workflow-Syntax und Trockenlauf prüfen
  - Agent: Developer
  - Dateien: — (Verifikation)
  - Abhängigkeiten: 3.1–3.7, 4.1, 4.2
  - Beschreibung: `deploy.yml` mit einem YAML-/Actions-Linter prüfen
    (z. B. `actionlint`); den Guard-Step lokal simulieren; auf einem
    Test-/Staging-Ziel (falls verfügbar) einen `workflow_dispatch`-Lauf bis
    zum Approval durchführen und die Summary kontrollieren. Ergebnis in
    `task-5.1.notes.md`.
  - Akzeptanz:
    - [ ] `actionlint` meldet keine Fehler für `deploy.yml`
    - [ ] Ein pausierter Lauf im Status „Waiting" ist im Actions-UI sichtbar
    - [ ] Nach Approval laufen die Steps in der spezifizierten Reihenfolge
