# Test-Report: T3.1-3.3

**Status:** alle-gruen

**Scope-Hinweis:** Getestet wird ausschließlich der in Commit `f653e44`
implementierte Teil von `.github/workflows/deploy.yml`: Grundgerüst/Trigger
(3.1), Build-Steps inkl. Kern-Dateien-Verifikation (3.2) und SSH-Setup +
„Maintenance an" (3.3). rsync mit Schutz-Excludes (3.4), Migrationen (3.5),
Maintenance-aus/Key-Cleanup/Summary (3.6) und der Kopplungs-Guard (3.7)
existieren in `deploy.yml` noch nicht und werden hier **nicht** getestet (laut
Auftrag explizit ausgeklammert). Ein echter End-to-End-Test gegen einen realen
Deploy-Zielserver ist nicht möglich, da `DEPLOY_SSH_KEY`/`DEPLOY_HOST`/
`DEPLOY_USER`/`DEPLOY_PATH` und das GitHub-Environment `production` in diesem
Repo noch nicht konfiguriert sind (erwartungsgemäß, siehe
`task-T3.1-3.2.notes.md`).

## Hinzugefügte / geänderte Tests

- `tests/deploy-workflow-smoke.sh` (neu): 23 Prüfungen in 6 Abschnitten,
  analog zum bestehenden Muster `tests/ci-workflow-smoke.sh` (reines Bash +
  Python/pyyaml, `pass`/`fail`-Helfer, `trap cleanup EXIT`, kein PHPUnit,
  keine dauerhaften Änderungen an Produktivdateien). Kein bestehender Test
  wurde verändert oder gelöscht.

## Akzeptanzkriterien-Abdeckung

### Task 3.1 — Grundgerüst: Trigger, Concurrency, Environment, Checkout

