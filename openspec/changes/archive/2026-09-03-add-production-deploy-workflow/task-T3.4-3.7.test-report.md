# Test-Report: T3.4-3.7 (deploy.yml — rsync, Migration, Cleanup/Summary, Guard)

**Status:** alle-gruen

**Commit unter Test:** `d75cba4` (Branch `feature/add-production-deploy-workflow`)
**Datei unter Test:** `.github/workflows/deploy.yml`

Da kein echter Deploy-Zielserver existiert, wurden SSH/rsync **lokal simuliert**
(zwei Testverzeichnisse anstelle von Runner↔Server), der Guard-Step wurde als
extrahiertes Bash-Skript real ausgeführt, und die restlichen Anforderungen
wurden durch statische Analyse (`grep`, `awk`, `python3 -c "import yaml"`)
gegen die tatsächliche Workflow-Datei verifiziert.

**Hinweis zur rsync-Version:** Lokal ist `openrsync` (BSD/macOS-Kompatibilitätsschicht,
Protokoll 29) installiert, nicht GNU-rsync wie auf `ubuntu-latest`. Für die
getesteten Optionen (`-az`, `--delete`, `--exclude`, `-i`) ist das Verhalten
protokollkompatibel und für diesen Zweck ausreichend; feinere GNU-spezifische
Flag-Nuancen wurden nicht geprüft, da `deploy.yml` nur die genannten
Standardoptionen nutzt.

Alle Testverzeichnisse (`$SCRATCHPAD/rsync-test/`, `$SCRATCHPAD/guard-test/`)
sowie das durch `bash build.sh` erzeugte `dist/` wurden nach Testende
vollständig entfernt. `git status` im Projekt ist nach der Bereinigung sauber
(bis auf die vom Reviewer erzeugte `task-T3.4-3.7.review.md`, nicht von mir
angelegt).

---

## 1. rsync-Trockentest MIT Excludes (Positivtest)

### Aufbau

`bash build.sh` real ausgeführt → `dist/dog-mentality-test/` (1117 Dateien,
11 MB, Exit-Code 0).

Simuliertes Ziel `target-protected/` mit allen zwölf zu schützenden Pfaden
befüllt (Fake-Inhalte mit eindeutigem Text, damit ein Overwrite eindeutig
nachweisbar wäre):

1. `.maintenance`
2. `api/config.local.php` (Fake-DB-Credentials)
3. `uploads/irgendwas.jpg`
4. `logs/irgendwas.log`
5. `.git/HEAD` (Dummy-Datei in `.git/`-Verzeichnis)
6. `wizard/index.php` (Fake-Wizard-Inhalt) + `wizard/.lock` = `locked`
7. `irgendwas.zip`
8. `dist/nested/datei`
9. `php.ini`
10. `.user.ini`
11. `.htpasswd`
12. `.well-known/acme-challenge/token`

MD5-Manifest aller 13 Dateien vor dem Test erstellt.

### Exakter Befehl (aus deploy.yml, `-e ssh`-Teil entfernt)

```
rsync -az --delete \
  --exclude='.maintenance' --exclude='api/config.local.php' \
  --exclude='uploads/' --exclude='logs/' --exclude='.git' \
  --exclude='wizard/' --exclude='*.zip' --exclude='dist/' \
  --exclude='php.ini' --exclude='.user.ini' --exclude='.htpasswd' \
  --exclude='.well-known/' \
  dist/dog-mentality-test/ target-protected/
```

### Ergebnis

- **Exit-Code: 0.**
- **Alle 13 Prüfpunkte** (zwölf Pfade, `wizard/` mit zwei Dateien geprüft)
  existieren nach dem Lauf weiterhin.
- **MD5-Diff vor/nach:** `diff manifest-before manifest-after` → **identisch,
  keine Abweichung.** Keine der geschützten Dateien wurde angefasst.
