# Test-Report: T3.1 — `scripts/migrate-selftest.php`

**Status:** alle-gruen

**Rolle des Testers hier:** T3.1 liefert selbst ein Testskript
(`scripts/migrate-selftest.php`), keine PHPUnit-Tests im klassischen Sinn.
Es wurden keine neuen Testdateien hinzugefügt — die Aufgabe des Testers war,
den Selbsttest sowie die bestehende Regressionssuite (T1.1) unabhängig vom
Entwickler-Bericht gegen einen frischen `mysql:8.0`-Docker-Container
nachzuvollziehen, die Isolationsanforderung (`database/migrations/` bleibt
unangetastet) explizit zu verifizieren und eine eigene, andersartige
Sabotage gegen den Runner durchzuführen.

## Hinzugefügte / geänderte Tests

Keine — bestehende Skripte wurden ausschließlich ausgeführt/verifiziert:

- `scripts/migrate-selftest.php` (T3.1, neu, vom Entwickler geliefert)
- `tests/migration-runner-test.php` (T1.1, bestehend, 50 Assertions,
  Regressionslauf)
- `scripts/migrate.php` (T2.1, funktionaler Regressions-Check von Hand,
  siehe unten)

Produktivcode wurde während der Verifikation temporär sabotiert (Schritt 5,
siehe unten) und danach vollständig auf den Commit-Stand zurückgesetzt
(verifiziert per `git diff`/`git status` — keine Restspuren).

## Akzeptanzkriterien-Abdeckung (Task 3.1, `tasks.md`)

- [x] `php -l scripts/migrate-selftest.php` ist fehlerfrei — verifiziert
  (`No syntax errors detected`).
- [x] Gegen eine leere lokale MySQL/MariaDB (Docker) läuft der Selbsttest mit
  Exit 0 durch — verifiziert gegen einen frisch gestarteten `mysql:8.0`-
  Container: 18/18 Assertions bestanden, `EXIT CODE: 0`.
- [x] Bei absichtlich gebrochenem Runner meldet der Selbsttest Exit 1 —
  verifiziert mit einer **eigenen, vom Entwickler-Sabotage-Test
  unabhängigen** Sabotage (Details unten): Exit 1 (via
  `tests/migration-runner-test.php`, s. Einschränkung unten für
  `migrate-selftest.php` selbst).

Zusätzlich (aus dem Auftrag, nicht Teil der ursprünglichen Task-Akzeptanz,
aber explizit angefordert):

- [x] `php -l scripts/MigrationRunner.php` / `php -l scripts/migrate.php`
  fehlerfrei.
- [x] `git status`/`git diff` zeigen nach einem Selbsttest-Lauf **keine**
  Änderungen an `database/migrations/` (Isolationsanforderung) —
  explizit verifiziert.
- [x] `tests/migration-runner-test.php` (T1.1, 50 Assertions) bleibt nach
  der Signatur-Erweiterung an `MigrationRunner.php` vollständig grün.
- [x] Funktionaler Regressionscheck von `scripts/migrate.php` (T2.1:
  Dry-Run, Fresh-Install, Idempotenz, fehlende Konfigurationsdatei) zeigt
  unverändertes Verhalten.

## Ausführungs-Ergebnis

### 1. `php -l`

```
No syntax errors detected in scripts/migrate-selftest.php
No syntax errors detected in scripts/MigrationRunner.php
No syntax errors detected in scripts/migrate.php
```

### 2. Docker-Setup

```
docker run -d --name migrate-selftest-mysql-tester -e MYSQL_ROOT_PASSWORD=root -p 33063:3306 mysql:8.0
MySQL ready after 2 attempts (mysqladmin ping)
```

Eigener, frischer Container (Name/Port unabhängig vom Entwickler-Lauf),
danach wieder entfernt (`docker rm -f migrate-selftest-mysql-tester`).

### 3. `scripts/migrate-selftest.php` gegen frische DB (unveränderter Code)

