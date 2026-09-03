# Notes: Tasks 3.4–3.7 (deploy.yml — rsync, Migration, Cleanup/Summary, Guard)

## Umfang

Ergänzt `.github/workflows/deploy.yml` (vorhanden mit Tasks 3.1–3.3: Grundgerüst,
Build-Steps, SSH-Setup, „Wartungsmodus an") um:

- **3.4** Step „Dateien übertragen (rsync)"
- **3.5** Step „Migrationen ausführen" (`id: migrate`)
- **3.6** Steps „Wartungsmodus aus", „SSH-Key entfernen", „Deployment summary"
  (alle `if: always()`)
- **3.7** Step „Kopplungs-Guard (CI-Name + rsync-Excludes)"

Datei: `.github/workflows/deploy.yml`

## Task 3.4 — rsync mit Schutz-Excludes

Exakter Wortlaut der zwölf `--exclude`-Muster (unverändert aus design.md D2
übernommen):

```
--exclude='.maintenance'
--exclude='api/config.local.php'
--exclude='uploads/'
--exclude='logs/'
--exclude='.git'
--exclude='wizard/'
--exclude='*.zip'
--exclude='dist/'
--exclude='php.ini'
--exclude='.user.ini'
--exclude='.htpasswd'
--exclude='.well-known/'
```

`-e "ssh -i ~/.ssh/deploy_key -p $DEPLOY_PORT -o StrictHostKeyChecking=yes"`,
Quelle `dist/dog-mentality-test/` (mit Trailing Slash), Ziel
`"$DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH/"`. `wizard/` ist vollständig
ausgeschlossen (D2b), kein `wizard/.lock`-Einzelexclude mehr vorhanden.

## Task 3.5 — Migrationen per SSH

Step direkt nach rsync, `id: migrate` (wird von Task 3.6 für das
Migrations-Ergebnis in der Summary referenziert):

```
ssh -i ~/.ssh/deploy_key -p "$DEPLOY_PORT" -o StrictHostKeyChecking=yes "$DEPLOY_USER@$DEPLOY_HOST" \
  "cd '$DEPLOY_PATH' && $DEPLOY_PHP_BIN scripts/migrate.php"
```

Kein `|| true` — ein Exit ≠ 0 lässt den Step und damit den Job fehlschlagen
(Standard-GHA-Verhalten ohne `continue-on-error`).

## Task 3.6 — Wartungsmodus aus, Key-Cleanup, Summary

Drei Steps, alle mit `if: always()`:

1. „Wartungsmodus aus": `ssh … "rm -f '$DEPLOY_PATH/.maintenance'" || echo "::warning::…"`
   (best-effort, analog zu „Wartungsmodus an").
2. „SSH-Key entfernen": `rm -f ~/.ssh/deploy_key`.
3. „Deployment summary": schreibt eine Markdown-Tabelle nach
   `$GITHUB_STEP_SUMMARY` mit den fünf geforderten Feldern:
   - Auslöser: `${{ github.event_name }}`
   - Deployter Commit: derselbe Ausdruck wie im Checkout-Step
     (`workflow_run.head_sha` bzw. `inputs.ref`/`github.ref`)
   - Actor: `${{ github.actor }}`
   - Zeitpunkt (UTC): `date -u '+%Y-%m-%d %H:%M:%S UTC'` zur Laufzeit des Steps
   - Migrations-Ergebnis: `${{ steps.migrate.outcome || 'übersprungen' }}` —
     `success`/`failure`, oder `übersprungen`, falls der Migrations-Step wegen
     eines vorherigen Fehlers (z. B. rsync) gar nicht erst lief.

Die vier dynamischen Werte werden über `env:` in den Summary-Step injiziert
(keine `${{ }}`-Interpolation direkt im `run:`-Skript), analog zum bestehenden
Muster mit `DEPLOY_SSH_KEY` in Task 3.3.

## Task 3.7 — Kopplungs-Guard-Step

**Korrektur nach Erstimplementierung:** Der ursprüngliche Implementierungs-
Auftrag dieser Session enthielt einen Fehler und verlangte fälschlich „Früher
Step, NACH Checkout, VOR dem Build ('PHP einrichten'-Step)". Das widersprach
sowohl design.md D8 (Schritt 6: nach der Build-Verifikation, vor SSH-Setup)
als auch tasks.md 3.7 selbst. Nach Implementierung wurde die Abweichung beim
Gegenlesen bemerkt und der Step an die in design.md D8 spezifizierte Position
verschoben: jetzt nach „Kern-Dateien verifizieren" (Schritt 5) und vor „SSH
einrichten" (Schritt 7) — die vollständige Steps-Reihenfolge entspricht damit
wieder exakt den 13 in D8 nummerierten Schritten (verifiziert per
`grep -n "^      - name:"`).

Guard-Logik:

```bash
set -e
grep -q '^name: CI$' .github/workflows/ci.yml \
  || { echo "::error::ci.yml heißt nicht mehr 'CI'"; exit 1; }

excludes=( ".maintenance" "api/config.local.php" "uploads/" "logs/" ".git"
           "wizard/" "*.zip" "dist/" "php.ini" ".user.ini" ".htpasswd"
           ".well-known/" )

for pattern in "${excludes[@]}"; do
  grep -qF -- "exclude='$pattern'" .github/workflows/deploy.yml \
    || { echo "::error::Schutz-Exclude fehlt in deploy.yml: $pattern"; exit 1; }
done
```

Der Step prüft `.github/workflows/deploy.yml` gegen sich selbst — das
funktioniert, weil `actions/checkout` die komplette Datei (alle Steps
inklusive der später folgenden rsync-Excludes) bereits vor Ausführung des
ersten Steps auf den Runner kopiert; der Guard liest die Datei als Text, nicht
den aktuell laufenden Step.

**Abweichung von der wörtlichen Vorlage:** `grep -q "exclude='<muster>'"` aus
tasks.md/Auftrag wurde zu `grep -qF -- "exclude='$pattern'"` präzisiert
(`-F` = Fixed-String statt Basic-Regex). Grund: einige Muster enthalten
Regex-Metazeichen (`.` in `.maintenance`, `.git` etc.; `*` in `*.zip`), die in
einer BRE ungewollte Bedeutung hätten (z. B. `'*` = „null oder mehr `'`").
`-F` erzwingt exakte Literal-Suche und ist damit robuster; das Ergebnis
(Erfolg bei vorhandenem Exclude, Fehlschlag bei fehlendem) ist identisch zur
Spezifikation.

## Verifikation durchgeführt

- `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))"`
  → lädt fehlerfrei.
- `grep -qF -- "exclude='<muster>'" .github/workflows/deploy.yml` für alle
  zwölf Muster einzeln geprüft → alle vorhanden.
- `grep -n "wizard/.lock" .github/workflows/deploy.yml` → kein Treffer (kein
  Einzelexclude mehr).
- `grep -n "secrets\." .github/workflows/deploy.yml` → alle Treffer liegen in
  `env:`-Blöcken (`DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`,
  `DEPLOY_SSH_KEY`), keine direkte Interpolation in `run:`-Strings.
- Guard-Logik lokal simuliert (Kopien in Scratch-Verzeichnis, echte Dateien
  unangetastet):
  - `ci.yml`-Kopie mit geändertem `name:` → `grep -q '^name: CI$'` matcht
    nicht mehr → Guard würde mit `exit 1` fehlschlagen. Bestätigt.
  - `deploy.yml`-Kopie ohne `--exclude='wizard/'`-Zeile → Fixed-String-Grep
    matcht nicht mehr → Guard würde mit `exit 1` fehlschlagen. Bestätigt.
  - Guard-Skript gegen die echte, unveränderte `deploy.yml`/`ci.yml`
    ausgeführt → läuft vollständig durch (Exit 0).
- Kein Ziel-Server vorhanden — SSH/rsync/Migrations-Steps selbst wurden nicht
  live gegen alfahosting getestet (das ist Task 5.1 / Erstinbetriebnahme,
  außerhalb dieses Task-Blocks).

## Nicht angefasst

- Tasks 3.1–3.3 (bereits vorhanden, nur gelesen).
- `permissions:` (`contents: read`) unverändert, keine zusätzlichen
  Berechtigungen benötigt (kein `actions:`/`checks:`-Zugriff nötig für diese
  Steps).
- Tasks 4.x (`CI_CD.md`, `FTP_DEPLOYMENT.md`) und 5.x (Verifikation) — nicht
  Teil dieses Auftrags.
