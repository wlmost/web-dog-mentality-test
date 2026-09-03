# Abnahme: add-production-deploy-workflow

**Status:** bereit-für-user-review

## Prüfgrundlage

- `openspec validate add-production-deploy-workflow --strict` → `Change 'add-production-deploy-workflow' is valid`.
- 14 von 15 Top-Level-Tasks vollständig abgehakt (1.1–1.4, 2.1–2.2, 3.1–3.7, 4.1–4.2). Task 5.1 ist
  teilweise abgehakt: das lokal/strukturell prüfbare Akzeptanzkriterium (`actionlint`) ist erfüllt,
  die zwei verbleibenden Kriterien erfordern echte, außerhalb des Repos liegende Infrastruktur
  (siehe „Offen / Nacharbeit" unten).
- `git diff main...feature/add-production-deploy-workflow` gelesen: 10 Produktivdateien geändert/neu
  (`index.php`, `api/config.php`, `maintenance.html`, `.gitignore`, `build.sh`,
  `.github/workflows/deploy.yml`, `CI_CD.md`, `FTP_DEPLOYMENT.md`, `README.md`,
  `tests/deploy-workflow-smoke.sh`) — ausschließlich additiv bzw. minimal-invasiv, keine bestehende
  Kernlogik entfernt.
- Alle Review-/Test-Report-Dateien im Change-Verzeichnis gelesen (`task-T1.*`, `task-T3.1-3.2.notes.md`,
  `task-T3.1-3.3.*`, `task-T3.4-3.7.*`, `task-T4.notes.md`, `task-5.1.notes.md`).
- Realer GitHub-Actions-Lauf (`CI`, Run `33789173289`, auf diesem Branch) grün — bestätigt, dass die
  PHP-Änderungen (Wartungsmodus-Guards) keine Regression in `lint`/`composer`/`build` verursachen.
- `actionlint` (v1.7.12) gegen `deploy.yml` und `ci.yml`: keine Befunde.

## Erfüllt

- **Struktur:** `openspec validate --strict` läuft fehlerfrei durch.
- **Wartungsmodus (T1):** `.maintenance`-Guards in `index.php` (HTML/503) und `api/config.php`
  (JSON/503, nachweislich vor jeder DB-Verbindung) sind live gegen den PHP built-in Server verifiziert,
  inklusive eines Negativ-Tests (kaputte DB-Config + `.maintenance` → sauberes 503 statt DB-Timeout).
- **build.sh (T2):** `maintenance.html` wird ins Deploy-Paket aufgenommen; `DEPLOY_CHECKLIST.txt` nennt
  FTP nur noch als Fallback, nicht als einzigen Weg.
- **deploy.yml (T3), produktionskritischster Teil:**
  - Alle 13 Steps entsprechen exakt der in `design.md` D8 spezifizierten Reihenfolge (verifiziert nach
    einer Korrektur: der Kopplungs-Guard war zunächst fälschlich vor dem Build platziert und wurde beim
    Gegenlesen an die korrekte Position — nach Build-Verifikation, vor SSH-Setup — verschoben).
  - Secrets ausschließlich über `env:`-Blöcke, nie direkt in `run:`-Strings interpoliert.
  - `StrictHostKeyChecking=yes` konsequent, nirgends `no`/`accept-new`.
  - Checkout-`ref` nutzt `workflow_run.head_sha` (vermeidet TOCTOU zwischen CI-Erfolg und Deploy).
  - **Lokale rsync-Simulation gegen ein nachgebautes Produktions-Ziel** bestätigt: alle zwölf
    Schutz-Excludes (inkl. `wizard/` vollständig statt nur `wizard/.lock`) halten stand, byte-identisch
    erhalten. Eine Negativ-Kontrolle ohne Excludes zeigt konkret das Risiko, das D2b beschreibt:
    `wizard/.lock` würde entsperrt, `wizard/index.php` mit echtem Code überschrieben.
  - Migrations-Step ohne `|| true` — ein SSH-/Migrationsfehler lässt den Job rot werden.
  - Alle drei Cleanup-Steps (Wartungsmodus aus, SSH-Key entfernen, Summary) mit `if: always()`.
  - Kopplungs-Guard erkennt sowohl `ci.yml`-Namens-Drift als auch jedes fehlende Exclude-Pattern
    (gegen 5 mutierte Kopien getestet).
  - Ein DRY-Sollte-Befund (`DEPLOY_REF`-Ausdruck dupliziert) wurde behoben.
- **Dokumentation (T4):** `CI_CD.md` deckt alle geforderten Abschnitte (a)–(j) ab; Secret-/
  Variablennamen wurden aktiv gegen `deploy.yml` per `grep` abgeglichen (keine Namensdrift).
  Erstinbetriebnahme benennt explizit „erster Deploy ist rot ist erwartet". `FTP_DEPLOYMENT.md`/
  `README.md` erhielten reine Einfügungen (per Diff verifiziert), nichts Bestehendes wurde entfernt.
- **Muss-Befunde:** Keine. Jeder Review-Durchlauf (T1, T3.1-3.3, T3.4-3.7) endete mit „ok" bzw. wurde
  nach Sollte-Fixes wieder verifiziert grün.

## Offen / Nacharbeit

- **Task 5.1, zwei Akzeptanzkriterien:** Ein echter `workflow_dispatch`-Lauf bis zum Approval
  (pausierter „Waiting"-Status im Actions-UI, danach Steps in korrekter Reihenfolge) kann nicht durch
  einen Agenten hergestellt werden — er setzt voraus, dass der User selbst im GitHub-Repo das
  Environment `production` mit Required Reviewer sowie die vier Secrets (`DEPLOY_SSH_KEY`,
  `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`) einrichtet, und dass ein echter SSH-fähiger Zielserver
  (alfahosting) bereitsteht. Das ist in `CI_CD.md` Abschnitt (d) dokumentiert. Empfehlung: nach Merge
  und Secret-Einrichtung einen `workflow_dispatch`-Lauf durchführen und dabei bewusst die
  Erstinbetriebnahme-Sequenz aus Abschnitt (f) erwarten (erster Lauf ist planmäßig rot).
- Kein `CLAUDE.md`/`TESTING.md` im Projekt-Root — seit `add-ci-workflow` bekannte, nicht
  behobene Lücke.

## Empfehlung an den User

Der Change ist innerhalb dessen, was ohne echten Zielserver und ohne Zugriff auf GitHub-Repo-Settings
verifizierbar ist, vollständig, spec-konform und mit besonderer Sorgfalt gegen das höchste Risiko
dieser gesamten Pipeline (`rsync --delete` gegen Produktionsdaten) abgesichert — inklusive einer
lokalen Simulation, die das D2b-Risiko konkret nachweist, sowie einer beim Review gefundenen und
korrigierten Abweichung von der spezifizierten Step-Reihenfolge. Freigabe zu Schritt 12 (User-Gate 2)
wird empfohlen, mit dem klaren Vermerk, dass die Erstinbetriebnahme (Environment-Setup, Secrets,
erster – erwartungsgemäß roter – Deploy) ein bewusster manueller Folgeschritt außerhalb dieses Changes
bleibt.