```
MIGRATE_TEST_HOST=127.0.0.1 MIGRATE_TEST_PORT=33063 MIGRATE_TEST_USER=root MIGRATE_TEST_PASS=root php scripts/migrate-selftest.php
...
== Szenario 1: Frische Datenbank -> alle Migrationen werden angewendet ==
  ✓ Erster Lauf (frische DB) endet mit Exit-Code 0
  ✓ schema_migrations enthaelt nach dem ersten Lauf die Versionen 001-005
  ✓ Ausgabe meldet "5 angewendet, 0 übersprungen"
== Szenario 2: Zweiter Lauf ist wirkungslos (Idempotenz) ==
  ✓ Zweiter Lauf endet mit Exit-Code 0
  ✓ Ausgabe meldet "0 angewendet, 5 übersprungen"
  ✓ schema_migrations ist nach dem zweiten Lauf unveraendert (001-005)
== Szenario 3: Teilzustand nach DELETE der letzten Version ==
  ✓ Dritter Lauf endet mit Exit-Code 0
  ✓ Ausgabe meldet "1 angewendet, 4 übersprungen"
== Szenario 4: Absichtlich kaputte Zusatzmigration (isolierter Test-Temp-Pfad) ==
  ✓ Lauf mit kaputter Zusatzmigration endet mit Exit-Code 1
  ✓ Ausgabe nennt die fehlgeschlagene Version 999
  ✓ Die kaputte Migration 999 wird NICHT in schema_migrations eingetragen
  ✓ Die zuvor erfolgreichen Migrationen 001-005 bleiben unveraendert eingetragen
==============================================================
Ergebnis: 18 bestanden, 0 fehlgeschlagen
==============================================================
EXIT CODE: 0
```

Deckt sich mit dem Entwickler-Bericht (18/18, Exit 0).

### 4. Isolationsverifikation — `database/migrations/` unangetastet

```
$ git status --porcelain    # VOR dem Lauf: leer
$ git status --porcelain    # NACH dem Lauf: leer
$ git diff -- database/migrations/   # keine Ausgabe, Exit-Code 0 (keine Änderung)
$ ls /tmp | grep migrate-selftest              -> keine Treffer
$ find "$TMPDIR" -maxdepth 1 -iname "*migrate-selftest*"  -> keine Treffer
$ docker exec ... mysql -e "SHOW DATABASES LIKE 'migrate_selftest';"  -> leer (DB wurde per Shutdown-Hook gedroppt)
```

Isolationsanforderung bestätigt: weder `database/migrations/` noch
sonstige Projektdateien wurden verändert, keine liegen gebliebenen
Temp-Verzeichnisse, die Wegwerf-Datenbank wurde zuverlässig aufgeräumt.

### 5. Regressionslauf `tests/migration-runner-test.php` (T1.1)

```
MR_TEST_HOST=127.0.0.1 MR_TEST_PORT=33063 MR_TEST_USER=root MR_TEST_PASS=root php tests/migration-runner-test.php
...
Ergebnis: 50 bestanden, 0 fehlgeschlagen
EXIT CODE: 0
```

Bestätigt: die abwärtskompatible Signaturerweiterung
(`getAvailableMigrations(?string $migrationsDir = null)`,
`runPending(mysqli $conn, string $prefix, ?string $migrationsDir = null)`)
hat keine Regression an den bestehenden Aufrufern (Gruppen A–D, alle rufen
ohne den neuen Parameter auf) verursacht.

### 6. Funktionaler Regressionscheck `scripts/migrate.php` (T2.1)

Gegen denselben Container, eigene Wegwerf-DB (`migrate_functional_check`,
Präfix `fchk_`):

```
--dry-run vor Schema-Installation  -> "Ausstehende Migrationen: 001, 002, 003, 004, 005", Exit 0
--dry-run nach Schema-Installation -> unverändert, Exit 0 (keine DB-Änderung durch Dry-Run)
Fresh-Install-Lauf                 -> "MIGRATE OK: 5 angewendet, 0 übersprungen", Exit 0
Zweiter Lauf (Idempotenz)          -> "MIGRATE OK: 0 angewendet, 5 übersprungen", Exit 0
Aufruf mit nicht existierendem Konfigurationspfad
                                    -> "MIGRATE FAIL: Konfigurationsdatei nicht gefunden: ...", Exit 1
```

Kein abweichendes Verhalten gegenüber dem in `task-T2.1.notes.md`
dokumentierten Stand — die T3.1-Signaturerweiterung ist tatsächlich rein
additiv.

### 7. Eigene Sabotage (unabhängig von der Entwickler-Sabotage)

Der Entwickler hat in `scripts/migrate.php` nach `runPending()`
`$result['failedVersion'] = null;` gesetzt (simuliert einen Runner, der
einen Fehler verschluckt). Um eine unabhängige Bestätigung zu liefern,
wurde stattdessen **`scripts/MigrationRunner.php`** an anderer Stelle
sabotiert: der `usort()`-Comparator in `getAvailableMigrations()` wurde
von aufsteigender auf absteigende numerische Sortierung umgedreht
(`(int)getMigrationVersion($b) <=> (int)getMigrationVersion($a)` statt
`$a <=> $b`).

**Ergebnis der Gegenprobe — mit einer wichtigen Einschränkung:**