- Zusätzlich mit `-i` (itemize) wiederholt: die itemisierte Änderungsliste
  (1286 Zeilen) enthält **keine** Zeile, die einen der zwölf Exclude-Patterns
  exakt trifft (präzise Regex mit Wortende/Pfadgrenze; zwei anfängliche
  Treffer waren False Positives durch ungenaue Regex — `php.ini.example`
  und `api/config.local.php.example`, beides legitime, zu übertragende
  Beispieldateien, keine der geschützten Laufzeitdateien).
- Keine `*deleting`-Zeile für einen geschützten Pfad.
- Normale Build-Dateien korrekt übertragen: `api/auth.php`,
  `frontend/index.html`, `vendor/autoload.php`, `scripts/migrate.php`,
  `index.php`, `.htaccess`, `composer.json`, `maintenance.html` — alle im
  Ziel vorhanden.
- Zweiter `--dry-run`-Lauf gegen dasselbe Ziel: keine weiteren Änderungen
  (idempotent).

**Fazit:** rsync mit den zwölf Excludes schützt exakt die spezifizierten
Pfade vollständig und verändert alles andere korrekt gemäß Build-Inhalt.

---

## 2. Negativ-Kontrolle OHNE Excludes

Frische Kopie desselben Ziel-Setups (`target-negative/`), MD5-Manifest vor
Test erstellt. Befehl: `rsync -az --delete dist/dog-mentality-test/ target-negative/`
(keine Excludes).

### Ergebnis

- Exit-Code 0 (rsync selbst meldet keinen Fehler — es löscht einfach).
- **11 der 13 Prüfpunkte wurden gelöscht:** `.maintenance`,
  `api/config.local.php`, `uploads/irgendwas.jpg`, `logs/irgendwas.log`,
  `.git/HEAD`, `irgendwas.zip`, `dist/nested/datei`, `php.ini`, `.user.ini`,
  `.htpasswd`, `.well-known/acme-challenge/token` — alle **weg** (per
  `--delete` entfernt, weil sie nicht in der Quelle liegen).
