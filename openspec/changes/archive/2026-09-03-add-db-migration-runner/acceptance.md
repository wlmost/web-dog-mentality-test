# Abnahme: add-db-migration-runner

**Status:** bereit-für-user-review

## Prüfgrundlage

- `openspec validate add-db-migration-runner --strict` → `Change 'add-db-migration-runner' is valid`.
- Alle 5 Top-Level-Tasks (1.1, 2.1, 3.1, 3.2, 4.1) und ihre Akzeptanzkriterien in `tasks.md` sind `[x]`.
- `git diff main...feature/add-db-migration-runner` gelesen: `scripts/MigrationRunner.php`,
  `scripts/migrate.php`, `scripts/migrate-selftest.php`, `scripts/README.md`, `build.sh`,
  `tests/migration-runner-test.php`, `tests/support/migration-runner-child.php` — 7 Dateien,
  ausschließlich additiv (keine bestehende Datei außer `build.sh` verändert).
- Alle vier Task-Review- und Test-Report-Dateien im Change-Verzeichnis gelesen
  (`task-T1.1.*`, `task-T2.1.*`, `task-T3.1.*`, `task-T4.1.review.md`, `task-3.2.notes.md`).
- Commit-Historie (12 Commits, `feat`/`fix`/`test`/`docs`) zeigt für jede Task den vollständigen
  Zyklus Implementierung → Review/Test → Korrektur, wo nötig.
- Realer GitHub-Actions-Lauf auf `feature/add-db-migration-runner` (Run `33745548828`, nach dem
  letzten T4.1-Fix): `lint`, `composer`, `build` alle `success` — bestätigt insbesondere, dass die
  `build.sh`-Änderung den bestehenden CI-Workflow aus `add-ci-workflow` nicht bricht.

## Erfüllt

- **Struktur:** `openspec validate --strict` läuft fehlerfrei durch.
- **Vollständigkeit:** Alle 5 Tasks abgehakt, keine offene Task.
- **Spec-Konformität** (`specs/database-migrations/spec.md`, `design.md`):
  - `MigrationRunner` hat keine Abhängigkeit auf `wizard/`; `executeSqlFile`/`splitSqlStatements`
    übernehmen die Semantik von `WizardHelper` 1:1 (Reviewer T1.1 verifiziert, zeilengetreu).
  - Versionssortierung numerisch (nicht lexikografisch) — dediziert getestet (9 vs. 010).
  - `runPending` stoppt bei Fehler ohne Eintrag der fehlgeschlagenen Migration — verifiziert
    gegen echten `mysql:8.0`-Container (T1.1, T2.1, T3.1 Testreports).
  - `migrate.php` erfüllt die CLI-Schnittstelle aus design.md D6 exakt (`--dry-run`,
    Exit-Codes, `MIGRATE OK`/`MIGRATE FAIL`-Formate).
  - `migrate-selftest.php` deckt alle vier D8-Szenarien ab (Fresh-Install, Idempotenz,
    Teilzustand, isolierter Fehlerabbruch) und rührt `database/migrations/` nachweislich nicht an.
  - `build.sh` nimmt `scripts/MigrationRunner.php` + `scripts/migrate.php` explizit in die
    Deploy-Allowlist auf; `migrate-selftest.php`/`README.md` bewusst ausgeschlossen.
- **Muss-Befund behoben:** T1.1-Reviewer fand, dass `recordMigration()` einen fehlgeschlagenen
  `INSERT` unter PHP 8.0 (`MYSQLI_REPORT_OFF`) stillschweigend verschluckt hätte (Bruch der
  Idempotenz-Garantie). Fix (`runStatement()`-Helfer) wurde umgesetzt und per Regressionsnachweis
  gegen den unveränderten Alt-Code bestätigt (Alt-Code verschluckt den Fehler tatsächlich, neuer
  Code wirft zuverlässig in beiden mysqli-Report-Modi).
- **Design-Lücke sauber geschlossen:** `getAvailableMigrations()`/`runPending()` erhielten einen
  optionalen, 100 % abwärtskompatiblen `$migrationsDir`-Parameter, um T3.1s Isolationsanforderung
  zu erfüllen, ohne `database/migrations/` anzufassen — von Reviewer T3.1 als "sauber, verifiziert
  abwärtskompatibel" bestätigt.
- **Sollte-Befunde:** Alle sicherheits-/robustheitsrelevanten Punkte (fehlende Fehlerbehandlung bei
  `SHOW TABLES LIKE`, `0777`-Verzeichnis mit `DB_PASS` im Klartext, falsches Identifier-Escaping,
  stiller Env-Var-Override im Produktionspfad, fehlender `error()`-Hard-Fail für zwei Kern-Dateien
  in `build.sh`) wurden behoben und jeweils funktional gegen einen Docker-Container reverifiziert.
- **Bewusst zurückgestellte Punkte** (dokumentiert, kein Blocker):
  - Zwei T2.1-Sollte-Punkte (Tabellennamen-DRY, `MIGRATE FAIL`-Formatierung bei
    `recordMigration`-Fehlern) — hätten Änderungen an `MigrationRunner.php` außerhalb des
    Datei-Scopes der jeweiligen Korrektur erfordert; als Beobachtung dokumentiert.
  - SIGINT/SIGTERM-Cleanup-Lücke in `migrate-selftest.php` — akzeptierte Einschränkung für ein
    manuell ausgeführtes Entwickler-Tool außerhalb der automatisierten Pipeline.
  - T3.1-Tester-Befund: Selbsttest prüft Endzustand, nicht Anwendungsreihenfolge der Migrationen —
    kein Defekt, da die numerische Sortierlogik bereits in T1.1 dediziert (und bestehend) getestet
    ist; als Beobachtung für künftige Testvertiefung vermerkt.
  - Weiterhin kein `CLAUDE.md`/`TESTING.md` im Projekt-Root (bereits aus `add-ci-workflow` bekannte
    Lücke, seither nicht nachgeholt) — Empfehlung bleibt bestehen, vor `add-production-deploy-workflow`
    nachzuholen.
- **Tests:** `tests/migration-runner-test.php` (50 Assertions) + `scripts/migrate-selftest.php`
  (18 Assertions) — beide zuletzt grün gegen `mysql:8.0`-Docker-Container reproduziert
  (Task-3.2-Reproduktion protokolliert in `task-3.2.notes.md`).

## Offen / Nacharbeit

- Keine blockierenden Punkte identifiziert.
- Empfehlung, `CLAUDE.md`/`TESTING.md` als eigene kleine Aufgabe vor `add-production-deploy-workflow`
  einzuplanen (kein Task dieses Changes).

## Empfehlung an den User

Der Change ist vollständig, spec-konform, durch einen realen grünen CI-Lauf sowie 68 unabhängige
Test-Assertions (50 + 18) gegen einen echten MySQL-Container abgesichert. Der einzige während der
Implementierung gefundene Muss-Befund (Fehlerhärtung in `recordMigration`) wurde behoben und mit
Regressionsnachweis bestätigt. Freigabe zu Schritt 12 (User-Gate 2) wird empfohlen.