- `tests/migration-runner-test.php` (T1.1) erkennt die Sabotage zuverlässig:
  **19 von 50 Assertions schlagen fehl**, Exit-Code **1** (u. a.
  „liefert 001,002,9,010 in numerischer Reihenfolge" und „Alle fünf realen
  Migrationen (001-005) werden in numerischer Reihenfolge angewendet").
- **`scripts/migrate-selftest.php` (T3.1, Gegenstand dieser Task) erkennt
  dieselbe Sabotage NICHT**: Lauf gegen dieselbe sabotierte
  `MigrationRunner.php` ergibt weiterhin **18 von 18 Assertions bestanden,
  Exit-Code 0**.

Ursache (Codeanalyse, keine Vermutung): Die vier D8-Szenarien in
`migrate-selftest.php` prüfen nur den **Endzustand** (Menge der
eingetragenen Versionen, Zähler „angewendet/übersprungen", Exit-Code),
nicht die tatsächliche **Anwendungsreihenfolge**. Die fünf realen
Migrationen 001–005 sind inhaltlich voneinander unabhängige Guards (jede
prüft `information_schema` selbst, bevor sie etwas ändert) — eine
umgekehrte Ausführungsreihenfolge führt bei ihnen zu keinem SQL-Fehler und
zu keiner abweichenden Endmenge in `schema_migrations` (weil
`getAppliedVersions()` ohnehin `ORDER BY version` verwendet). Auch
Szenario 4 bleibt unberührt: da 001–005 zu diesem Zeitpunkt bereits
eingetragen sind, werden sie unabhängig von der Sortierreihenfolge
übersprungen; nur die neue Version `999` wird ausgeführt und schlägt
erwartungsgemäß fehl — unabhängig davon, ob sie zuerst oder zuletzt
„dran" wäre.

Nach der Sabotage wurde `scripts/MigrationRunner.php` wieder exakt auf den
Commit-Stand zurückgesetzt (Diff der Wiederherstellung gegen die
Originaldatei geprüft — identisch) und die volle Kette erneut grün
gefahren: `php -l` fehlerfrei, `migrate-selftest.php` 18/18 (Exit 0),
`migration-runner-test.php` 50/50 (Exit 0), `git status`/`git diff`
komplett leer.

## Bewertung des Akzeptanzkriteriums „Bei absichtlich gebrochenem Runner meldet der Selbsttest Exit 1"

Formal **erfüllt**: sowohl der Entwickler-Sabotage-Test (Verschlucken des
Fehlercodes in `migrate.php`) als auch das ursprünglich intendierte
Szenario 4 (kaputte SQL-Zusatzmigration, im normalen 18/18-Lauf bereits
mitgetestet) lösen zuverlässig Exit 1 aus. Das Kriterium verlangt nicht,
dass *jede denkbare* Sabotageform erkannt wird.

**Aber:** Meine unabhängige Gegenprobe zeigt eine reale Lücke in der
*Aussagekraft* des Selbsttests gegenüber der Spezifikation. Die Spec
(`specs/database-migrations/spec.md`, Requirement „Idempotente Anwendung
in numerischer Reihenfolge", Scenario „Sortierung ist numerisch, nicht
lexikografisch") fordert explizit numerische Sortierung — diese
Eigenschaft wird aktuell **nur** von `tests/migration-runner-test.php`
(mit synthetischen Dateien `001`, `002`, `9`, `010`) geprüft, **nicht**
vom D8-Selbsttest in `scripts/migrate-selftest.php`, obwohl Letzterer
laut Proposal/Design „unabhängig vom Deploy testbar" sein soll und mit
echten Migrationsdateien arbeitet. Das ist kein Fehlschlag der
Akzeptanzkriterien von Task 3.1 und erfordert **keine** Korrektur am
Produktivcode — ich notiere es hier als Beobachtung für Review/Architekt,
da es die Duplikation der Sortier-Prüfung zwischen T1.1 und T3.1 betrifft
(die reale Absicherung existiert bereits in T1.1; T3.1 deckt sie nur
zufällig durch die verwendeten, ordnungsunabhängigen Migrationsdateien
nicht ab).

## Fehler (falls vorhanden)

Keine. Alle drei Akzeptanzkriterien von Task 3.1 sind erfüllt, keine
Regression in T1.1/T2.1 durch die Signaturerweiterung, Isolationsanforderung
bestätigt. Die oben dokumentierte Beobachtung zur Sortier-Erkennungslücke im
D8-Selbsttest ist **kein Testfehlschlag**, sondern ein Hinweis auf
begrenzte Testtiefe an dieser einen Stelle (durch T1.1 bereits abgedeckt).