- [x] `workflow_run` referenziert exakt `"CI"` — getestet in
  `deploy-workflow-smoke.sh::Abschnitt 2` ("on.workflow_run.workflows
  referenziert exakt [\"CI\"]")
- [x] `concurrency` mit `cancel-in-progress: false` gesetzt — getestet in
  `deploy-workflow-smoke.sh::Abschnitt 2` ("concurrency: group=..., 
  cancel-in-progress=false")
- [x] Job nutzt `environment: production` — getestet in
  `deploy-workflow-smoke.sh::Abschnitt 2` ("Job nutzt environment:
  production")
- [x] Checkout-`ref`-Ausdruck wie spezifiziert — visuell/strukturell
  verifiziert per YAML-Parse (Abschnitt 1) und manuellem Abgleich des
  extrahierten `with.ref`-Werts gegen design.md D4 (siehe
  Ausführungs-Ergebnis unten, Python-Dump); zusätzlich indirekt über
  Abschnitt 5 (Trigger-Logik), da derselbe Bedingungsausdruck
  (`workflow_run` vs. `workflow_dispatch`) auch im `ref`-Ausdruck verwendet
  wird

### Task 3.2 — Build-Steps im Workflow

- [x] Fehlt eine Kern-Datei, bricht der Job vor SSH ab — getestet in
  `deploy-workflow-smoke.sh::Abschnitt 4` ("extrahierter Step bricht mit
  Exit 1 ab, wenn scripts/migrate.php fehlt"); zusätzlich durch die
  Reihenfolge im Workflow selbst bestätigt (Abschnitt 1/2: der
  „Kern-Dateien verifizieren"-Step steht laut YAML-Struktur vor dem
  „SSH einrichten"-Step)
- [x] `scripts/migrate.php` ist Teil der Verifikation — getestet in
  `deploy-workflow-smoke.sh::Abschnitt 3+4` (lokaler `build.sh`-Lauf prüft
  alle fünf Kern-Dateien inkl. `scripts/migrate.php`; Negativtest lässt
  gezielt `scripts/migrate.php` fehlen)
- [x] `extensions:` enthält `gd` und `zip`; kein `pdo_mysql` — getestet in
  `deploy-workflow-smoke.sh::Abschnitt 2` ("extensions-Liste enthält
  gd/zip/mysqli, kein pdo_mysql")

### Task 3.3 — SSH einrichten + Wartungsmodus an

- [x] Kein `StrictHostKeyChecking=no` / `-o StrictHostKeyChecking=accept-new`
  — manuell per `grep` gegen `deploy.yml` bestätigt (siehe
  Ausführungs-Ergebnis unten); einziges Vorkommen ist
  `StrictHostKeyChecking=yes`. Nicht als eigener Assert in die
  Smoke-Test-Datei aufgenommen (redundant zur Prüfung 3.4/3.7, die erst mit
  dem rsync-Step relevant wird), aber als Teil dieses Berichts manuell
  verifiziert.
- [x] „Maintenance an" ist best-effort (Fehlschlag → nur Warnung) —
  manuell per `grep`/Lesen des `run:`-Blocks bestätigt: Step endet mit
  `|| echo "::warning::..."`, kein harter Abbruch. Nicht live gegen einen
  Server testbar (kein SSH-Ziel vorhanden) — siehe Nicht-testbar-Hinweis
  unten.
- [x] `secrets.DEPLOY_SSH_KEY` nur in einem `env:`-Block, nie direkt in
  einem `run:`-String interpoliert — getestet in
  `deploy-workflow-smoke.sh::Abschnitt 6` (drei sich ergänzende Prüfungen:
  Zählung der Vorkommen, Grep gegen `run: |`-Folgezeilen, AST-nahe
  Python-Prüfung über den gesamten mehrzeiligen `run:`-Block)

### Zusätzlich geprüft (über die Task-Akzeptanzkriterien hinaus, aus dem Auftrag)

- [x] YAML-Validität (`yaml.safe_load`) — Abschnitt 1
- [x] Trigger-`if`-Logik für `workflow_dispatch`, `workflow_run`+`success`,
  `workflow_run`+`failure`, `workflow_run`+`cancelled` — Abschnitt 5
- [x] Kontext-Ausdrücke `${{ ... }}` sind syntaktisch ausgeglichen
  (7 Vorkommen, 7 Schließungen) — Abschnitt 2
- [x] Bekannte, gültige Action-Referenzen `actions/checkout@v4` und
  `shivammathur/setup-php@v2`, identisch zur bereits produktiv laufenden
  `ci.yml` — Abschnitt 2

## Nicht testbar mit der aktuellen Implementierung / ohne echten Zielserver

- Live-SSH-Verbindungsaufbau, `ssh-keyscan` gegen einen echten Host,
  tatsächliches `touch $DEPLOY_PATH/.maintenance` auf einem Server — es
  existiert kein konfiguriertes `DEPLOY_HOST`/`DEPLOY_SSH_KEY` in diesem
  Repo. Nur strukturell/statisch geprüft (siehe oben).
- Environment-`production`-Approval-Gate (pausiert im GitHub-UI bis
  „Approve") — kann nicht lokal simuliert werden, da das GitHub Environment
  im echten Repo nicht angelegt ist (gehört zur Infra-Einrichtung, nicht zum
  Code).
- `actionlint`-Schema-Validierung — Tool ist in dieser Umgebung nicht
  installiert und wird laut Auftrag/`tasks.md` (Task 5.1) bewusst nicht
  installiert. Ersatzweise wurde Abschnitt 2 (manuelle/strukturelle
  Schema-Prüfung) durchgeführt.
- rsync mit Schutz-Excludes (3.4), Migrationen (3.5), Maintenance-aus /
  Key-Cleanup / Summary (3.6), Kopplungs-Guard (3.7) — existieren im
  aktuellen `deploy.yml` (Commit `f653e44`) noch nicht; nicht Teil des
  Test-Scopes T3.1-3.3.

## Ausführungs-Ergebnis

```
$ bash tests/deploy-workflow-smoke.sh

== 1. YAML-Validität ==
  ✓ deploy.yml: yaml.safe_load lädt fehlerfrei

== 2. GitHub-Actions-Schema (manuell/strukturell) ==
  ✓ on.workflow_run.workflows referenziert exakt ["CI"]
  ✓ on.workflow_dispatch mit inputs vorhanden
  ✓ workflow_dispatch.inputs.ref hat Default main
  ✓ concurrency: group=deploy-production, cancel-in-progress=false
  ✓ Job nutzt environment: production
  ✓ actions/checkout@v4 referenziert (bekannte, gültige Version)
  ✓ shivammathur/setup-php@v2 referenziert (bekannte, gültige Version)
  ✓ php-version ist "8.0" (identisch zu ci.yml)
  ✓ extensions-Liste enthält gd/zip/mysqli, kein pdo_mysql (identisch zu ci.yml)
  ✓ Kontext-Ausdrücke ${{ ... }} sind ausgeglichen (7 Stück)
  ✓ job.if enthält beide erwarteten Bedingungen

== 3. Build-Steps isoliert lokal simulieren (ohne SSH-Teil) ==
  ✓ bash build.sh (lokal) -> Exit 0
  ✓ alle fünf Kern-Dateien aus dem 'Kern-Dateien verifizieren'-Step sind nach build.sh vorhanden

== 4. Negativtest: 'Kern-Dateien verifizieren'-Step gegen unvollständiges dist/ ==
  ✓ extrahierter Step bricht mit Exit 1 ab, wenn scripts/migrate.php fehlt
  ✓ extrahierter Step meldet exakt die fehlende Datei (scripts/migrate.php)

== 5. Trigger-Logik (job.if) mit synthetischen Event-Payloads ==
  ✓ job.if: workflow_dispatch soll laufen (event_name=workflow_dispatch conclusion=None -> True (erwartet True))
  ✓ job.if: workflow_run+success soll laufen (event_name=workflow_run conclusion=success -> True (erwartet True))
  ✓ job.if: workflow_run+failure soll NICHT laufen (event_name=workflow_run conclusion=failure -> False (erwartet False))
  ✓ job.if: workflow_run+cancelled soll NICHT laufen (event_name=workflow_run conclusion=cancelled -> False (erwartet False))

== 6. Secrets-Handling: DEPLOY_SSH_KEY nur in env:-Block, nie direkt in run: ==
  ✓ secrets.DEPLOY_SSH_KEY kommt genau einmal vor, ausschließlich als env:-Zuweisung
  ✓ kein run:-Block interpoliert ${{ secrets.* }} direkt (Prüfung auf Zeilen nach 'run: |')
OK: keine secrets.*-Referenz innerhalb eines run:-Skriptblocks
  ✓ AST-nahe Prüfung: keine secrets.*-Referenz innerhalb eines mehrzeiligen run:-Blocks

==============================================================
Ergebnis: 23 bestanden, 0 fehlgeschlagen
==============================================================
```

### Ergänzende manuelle Prüfungen (nicht als Assert im Skript, aber ausgeführt)

```
$ grep -n "StrictHostKeyChecking" .github/workflows/deploy.yml
70:  ssh -i ~/.ssh/deploy_key -p "$DEPLOY_PORT" -o StrictHostKeyChecking=yes "$DEPLOY_USER@$DEPLOY_HOST" \
# -> genau ein Vorkommen, "yes", kein "no"/"accept-new"

$ grep -n "secrets\." .github/workflows/deploy.yml
30:      DEPLOY_HOST: ${{ secrets.DEPLOY_HOST }}
31:      DEPLOY_USER: ${{ secrets.DEPLOY_USER }}
32:      DEPLOY_PATH: ${{ secrets.DEPLOY_PATH }}
62:          DEPLOY_SSH_KEY: ${{ secrets.DEPLOY_SSH_KEY }}
# -> alle vier Secret-Referenzen ausschließlich in env:-Wertzuweisungen

$ python3 -c "import yaml,json; print(json.dumps(yaml.safe_load(open('.github/workflows/deploy.yml'))['jobs']['deploy']['steps'][0]['with'], default=str))"
{"ref": "${{ github.event_name == 'workflow_run'\n    && github.event.workflow_run.head_sha\n    || (github.event.inputs.ref || github.ref) }}"}
# -> entspricht wörtlich design.md D4
```

**Hinweis (kein Fehler, nur Beobachtung):** `yaml.safe_load` interpretiert den
YAML-Top-Level-Schlüssel `on:` gemäß YAML-1.1-Spezifikation als booleschen
Schlüssel `True` (klassischer PyYAML/GitHub-Actions-Fallstrick). Das ist
**kein Fehler in `deploy.yml`** — GitHub Actions selbst parst `on:` speziell
und ist von dieser YAML-1.1-Eigenheit nicht betroffen; dasselbe Verhalten
zeigt `ci.yml` bereits produktiv. Betrifft nur die Python-Dump-Darstellung
in diesem Report, nicht die Gültigkeit des Workflows.

## Fehler

Keine. Alle 23 automatisierten Prüfungen und alle ergänzenden manuellen
Prüfungen sind grün. Kein Akzeptanzkriterium der Tasks 3.1-3.3 wurde
verletzt.
