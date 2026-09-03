# Tasks 3.1–3.3 — `.github/workflows/deploy.yml` (Grundgerüst, Build-Steps, SSH+Maintenance-an)

## Umfang

`.github/workflows/deploy.yml` (neu) mit den Abschnitten "3. deploy.yml" aus
`tasks.md`:

- **3.1** Grundgerüst: Trigger (`workflow_run` + `workflow_dispatch`),
  `concurrency`, `environment: production`, Checkout des exakten Commits.
- **3.2** Build-Steps: `setup-php`, `rsync`-Verfügbarkeit, `bash build.sh`,
  Shell-Verifikation der fünf Kern-Dateien **vor** jedem SSH-Schritt.
- **3.3** SSH einrichten (Key-Datei, `chmod 600`, `ssh-keyscan`) +
  „Maintenance an" (best-effort).

Explizit **nicht** implementiert (folgt in einem Folge-Schritt): 3.4 (rsync
mit den zwölf Schutz-Excludes), 3.5 (Migrationen), 3.6 (Maintenance aus /
Key-Cleanup / Summary), 3.7 (Kopplungs-Guard-Step).

**Begründung, warum 3.3 in diesem Schritt enthalten ist:** Der Auftrag
schließt SSH-Schritte explizit aus, "außer explizit Task 3.3 verlangt „SSH
einrichten + Wartungsmodus an" als Teil dieses Blocks" — genau das ist der
Titel von Task 3.3 in `tasks.md`. Der Auftrag listet zudem "3.1/3.2/3.3" als
"diese drei" (im Gegensatz zu "den späteren 3.4-3.7"). Daher wurde 3.3
vollständig umgesetzt und in `tasks.md` abgehakt.

## Geänderte / neue Dateien

- `.github/workflows/deploy.yml` (neu)
- `openspec/changes/add-production-deploy-workflow/tasks.md` — Checkboxen
  3.1, 3.2, 3.3 (inkl. aller Akzeptanzkriterien) abgehakt.

## Umsetzung im Detail

### Trigger / Concurrency / Job-Grundgerüst (3.1)

```yaml
on:
  workflow_run:
    workflows: ["CI"]
    types: [completed]
    branches: [main]
  workflow_dispatch:
    inputs:
      ref:
        description: "Commit/Branch/Tag, der deployt werden soll"
        required: false
        default: main

concurrency:
  group: deploy-production
  cancel-in-progress: false
```

`workflows: ["CI"]` referenziert exakt den `name: CI` aus `.github/workflows/ci.yml`
(design.md D3) — die Kopplungs-Guard-Prüfung dafür folgt erst in Task 3.7.

Job:

```yaml
jobs:
  deploy:
    runs-on: ubuntu-latest
    if: github.event_name == 'workflow_dispatch' || github.event.workflow_run.conclusion == 'success'
    environment: production
    env:
      DEPLOY_PORT: ${{ vars.DEPLOY_PORT || 22 }}
      DEPLOY_PHP_BIN: ${{ vars.DEPLOY_PHP_BIN || 'php' }}
      DEPLOY_HOST: ${{ secrets.DEPLOY_HOST }}
      DEPLOY_USER: ${{ secrets.DEPLOY_USER }}
      DEPLOY_PATH: ${{ secrets.DEPLOY_PATH }}
```

`DEPLOY_HOST`/`DEPLOY_USER`/`DEPLOY_PATH` wurden zusätzlich zu den in Task 3.1
geforderten `DEPLOY_PORT`/`DEPLOY_PHP_BIN` auf Job-Ebene als `env` aus den
gleichnamigen Environment-Secrets gesetzt (design.md nennt diese vier als
Secrets für `production`). Das ist nötig, damit Task 3.3 (SSH/Maintenance-an)
und die späteren Tasks 3.4–3.6 sie konsistent per `$DEPLOY_HOST` usw.
referenzieren können, ohne sie in jedem Step einzeln aus `secrets.*`
abzuleiten. `permissions: contents: read` wurde analog zu `ci.yml` ergänzt
(least privilege, kein funktionaler Bestandteil der Task-Akzeptanzkriterien,
aber keine Abweichung von ihnen).

Checkout-Step mit dem in Task 3.1 / design.md D4 wörtlich vorgegebenen
`ref`-Ausdruck (gefaltete Mehrzeilen-Schreibweise identisch zum
`design.md`-Beispiel übernommen):

```yaml
- name: Checkout
  uses: actions/checkout@v4
  with:
    ref: >-
      ${{ github.event_name == 'workflow_run'
          && github.event.workflow_run.head_sha
          || (github.event.inputs.ref || github.ref) }}
```

### Build-Steps (3.2)

- `shivammathur/setup-php@v2`, `php-version: "8.0"`,
  `extensions: mbstring, ctype, gd, zip, mysqli, curl, json` — identisch zur
  Liste in `ci.yml` (Jobs `composer`/`build`).
- „rsync sicherstellen" — identischer Einzeiler wie in `ci.yml`:
  `command -v rsync || (sudo apt-get update && sudo apt-get install -y rsync)`.
- „Build ausführen" — `bash build.sh`.
- „Kern-Dateien verifizieren" — Schleife über
  `api/auth.php frontend/index.html wizard/index.php vendor/autoload.php scripts/migrate.php`
  (fünf Dateien, `scripts/migrate.php` zusätzlich zur `ci.yml`-Liste, wie in
  Task 3.2 gefordert); fehlt eine, `echo "::error::..."; exit 1` — dieser
  Schritt steht **vor** dem ersten SSH-Schritt.

