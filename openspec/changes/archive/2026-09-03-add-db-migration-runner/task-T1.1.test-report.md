# Test-Report: T1.1 — `scripts/MigrationRunner.php`

**Status:** alle-gruen

## Hinzugefügte / geänderte Tests

- `tests/migration-runner-test.php` (neu): Standalone-PHP-Assertions-Skript
  (kein PHPUnit — bewusster Non-Goal aus `design.md`, analog zu
  `tests/ci-workflow-smoke.sh`). 50 Assertions in 9 thematischen Gruppen.
  Führt jede logische Testgruppe in einem eigenen PHP-Subprozess aus (siehe
  Architektur-Hinweis im Datei-Kopfkommentar), weil PHP nicht zulässt, zwei
  unterschiedliche Dateien mit jeweils `class MigrationRunner` im selben
  Prozess zu laden (Redeclare-Fehler) — nötig, weil sowohl die echte
  `scripts/MigrationRunner.php` (gegen die realen `database/migrations/*.sql`)
  als auch isolierte Kopien mit synthetischen Migrationsdateien (gegen einen
  hartkodierten `__DIR__`-relativen Glob-Pfad) im selben Testlauf gebraucht
  werden.
- `tests/support/migration-runner-child.php` (neu): generischer,
  assertionsfreier Subprozess-Runner, der eine JSON-Liste von Operationen
  gegen eine geladene `MigrationRunner`-Instanz ausführt und das Ergebnis als
  JSON zurückgibt. Wird ausschließlich von `tests/migration-runner-test.php`
  aufgerufen.

Beide Dateien wurden neu erstellt; `scripts/MigrationRunner.php` selbst wurde
**nicht** verändert.

## Vorgehen / Infrastruktur

- Kurzlebiger Docker-Container `mysql:8.0` (Port 33062, `MYSQL_ROOT_PASSWORD=root`),
  vor Testlauf gestartet, nach Abschluss mit `docker rm -f` entfernt (kein
  Container hinterlassen, wie in den Notes des Entwicklers gefordert).
- Das Testskript legt ausschließlich eigene Wegwerf-Datenbanken an
  (`mrtest_group_a`, `mrtest_group_b`, `mrtest_group_c_full_schema`,
  `mrtest_group_d_partial_schema`) und löscht sie nach jeder Gruppe wieder
  (verifiziert: `SHOW DATABASES` nach Testlauf enthält keine `mrtest_*`-DB
  mehr). Temporäre Verzeichnisse/Dateien unter `sys_get_temp_dir()` werden per
  `register_shutdown_function` aufgeräumt (verifiziert: keine `mr-*`-Reste).
- Zweifacher Testlauf zur Prüfung der Wiederholbarkeit — beide Male
  `Ergebnis: 50 bestanden, 0 fehlgeschlagen`, Exit-Code 0.
- Eigene, unabhängige Verifikation (nicht aus den Notes des Entwicklers
  übernommen): Quellcode von `MigrationRunner.php` und die relevanten Zeilen
  aus `wizard/WizardHelper.php`, `wizard/install.php`, `database/schema.sql`,
  `database/schema-auth.sql` sowie alle fünf `database/migrations/*.sql`
  selbst gelesen, bevor die Tests geschrieben wurden.

## Befund zum vom Entwickler gemeldeten Fehlschlag bei Migration 003

**Widerlegt als MigrationRunner-Defekt, bestätigt als Test-Setup-Fehler des
Entwicklers.**