- **`wizard/` wurde nicht gelöscht, sondern überschrieben** (weil die Quelle
  ebenfalls ein `wizard/`-Verzeichnis enthält): `wizard/.lock` wechselte von
  `locked` auf `inactive`, `wizard/index.php` wechselte vom Fake-Inhalt zum
  echten Wizard-Code. Das ist exakt das in design.md D2b beschriebene Risiko
  — ein Deploy ohne `--exclude='wizard/'` würde den Web-Installer nach jedem
  Deploy entsperren (`WizardHelper::isLocked()`: `inactive` ⇒ „nicht
  gesperrt").
- Vollständiger MD5-Diff bestätigt: alle 13 ursprünglichen geschützten
  Dateien sind aus dem Manifest verschwunden bzw. durch andere Inhalte
  ersetzt; 1116 neue Dateien aus dem Build-Ordner sind stattdessen vorhanden.

**Fazit:** Der Unterschied zwischen Test 1 und Test 2 beweist eindeutig, dass
die Excludes ursächlich für den Schutz sind — ohne sie werden die
Laufzeitdaten zerstört bzw. der Wizard entsperrt.

---

## 3. Guard-Step lokal ausgeführt

### 3a. Gegen das echte, unveränderte Repository

Guard-Logik wortgetreu aus `deploy.yml` extrahiert (inkl. `set -e`, exakte
`grep`-Aufrufe) und im Projekt-Root ausgeführt:

```
PASS: ci.yml heisst CI
PASS: Exclude vorhanden: .maintenance
PASS: Exclude vorhanden: api/config.local.php
PASS: Exclude vorhanden: uploads/
PASS: Exclude vorhanden: logs/
PASS: Exclude vorhanden: .git
PASS: Exclude vorhanden: wizard/
PASS: Exclude vorhanden: *.zip
PASS: Exclude vorhanden: dist/
PASS: Exclude vorhanden: php.ini
PASS: Exclude vorhanden: .user.ini
PASS: Exclude vorhanden: .htpasswd
PASS: Exclude vorhanden: .well-known/
=== GESAMT-ERGEBNIS GUARD (echtes Repo): PASS (Exit 0) ===
```

Zusätzlich als eigenständiges Skript (`guard.sh`, identischer Inhalt) gegen
eine unveränderte Kopie von `ci.yml`/`deploy.yml` in einem Scratch-Verzeichnis
ausgeführt → `Guard: alle Checks bestanden.`, Exit-Code 0.

### 3b. Gegen mutierte Kopien (5 Szenarien, mehr als die geforderten 3)

Je eine Kopie von `ci.yml`/`deploy.yml` in eigenem Scratch-Unterverzeichnis,
ein Exclude bzw. der CI-Name gezielt entfernt/geändert:

| Mutation | Exit-Code | Fehlermeldung |
|---|---|---|
| `--exclude='wizard/'` entfernt | **1** | `::error::Schutz-Exclude fehlt in deploy.yml: wizard/` |
| `--exclude='api/config.local.php'` entfernt | **1** | `::error::Schutz-Exclude fehlt in deploy.yml: api/config.local.php` |
| `--exclude='.well-known/'` entfernt | **1** | `::error::Schutz-Exclude fehlt in deploy.yml: .well-known/` |
| `--exclude='*.zip'` entfernt | **1** | `::error::Schutz-Exclude fehlt in deploy.yml: *.zip` |
| `name: CI` in `ci.yml` zu `name: Continuous Integration` geändert | **1** | `::error::ci.yml heißt nicht mehr 'CI'` |

Alle fünf Mutationen erzeugen den erwarteten Exit-Code 1 mit einer passenden,
spezifischen Fehlermeldung.

**Fazit:** Guard-Step funktioniert exakt wie in D3/D8/tasks.md 3.7
spezifiziert — sowohl im Erfolgsfall (echtes Repo) als auch bei gezielten
Regressionen (fehlendes Exclude, Namens-Drift).

---

## 4. Migrations-Step-Semantik (statische Prüfung)

```yaml
- name: Migrationen ausführen
  id: migrate
  run: |
    ssh -i ~/.ssh/deploy_key -p "$DEPLOY_PORT" -o StrictHostKeyChecking=yes "$DEPLOY_USER@$DEPLOY_HOST" \
      "cd '$DEPLOY_PATH' && $DEPLOY_PHP_BIN scripts/migrate.php"
```

- `grep -n "continue-on-error" .github/workflows/deploy.yml` → **kein
  Treffer** im gesamten Workflow.
- `grep -n "|| true" .github/workflows/deploy.yml` → **kein Treffer**.
- Die einzigen `|| echo`-Fallbacks (Zeilen 101, 133) gehören zu „Wartungsmodus
  an"/„Wartungsmodus aus" (bewusst best-effort laut D7) — **nicht** zum
  Migrations-Step.
- Der Migrations-Step besteht aus genau einem `ssh`-Aufruf ohne
  Fehlerbehandlung. GitHub-Actions-Standardverhalten: Der Exit-Code des
  `run:`-Blocks ist der Exit-Code des letzten (einzigen) Kommandos; `ssh`
  gibt bei erfolgreicher Verbindung den Exit-Code des Remote-Kommandos
  zurück. Ein Exit-Code ≠ 0 von `scripts/migrate.php` (oder ein
  SSH-Verbindungsfehler) lässt damit den `run:`-Block und somit den Step
  fehlschlagen, was den Job als `failure` markiert — ohne jede
  Sonderbehandlung.

**Fazit:** Bestätigt durch Lesen — kein `|| true`/`continue-on-error`, Exit
≠ 0 lässt Step und Job rot werden.

---

## 5. `if: always()`-Steps (grep-Bestätigung)

```
grep -B2 "if: always()" .github/workflows/deploy.yml
```

Ergebnis (strukturiert via `awk`):

```
- name: Wartungsmodus aus
- name: SSH-Key entfernen
- name: Deployment summary
```

Alle drei geforderten Cleanup-Steps haben `if: always()`. Kein vierter Step
mit `if: always()` vorhanden, kein geforderter Step ohne `if: always()`.

---

## 6. YAML-Validität und Step-Reihenfolge vs. design.md D8

```
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))"
→ lädt fehlerfrei, keine Exception.
```

Step-Reihenfolge (`grep -n "^      - name:" .github/workflows/deploy.yml`)
Punkt für Punkt gegen D8 abgeglichen:

| D8 # | design.md D8 | tatsächlicher Step (Zeile) | Übereinstimmung |
|---|---|---|---|
| 1 | Checkout (exakter Commit) | `Checkout` (34) | ✅ |
| 2 | setup-php 8.0 | `PHP einrichten` (42) | ✅ |
| 3 | rsync sicherstellen | `rsync sicherstellen` (48) | ✅ |
| 4 | `bash build.sh` | `Build ausführen` (51) | ✅ |
| 5 | Build-Output verifizieren | `Kern-Dateien verifizieren` (54) | ✅ |
| 6 | Kopplungs-Guard (D3) | `Kopplungs-Guard (CI-Name + rsync-Excludes)` (60) | ✅ |
| 7 | SSH einrichten | `SSH einrichten` (87) | ✅ |
| 8 | Maintenance an (best-effort) | `Wartungsmodus an` (97) | ✅ |
| 9 | rsync -az --delete mit Excludes | `Dateien übertragen (rsync)` (103) | ✅ |
| 10 | Migrationen (D6) | `Migrationen ausführen` (122) | ✅ |
| 11 | Maintenance aus — `if: always()` | `Wartungsmodus aus` (128, `if: always()`) | ✅ |
| 12 | SSH-Key entfernen — `if: always()` | `SSH-Key entfernen` (135, `if: always()`) | ✅ |
| 13 | Deployment-Summary — `if: always()` | `Deployment summary` (139, `if: always()`) | ✅ |

**Fazit:** Alle 13 Steps sind vorhanden, in exakt der von D8 vorgegebenen
Reihenfolge, mit den korrekten `if:`-Bedingungen. Keine Abweichung.

---

## Akzeptanzkriterien-Abdeckung (Tasks 3.4–3.7 aus tasks.md)

### 3.4 rsync mit Schutz-Excludes
- [x] Alle zwölf Schutz-Excludes im Aufruf vorhanden — bestätigt per `grep`
  (Abschnitt 3a) und funktional per Positivtest (Abschnitt 1)
- [x] Kein `wizard/.lock`-Einzelexclude mehr — `grep -n "wizard/.lock"
  deploy.yml` liefert keinen Treffer (nur `wizard/` als Verzeichnis-Exclude)
- [x] Quelle ist `dist/dog-mentality-test/` (mit Trailing Slash) — Zeile 119
  `dist/dog-mentality-test/ \` bestätigt per Read
- [x] `--delete` aktiv — Zeile 105 `rsync -az --delete`, funktional durch
  Negativ-Kontrolle (Abschnitt 2) bestätigt

### 3.5 Migrationen per SSH
- [x] Migrations-Fehler macht den Job rot — Abschnitt 4 (statische Analyse,
  kein `|| true`/`continue-on-error`)
- [x] Nutzt `$DEPLOY_PHP_BIN` — Zeile 126: `$DEPLOY_PHP_BIN scripts/migrate.php`

### 3.6 Wartungsmodus aus, Key-Cleanup, Summary (`if: always()`)
- [x] Alle drei Steps haben `if: always()` — Abschnitt 5
- [x] Summary enthält die fünf geforderten Felder — per `Read` bestätigt:
  Auslöser (`DEPLOY_TRIGGER`), deployter Commit (`DEPLOY_REF`), Actor
  (`DEPLOY_ACTOR`), UTC-Zeit (`date -u`), Migrations-Ergebnis
  (`MIGRATION_OUTCOME` = `steps.migrate.outcome || 'übersprungen'`)

### 3.7 Kopplungs-Guard-Step
- [x] Umbenennen von `ci.yml` `name:` lässt den Guard fehlschlagen —
  Abschnitt 3b, Exit 1, korrekte Fehlermeldung
- [x] Entfernen eines beliebigen der zwölf Excludes (insb. `wizard/`) lässt
  den Guard fehlschlagen — Abschnitt 3b, 4 verschiedene Excludes getestet
  (`wizard/`, `api/config.local.php`, `.well-known/`, `*.zip`), alle Exit 1
  mit spezifischer Fehlermeldung

### Zusätzliche Spec-Scenarios (production-deployment/spec.md)
- [x] „Serverseitige config.local.php bleibt erhalten" — Abschnitt 1
- [x] „Wizard wird nicht mit-deployt" — Abschnitt 1 (Positiv) und Abschnitt 2
  (Negativ zeigt das Gegenteil-Risiko)
- [x] „Serverseitige PHP-Overrides bleiben erhalten" (`php.ini`, `.user.ini`) —
  Abschnitt 1
- [x] „Hoster-/ACME-Pfade bleiben erhalten" (`.htpasswd`, `.well-known/`) —
  Abschnitt 1
- [x] „Nutzer-Uploads bleiben erhalten" (`uploads/`, `logs/`) — Abschnitt 1
- [x] „Migration schlägt fehl → Deploy-Job rot, Maintenance-Aus läuft trotzdem"
  — Abschnitt 4 (Exit-Code-Propagation) + Abschnitt 5 (`if: always()` auf
  „Wartungsmodus aus")
- [x] „Guard erkennt Namens-Drift" / „Guard erkennt fehlendes Exclude" /
  „Guard erkennt fehlendes wizard/-Exclude" — Abschnitt 3b
- [ ] „Key wird immer entfernt" (End-to-End auf echtem Runner) — **nicht
  lokal vollständig testbar**, weil `~/.ssh/deploy_key` in der lokalen
  Simulation nie angelegt wurde (kein echter SSH-Schritt ausgeführt). Die
  `if: always()`-Bedingung des Steps selbst ist aber statisch bestätigt
  (Abschnitt 5); dass `rm -f ~/.ssh/deploy_key` als Kommando korrekt ist,
  wurde per `Read` verifiziert.
- [ ] „Lauf pausiert bis Approval" / „Ablehnung" (Environment-Gate) — **nicht
  lokal testbar**, da dies ausschließlich GitHub-UI-/Actions-Backend-Verhalten
  ist (Required Reviewer auf Environment `production`); kein lokales
  Äquivalent verfügbar. Das ist ohnehin Gegenstand von Task 5.1
  (Live-Verifikation), nicht 3.4–3.7.

---

## Ausführungs-Ergebnis (Zusammenfassung)

```
Test 1 (rsync MIT Excludes):        PASS  (Exit 0, 13/13 Pfade unverändert)
Test 2 (rsync OHNE Excludes):       PASS  (Negativkontrolle bestätigt: 11/13 gelöscht, wizard/ entsperrt)
Test 3a (Guard, echtes Repo):       PASS  (Exit 0)
Test 3b (Guard, 5 Mutationen):      PASS  (alle 5x Exit 1, korrekte Fehlermeldungen)
Test 4 (Migrations-Semantik):       PASS  (kein continue-on-error/|| true)
Test 5 (if: always() Grep):         PASS  (3/3 Steps)
Test 6 (YAML valide + Step-Order):  PASS  (13/13 Steps exakt gemäß D8)
```

## Fehler

Keine. Alle geprüften Verhaltensweisen entsprechen der Spezifikation
(design.md D8, specs/production-deployment/spec.md, tasks.md 3.4–3.7).

## Einschränkungen dieser Testmethode

- Kein echter alfahosting-Server vorhanden — SSH-Verbindungsaufbau,
  `ssh-keyscan`, tatsächliche Migration und das GitHub-Environment-Approval-
  Gate wurden **nicht** live getestet. Das ist laut Aufgabenstellung und
  `tasks.md` Task 5.1 vorbehalten.
- Lokales rsync ist `openrsync` (macOS), nicht GNU-rsync wie auf
  `ubuntu-latest`. Für `-az --delete --exclude` ist das Verhalten jedoch
  protokoll-äquivalent.
- Die Guard-Logik wurde als eigenständiges Skript mit identischem Inhalt
  ausgeführt (nicht innerhalb eines echten GitHub-Actions-Runners), da kein
  `act`/Actions-Runner-Simulator zur Verfügung stand. Der Bash-Code selbst
  ist aber wortgetreu aus `deploy.yml` übernommen.