### SSH + Maintenance an (3.3)

```yaml
- name: SSH einrichten
  env:
    DEPLOY_SSH_KEY: ${{ secrets.DEPLOY_SSH_KEY }}
  run: |
    mkdir -p ~/.ssh
    chmod 700 ~/.ssh
    printf '%s\n' "$DEPLOY_SSH_KEY" > ~/.ssh/deploy_key
    chmod 600 ~/.ssh/deploy_key
    ssh-keyscan -p "$DEPLOY_PORT" -H "$DEPLOY_HOST" >> ~/.ssh/known_hosts

- name: Wartungsmodus an
  run: |
    ssh -i ~/.ssh/deploy_key -p "$DEPLOY_PORT" -o StrictHostKeyChecking=yes "$DEPLOY_USER@$DEPLOY_HOST" \
      "touch '$DEPLOY_PATH/.maintenance'" \
      || echo "::warning::Wartungsmodus konnte nicht aktiviert werden (evtl. Erst-Deploy)"
```

Sicherheitsaspekte (design.md D9, Task 3.3):

- `ssh-keyscan` befüllt `~/.ssh/known_hosts` **vor** der ersten SSH-Nutzung;
  `-o StrictHostKeyChecking=yes` wird verwendet — nirgends
  `StrictHostKeyChecking=no` oder `accept-new`.
- Der private Schlüssel wird per `printf '%s\n' "$DEPLOY_SSH_KEY" > ...`
  in eine Datei geschrieben (nicht auf `stdout`/Log ausgegeben) und erhält
  sofort `chmod 600`.
- „Maintenance an" ist best-effort: `|| echo "::warning::..."` — ein
  Fehlschlag (z. B. beim allerersten Deploy, wenn `$DEPLOY_PATH` noch nicht
  existiert) bricht den Job **nicht** ab, sondern erzeugt nur eine
  GitHub-Actions-Warnung.
- Kein Secret-Wert wird im Klartext geloggt (weder Key-Inhalt noch Host/User/
  Pfad werden per `echo` ausgegeben; GitHub Actions maskiert
  Secret-Referenzen in Logs zusätzlich automatisch).

## Verifikation

- **YAML-Syntax:** `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))"`
  — lädt fehlerfrei (Ausgabe als verschachteltes Dict geprüft, inkl. korrekt
  gefaltetem `ref`-Ausdruck im Checkout-Step).
- **actionlint:** nicht installiert in dieser Umgebung
  (`which actionlint` → nicht gefunden). Wurde absichtlich **nicht**
  installiert (das ist laut `tasks.md` Task 5.1, außerhalb dieses Scopes).
  Task 5.1 muss `deploy.yml` inkl. der hier neu hinzugekommenen Steps noch
  mit `actionlint` prüfen.
- **Manueller Abgleich gegen Akzeptanzkriterien:**
  - 3.1: `workflow_run.workflows: ["CI"]` ✓; `concurrency.cancel-in-progress: false` ✓;
    `environment: production` ✓; Checkout-`ref`-Ausdruck wortgleich zu
    design.md D4 ✓.
  - 3.2: Kern-Dateien-Verifikation mit `exit 1` steht vor dem ersten
    SSH-Step ✓; `scripts/migrate.php` ist Teil der Liste ✓;
    `extensions:` enthält `gd, zip`, kein `pdo_mysql` (stattdessen `mysqli`) ✓.
  - 3.3: kein `StrictHostKeyChecking=no`/`accept-new` (grep bestätigt: einziges
    Vorkommen ist `StrictHostKeyChecking=yes`) ✓; „Maintenance an" hat
    `|| echo "::warning::..."` statt hartem Abbruch ✓.
- Kein Docker-/Live-Lauf möglich (Secrets/Environment `production` sind noch
  nicht im echten GitHub-Repo konfiguriert) — wie im Auftrag erwartet.

## Abweichungen von der Spec

Keine inhaltliche Abweichung. Ergänzungen ohne Widerspruch zu einem
Akzeptanzkriterium:

- `permissions: contents: read` auf Workflow-Ebene (Analogie zu `ci.yml`,
  least privilege).
- Job-`env` enthält zusätzlich `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`
  (aus den gleichnamigen Secrets), da diese ab Task 3.3 gebraucht werden und
  design.md sie ausdrücklich als benötigte Secrets nennt.
- `workflow_dispatch.inputs.ref` erhält eine `description`
  (kosmetisch, für die GitHub-UI).

## Hinweise für Folge-Tasks

- Task 3.4 (rsync mit den zwölf Schutz-Excludes) fügt den rsync-Transfer
  **nach** dem „Wartungsmodus an"-Step ein; Quelle `dist/dog-mentality-test/`,
  Ziel `"$DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH/"` — beide Variablen sind
  bereits als Job-`env` verfügbar.
- Task 3.5 (Migrationen) nutzt `$DEPLOY_PHP_BIN` und `$DEPLOY_PATH`
  (ebenfalls bereits vorhanden).
- Task 3.6 (Maintenance aus / Key-Cleanup / Summary, alle `if: always()`)
  ergänzt die verbleibenden Schritte ans Ende des Jobs.
- Task 3.7 (Kopplungs-Guard) sollte als früher Step nach dem Checkout
  eingefügt werden (vor dem Build), wie in design.md D8 Schritt 6 vorgesehen.
- Task 5.1 muss `actionlint` gegen die fertige `deploy.yml` laufen lassen,
  sobald alle Steps (3.4–3.7) ergänzt sind.