- `database/schema-auth.sql:8` definiert `{{PREFIX}}auth_users` (inkl. Kommentar
  „Tabelle: auth_users"); `database/schema.sql` definiert dagegen nur
  `{{PREFIX}}users` (Zeile 113) — eine andere Tabelle.
- `wizard/install.php:81-84` lädt beim echten Install-Schritt **beide**
  Dateien in der Reihenfolge `schema.sql` → `schema-auth.sql`, bevor
  Migrationen angewendet werden.
- `database/migrations/003_add_avatar_to_users.sql` referenziert explizit
  `{{PREFIX}}auth_users` und enthält sogar den Kommentar „Hinweis: Spalte ist
  bereits in schema-auth.sql enthalten; dieser Guard verhindert den Fehler bei
  Neuinstallationen."
- **Eigener Test (Gruppe C):** `schema.sql` + `schema-auth.sql` in dieser
  Reihenfolge über `MigrationRunner::executeSqlFile()` eingespielt, danach
  `runPending()` gegen die echten Dateien `database/migrations/001-005.sql`
  aufgerufen → alle fünf Migrationen (inkl. 003) laufen fehlerfrei durch,
  `failedVersion` ist `null`, `{{PREFIX}}auth_users.avatar` existiert danach.
- **Eigene Gegenprobe (Gruppe D):** nur `schema.sql` geladen (ohne
  `schema-auth.sql`), dieselben Migrationen ausgeführt → Migration 003
  schlägt exakt wie vom Entwickler beschrieben fehl (`failedVersion === '003'`,
  Fehlertext enthält `auth_users`), 001/002 laufen trotzdem durch, 003 wird
  **nicht** eingetragen.
- **Schlussfolgerung:** Der vom Entwickler beobachtete Fehlschlag lag daran,
  dass im eigenen funktionalen Testlauf nur `database/schema.sql` geladen
  wurde statt aller benötigten Schema-Dateien. `MigrationRunner.php` verhält
  sich in beiden Fällen korrekt (wendet an, wenn die Voraussetzung erfüllt
  ist; bricht kontrolliert ab, wenn nicht) — kein Fix am Produktivcode nötig.

## Akzeptanzkriterien-Abdeckung (Task 1.1, `tasks.md`)

- [x] `php -l scripts/MigrationRunner.php` ist fehlerfrei — getestet in
  `migration-runner-test.php` (`php -l`-Check via `exec()`)
- [x] Klasse hat keine Abhängigkeit auf `wizard/` — getestet in
  `migration-runner-test.php::"Code (ausserhalb von Kommentaren) referenziert
  nirgends 'wizard'"` (Tokenizer-basiert, filtert Doc-Kommentare heraus, die
  bewusst auf `wizard/WizardHelper.php` als Quelle verweisen)
- [x] Versionssortierung ist numerisch (002 vor 010) — getestet in
  `"getAvailableMigrations() liefert 001,002,9,010 in numerischer
  Reihenfolge (002 vor 010, 9 vor 010)"` (zusätzlich mit einer einstelligen
  Version `9` neben `010` getestet — das ist der Fall, bei dem
  lexikografische und numerische Sortierung tatsächlich divergieren)
- [x] `runPending` trägt eine fehlgeschlagene Migration nicht ein und stoppt
  sofort — getestet in Gruppe B ("Requirement: Fehlerabbruch ohne
  Fortschrittseintrag", 6 Assertions) sowie zusätzlich in der Gegenprobe
  (Gruppe D) mit einer realen Migration

## Weitere eigene Verifikationen (über die Akzeptanzkriterien hinaus, aus der Spec abgeleitet)

- Tracking-Tabelle: Anlegen (`CREATE TABLE IF NOT EXISTS`), Idempotenz bei
  zweitem `ensureTrackingTable()`-Aufruf (Spaltenliste unverändert), korrekte
  Spalten (`version`, `applied_at`, `description`)
- `getAppliedVersions()`: leer auf frischer Tabelle, korrekt nach Inserts
- `executeSqlFile()`-Semantik: `{{PREFIX}}`-Ersetzung auch innerhalb eines
  String-Literals (`CONCAT('{{PREFIX}}', 'seed')`), `#`- und
  `--`-Kommentarzeilen werden verworfen, Statement-Split an
  Zeilenende-`;`, Session-Variablen (`SET @var`, `PREPARE`/`EXECUTE`/
  `DEALLOCATE`) bleiben über mehrere Statements hinweg auf derselben
  Verbindung erhalten (Guard-Migration legt tatsächlich eine Spalte an)
- `recordMigration()`: schreibt Version + Description korrekt; wirft
  `RuntimeException` bei Duplicate-Key (Primary-Key-Verletzung), ohne
  doppelten Eintrag zu hinterlassen
- Idempotenz-Dreiklang aus `design.md` D8: Fresh-Install (alle angewendet),
  wiederholter Lauf (0 angewendet, alle übersprungen), Teilzustand nach
  gezieltem `DELETE` einer Version (nur diese wird erneut angewendet)
- Edge Case: `getAvailableMigrations()` liefert `[]`, wenn
  `database/migrations/` nicht existiert
- Edge Case: `getMigrationVersion()`/`getMigrationDescription()` ohne
  Zahlpräfix bzw. ohne `-- Description:`-Zeile fallen korrekt auf den
  Dateinamen zurück
- Fehlerpfad: `executeSqlFile()` mit nicht existierender Datei liefert
  `success = false` mit erklärender Fehlermeldung
- Realdaten-Szenario (Gruppe C): alle fünf echten Migrationsdateien
  `001-005` gegen das vollständige, echte Schema (`schema.sql` +
  `schema-auth.sql`) angewendet — grün, inkl. der Guard-Migrationen 001-003
  mit Session-Variablen und der Mehrfach-Statement-Migration 005

## Ausführungs-Ergebnis

```
$ MR_TEST_HOST=127.0.0.1 MR_TEST_PORT=33062 MR_TEST_USER=root MR_TEST_PASS=root \
    php tests/migration-runner-test.php

== php -l scripts/MigrationRunner.php (Akzeptanzkriterium T1.1) ==
  ✓ php -l scripts/MigrationRunner.php ist fehlerfrei

== Keine Abhaengigkeit auf wizard/ (Akzeptanzkriterium T1.1) ==
  ✓ Code (ausserhalb von Kommentaren) referenziert nirgends "wizard"

== Requirement: Versionssortierung ist numerisch (...) ==
  ✓ getAvailableMigrations() liefert 001,002,9,010 in numerischer Reihenfolge (002 vor 010, 9 vor 010)
  ✓ getMigrationVersion() bewahrt das Originalformat (fuehrende Nullen bleiben erhalten) UND die Sortierung war numerisch
  ✓ getMigrationVersion("004_add_ai_rate_limits.sql") liefert "004" (Originalformat mit fuehrender Null)
  ✓ getMigrationVersion() faellt ohne Zahlpraefix auf den Basisnamen zurueck
  ✓ getMigrationDescription() liest "-- Description: ..."-Zeile
  ✓ getMigrationDescription() faellt ohne Description-Zeile auf basename() (inkl. .sql) zurueck

== Edge Case: fehlendes database/migrations/-Verzeichnis ==
  ✓ getAvailableMigrations() liefert ein leeres Array, wenn database/migrations/ nicht existiert

== Requirement: DB-Verbindung fuer Integrationstests ==
  ✓ Verbindung zu Test-MySQL (127.0.0.1:33062) hergestellt

== Requirement: Tracking-Tabelle mit Tabellenpraefix ==
  ✓ Tracking-Tabelle existiert vor ensureTrackingTable() noch nicht (SHOW TABLES liefert 0 Zeilen)
  ✓ ensureTrackingTable() (1. Aufruf) laeuft ohne Exception durch
  ✓ ensureTrackingTable() legt <prefix>schema_migrations an
  ✓ ensureTrackingTable() (2. Aufruf) laeuft ohne Exception durch (idempotent)
  ✓ ensureTrackingTable() ist idempotent (zweiter Aufruf aendert die Spaltenliste nicht)
  ✓ Tracking-Tabelle hat die Spalten version/applied_at/description
  ✓ getAppliedVersions() liefert leeres Array auf frischer Tracking-Tabelle

== Requirement: Idempotente Anwendung in numerischer Reihenfolge (Frische Datenbank) ==
  ✓ Fresh-Install: runPending() meldet keinen Fehler
  ✓ Fresh-Install: 001, 002, 010 werden in numerischer Reihenfolge angewendet
  ✓ Fresh-Install: nichts wird uebersprungen
  ✓ Nach Fresh-Install stehen 001, 002, 010 in der Tracking-Tabelle
  ✓ recordMigration() traegt die Description aus der Migrationsdatei ein
  ✓ Guard-Migration 002 (SET/PREPARE/EXECUTE/DEALLOCATE ueber mehrere Statements) hat die color-Spalte angelegt -- Session-Variablen bleiben ueber Statement-Grenzen hinweg erhalten
  ✓ {{PREFIX}} wird auch innerhalb eines String-Literals (CONCAT) ersetzt

== Requirement: Idempotente Anwendung (wiederholter Lauf ist wirkungslos) ==
  ✓ Zweiter Lauf: 0 Migrationen angewendet
  ✓ Zweiter Lauf: alle drei Versionen werden uebersprungen
  ✓ Zweiter Lauf: kein Fehler

== Requirement: Idempotente Anwendung (Teilzustand) ==
  ✓ Teilzustand: nach Entfernen von Version 010 wendet der Runner ausschliesslich 010 erneut an
  ✓ Teilzustand: 001 und 002 bleiben uebersprungen
  ✓ Teilzustand: nach dem dritten Lauf sind wieder alle drei Versionen eingetragen

== Requirement: SQL-Ausfuehrungssemantik (executeSqlFile) ==
  ✓ executeSqlFile() liefert success=false bei nicht existierender Datei
  ✓ executeSqlFile()-Fehlermeldung nennt den Dateinamen bei fehlender Datei

== Requirement: recordMigration() Fehlerverhalten ==
  ✓ recordMigration() wirft RuntimeException bei Duplicate-Key (Version bereits eingetragen)
  ✓ Fehlgeschlagener recordMigration()-Aufruf hinterlaesst keinen Duplicate-Eintrag

== Requirement: Fehlerabbruch ohne Fortschrittseintrag ==
  ✓ runPending() meldet die fehlgeschlagene Version 099 (Originalformat, inkl. fuehrender Nullen)
  ✓ runPending() liefert einen mysqli-Fehlertext
  ✓ runPending() liefert das fehlgeschlagene (gekuerzte) Statement
  ✓ runPending() bricht erst NACH den zuvor erfolgreichen Migrationen 001 und 002 ab
  ✓ Vorherige Migrationen (001, 002) bleiben in der Tracking-Tabelle eingetragen
  ✓ Die fehlgeschlagene Migration 099 wird NICHT in die Tracking-Tabelle eingetragen

== Szenario: vollstaendiges Schema (schema.sql + schema-auth.sql) + reale Migrationen 001-005 ==
  ✓ schema.sql wird ueber executeSqlFile() fehlerfrei eingespielt (Vorbedingung)
  ✓ schema-auth.sql wird ueber executeSqlFile() fehlerfrei eingespielt (Vorbedingung)
  ✓ Mit vollstaendigem Schema (inkl. schema-auth.sql) laeuft Migration 003 (ALTER TABLE {{PREFIX}}auth_users ADD COLUMN avatar) fehlerfrei durch
  ✓ Alle fuenf realen Migrationen (001-005) werden in numerischer Reihenfolge angewendet
  ✓ Migration 003 hat die Spalte avatar auf {{PREFIX}}auth_users angelegt

== Gegenprobe: NUR schema.sql (ohne schema-auth.sql) -> Migration 003 muss fehlschlagen ==
  ✓ schema.sql allein wird fehlerfrei eingespielt (Vorbedingung fuer die Gegenprobe)
  ✓ Ohne schema-auth.sql schlaegt Migration 003 fehl (fehlende Tabelle {{PREFIX}}auth_users) -- bestaetigt: Ursache ist unvollstaendiges Schema, kein MigrationRunner-Defekt
  ✓ Die mysqli-Fehlermeldung nennt die fehlende Tabelle auth_users
  ✓ Migrationen 001 und 002 (ohne auth_users-Abhaengigkeit) werden trotzdem erfolgreich angewendet
  ✓ Die fehlgeschlagene Migration 003 wird nicht in die Tracking-Tabelle eingetragen

==============================================================
Ergebnis: 50 bestanden, 0 fehlgeschlagen
==============================================================
```

Zweiter, unabhängiger Testlauf (Wiederholbarkeit): identisches Ergebnis
(`50 bestanden, 0 fehlgeschlagen`, Exit-Code 0).

## Fehler (falls vorhanden)

Keine — alle 50 Assertions bestehen bei beiden Testläufen. Die während der
Testentwicklung gefundenen Fehlschläge (Reihenfolge-Interferenz zwischen
Testgruppen wegen des hartkodierten `__DIR__`-relativen Migrationspfads,
ein Tippfehler `$stepResult(...)`, ein `?? 'MISSING'`-Sentinel-Bug bei
Assertions mit erwartetem `null`) waren ausschließlich Fehler im
Test-Harness selbst und wurden vor der finalen Ausführung behoben; sie
betrafen an keiner Stelle `scripts/MigrationRunner.php`.

## Hinweis für nachfolgende Tasks

- T2.1 (`scripts/migrate.php`) und T3.1 (`scripts/migrate-selftest.php`)
  sind noch nicht implementiert; entsprechend testet dieser Report
  ausschließlich die Klasse `MigrationRunner` direkt, nicht die künftige
  CLI-Schicht.
- Für T2.1/T3.1 empfiehlt es sich, beim Aufbau der Test-/Selftest-Datenbank
  ausdrücklich **beide** Schema-Dateien (`database/schema.sql` und
  `database/schema-auth.sql`, in dieser Reihenfolge) zu laden — siehe Befund
  oben. `database/schema-netbeat.sql` ist ein alternatives, in sich
  geschlossenes Schema für eine andere Hosting-Umgebung und wird laut
  `wizard/install.php` nicht zusätzlich zu `schema.sql` geladen.
