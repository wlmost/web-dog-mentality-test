# Abnahme: add-ci-workflow

**Status:** bereit-für-user-review

## Prüfgrundlage

- `openspec validate add-ci-workflow --strict` → `Change 'add-ci-workflow' is valid`.
- `openspec list` → `add-ci-workflow ✓ Complete` (10/10 Tasks in `tasks.md` als `[x]` markiert).
- `git diff main...feature/add-ci-workflow` gelesen (45 Dateien, u. a. `.github/workflows/ci.yml`,
  `.gitignore`, `composer.json`, `build.sh`, `composer.lock`).
- `openspec/changes/add-ci-workflow/task-ALL.review.md` (Reviewer, Gesamtempfehlung „ok“) und
  `task-ALL.test-report.md` (Tester, „alle-gruen“) gelesen.
- `gh run list -R wlmost/web-dog-mentality-test` → Run `33737502584` (push, `feature/add-ci-workflow`,
  Workflow `CI`) `conclusion: success`, nach dem Review-Fix (`permissions:`-Block).

## Erfüllt

- **Struktur:** `openspec validate --strict` läuft fehlerfrei durch.
- **Vollständigkeit:** Alle 10 Tasks (1.1, 1.2, 2.1, 2.2, 3.1–3.4, 4.1, 4.2) sind in `tasks.md` abgehakt.
- **Spec-Konformität:** Der Diff deckt sich mit `design.md`/`specs/continuous-integration/spec.md`:
  - `.github/workflows/ci.yml` trägt `name: CI`, Trigger `push: branches: ["**"]` + `pull_request`,
    Jobs `lint`, `composer` (`needs` keine), `build` (`needs: [composer]`), PHP 8.0 via
    `shivammathur/setup-php@v2`, Extensions `mbstring, ctype, gd, zip, mysqli, curl, json` wie in D9
    begründet, `actions/upload-artifact@v4` für `dist/dog-mentality-test`.
  - `composer.json` enthält jetzt `name`, `description`, `license: proprietary`, `type: project`;
    `config.platform.php` unverändert `"8.0"` (Diff geprüft).
  - `.gitignore` verliert nur die eine Zeile `composer.lock`; `vendor/` bleibt ignoriert (Diff geprüft,
    minimalinvasiv wie in D6/Review vermerkt).
  - `composer.lock` ist neu und eingecheckt (619 Zeilen im Diff).
  - `build.sh`: Farb-Helfer und Abschluss-Ausgaben auf `printf '%b'`-Stil umgestellt (kein `echo` ohne
    `-e` mit `\033`-Literalen mehr, Diff geprüft Zeilen 14-18, 207/213-Bereich); zusätzlich je ein
    harter `error`-Check für `api/auth.php`, `frontend/index.html`, `wizard/index.php` ergänzt
    (Gürtel-Teil der „Gürtel + Hosenträger“-Absicherung, die der `build`-Job extern spiegelt).
  - `api-contract.md` existiert weder im Arbeitsverzeichnis noch auf `main` oder dem Feature-Branch
    (war laut Session-Start-Snapshot nur ein untracktes Fremdartefakt; Ergebnis „Datei ist weg“ ist
    erfüllt, auch wenn es formal kein „staged removal“ im Git-Sinn gab, weil die Datei nie getrackt war).
  - `openspec/**` und `.claude/**` sind im Diff als neue, versionierte Dateien sichtbar; kein
    zusätzlicher `.gitignore`-Eintrag dafür.
- **Review-Befunde:** Keine „Muss“-Befunde. Der einzige sicherheitsrelevante „Sollte“-Befund
  (fehlender `permissions:`-Block, Least-Privilege für `GITHUB_TOKEN` bei öffentlichem Repo) ist
  umgesetzt — `ci.yml` enthält jetzt `permissions: contents: read` auf Workflow-Ebene, verifiziert
  durch den nach diesem Fix gelaufenen grünen Run `33737502584`. Die „Könnte“-Punkte (DRY der
  Extensions-Liste, fehlendes Composer-Caching, No-Op-`rsync`-Check) sind laut Reviewer explizit
  nicht blockierend und bewusst YAGNI für diesen schlanken Change.
- **Tests:** `tests/ci-workflow-smoke.sh` (21 Bash-Assertions) — laut Test-Report zweimal grün
  gelaufen, inkl. Idempotenz-Kontrolle. Ergänzend echte Verifikation gegen den GitHub-Actions-Lauf
  (Jobs `lint`/`composer`/`build` alle `success`, Artefakt `dog-mentality-test` real herunterladbar).
- **Dokumentierte, nicht blockierende Lücken** (bereits vom Reviewer/Tester benannt, hier bestätigt
  als nicht abnahmerelevant für diesen Change):
  - Fehlendes `CLAUDE.md`/`TESTING.md` im Projekt-Root — Prozess-Lücke, empfohlen vor dem nächsten
    Change (`add-production-deploy-workflow`) nachzuholen, kein Blocker für `add-ci-workflow` selbst.
  - `pull_request`-Trigger noch nie real ausgelöst (kein PR existiert) — schließt sich naturgemäß
    mit Schritt 14 (PR-Eröffnung); Empfehlung an den User unten.

## Offen / Nacharbeit

- Keine blockierenden Punkte identifiziert.
- Zur Vollständigkeit vor dem endgültigen Abschluss dieses Changes (nicht vor User-Gate 2, sondern
  vor Archivierung/Merge): Nach Öffnen des PRs (Schritt 14) `gh run list --json event` prüfen, ob ein
  Lauf mit `event: pull_request` grün erscheint — schließt die letzte in `test-report.md` benannte
  Verifikations-Lücke.
- Empfehlung, `CLAUDE.md`/`TESTING.md` als eigene kleine Aufgabe vor `add-production-deploy-workflow`
  einzuplanen (kein Task dieses Changes, daher hier nur als Empfehlung, nicht als offener Befund).

## Empfehlung an den User

Der Change ist inhaltlich vollständig, spec-konform und durch einen echten grünen CI-Lauf sowie 21
Test-Assertionen abgesichert; keine offenen „Muss“-Befunde. Freigabe zu Schritt 12 (User-Gate 2) wird
empfohlen; die einzige verbleibende Verifikation (`pull_request`-Trigger real feuern) erledigt sich
automatisch mit der PR-Eröffnung in Schritt 14.
