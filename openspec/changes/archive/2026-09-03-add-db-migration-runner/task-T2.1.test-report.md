# Test-Report: T2.1 — `scripts/migrate.php`

**Status:** alle-gruen

## Vorbemerkung: unabhängige Nachverifikation

Dieser Report prüft den vom Entwickler-Agent berichteten funktionalen
Testlauf **eigenständig nach** (nicht blind übernommen), gegen einen neuen,
kurzlebigen `mysql:8.0`-Docker-Container (`migrate-t21-test`, Port `33071`,
inzwischen entfernt). Es wurden **keine** Produktivdateien geändert; alle
Testartefakte (Configs, isolierte Migrations-Verzeichnisse) liegen im
Scratchpad und wurden nach Abschluss entfernt.

Es existiert **keine** `TESTING.md` und **keine** `CLAUDE.md` im
Projekt-Root (per `find` verifiziert) — es gibt daher keine
projektspezifischen Test-Konventionen, die hier vorrangig zu befolgen wären.
Als Referenz für Stil/Konventionen wurde die bereits vorhandene
`tests/migration-runner-test.php` (Task T1.1) herangezogen: reines
PHP-Assertions-Skript ohne PHPUnit (bewusster Non-Goal laut `design.md`),
PASS/FAIL-Zähler, Ausführung gegen eine Wegwerf-MySQL-Instanz.

## Hinweis zu den Schema-Dateien (wichtig für Reproduzierbarkeit)

Wie vom Auftrag gefordert wurden **alle drei** Schema-Dateien in der
Reihenfolge `database/schema.sql` → `database/schema-netbeat.sql` →
`database/schema-auth.sql` geladen. Dabei zeigte sich:

- `database/schema-netbeat.sql` ist eine **Alternative** zu `schema.sql`
  (trigger-freie Variante für netbeat-Hosting, siehe Kopfkommentar der
  Datei), **kein** additives Schema-Fragment. Nach `schema.sql` geladen,
  bricht `mysql < schema-netbeat.sql` bei Zeile 172
  (`CREATE INDEX idx_session_dog_battery ...`, kein `IF NOT EXISTS`) mit
  `ERROR 1061 (42000): Duplicate key name 'idx_session_dog_battery'` ab, da
  dieser Index bereits über `schema.sql`s `CREATE TABLE`-Definition von
  `test_sessions` existiert.
- Der resultierende Schema-Zustand ist dadurch **funktional unverändert**
  gegenüber `schema.sql` + `schema-auth.sql` allein (alle App-Tabellen sowie
  `dmt_schema_migrations` — Letztere bereits Teil von `schema.sql` selbst,
  `database/schema.sql:208-213` — sind vorhanden, keine Tabelle fehlt, keine
  ist dupliziert). Der kanonische Installationsweg laut
  `wizard/install.php:81-84` ist ohnehin nur `schema.sql` + `schema-auth.sql`
  (ohne `schema-netbeat.sql`).
- Diese Beobachtung bestätigt indirekt die Notiz aus dem Auftrag: Ein
  unvollständiges Schema-Setup (hier: der Netbeat-Zwischenschritt) erzeugt
  einen SQL-Fehler, der **nichts** mit `scripts/migrate.php` oder
  `MigrationRunner.php` zu tun hat. Für die eigentlichen `migrate.php`-Tests
  wurde der danach vorliegende, funktional vollständige Schema-Zustand
  verwendet (identisch zu `schema.sql` + `schema-auth.sql`).
- Zur Kontrolle wurde zusätzlich `tests/migration-runner-test.php` (bereits
  vorhanden, Task T1.1) gegen denselben Container ausgeführt — inklusive
  dessen "Gegenprobe" (Gruppe D), die explizit bestätigt, dass ein
  Fehlschlag von Migration 003 bei **nur** geladenem `schema.sql` (ohne
  `schema-auth.sql`) am unvollständigen Schema liegt, nicht an
  `MigrationRunner.php`. Alle 50 Assertions dieses bestehenden Tests sind
  grün (siehe Ausführungs-Ergebnis unten) — keine Regression durch T2.1.

## Hinzugefügte / geänderte Tests

Keine neuen automatisierten Testdateien hinzugefügt. Task T2.1 hat laut
`tasks.md` keinen eigenen dedizierten Selbsttest zum Gegenstand — dieser ist
Task 3.1 (`scripts/migrate-selftest.php`, noch offen/nicht implementiert).
Die Verifikation von T2.1 erfolgte daher analog zum Vorgehen des
Entwickler-Agenten manuell/skriptgesteuert per Shell + Docker-MySQL
(Kommandos protokolliert unten unter "Ausführungs-Ergebnis"), ergänzt um
eine unabhängige Prüfung per `information_schema.TABLES`-Diff für den
Dry-Run. Zusätzlich wurde die bestehende Regressionssuite
`tests/migration-runner-test.php` erneut ausgeführt (50/50 grün).

## Akzeptanzkriterien-Abdeckung (Task 2.1 aus `tasks.md`)

- [x] `php -l scripts/migrate.php` fehlerfrei — verifiziert:
  `No syntax errors detected in .../scripts/migrate.php`, Exit 0.
- [x] Fehlende `config.local.php` → Exit 1 mit erklärender Meldung —
  verifiziert mit nicht-existierendem Pfad:
  `MIGRATE FAIL: Konfigurationsdatei nicht gefunden: <pfad>`, Exit 1.
  Zusätzlich (Spec-Scenario "unvollständig"): Config ohne `DB_USER`,
  `DB_NAME`, `DB_PREFIX` → `MIGRATE FAIL: Fehlende DB-Konstanten in <pfad>:
  DB_USER, DB_NAME, DB_PREFIX`, Exit 1.
- [x] `--dry-run` ändert die DB nicht und endet mit Exit 0 — **unabhängig
  verifiziert** per `information_schema.TABLES`-Snapshot vor/nach dem
  Aufruf:
  - Auf einer Datenbank **ohne** Tracking-Tabelle (`dmt_schema_migrations`
    explizit per `DROP TABLE` entfernt): Dry-Run listet alle 5 Versionen als
    ausstehend, Exit 0, `information_schema.TABLES`-Diff vor/nach ist
    **identisch** — die Tracking-Tabelle wurde **nicht** angelegt. Bestätigt
    die im Code (`scripts/migrate.php`) und in `task-T2.1.notes.md`
    dokumentierte bewusste Abweichung von der wörtlichen `tasks.md`-Reihenfolge
    (`ensureTrackingTable()` wird im Dry-Run-Zweig **nicht** aufgerufen,
    stattdessen nur lesend `SHOW TABLES LIKE`).
  - Auf einer Datenbank **mit** bereits existierender, leerer
    Tracking-Tabelle: Dry-Run listet ebenfalls alle 5 Versionen, Exit 0,
    Tabellen-Snapshot unverändert, Zeilenanzahl in
    `dmt_schema_migrations` bleibt 0.
  - Finaler Dry-Run nach vollständiger Migration: `Keine ausstehenden
    Migrationen.`, Exit 0.
- [x] Erfolgslauf endet mit Exit 0, Fehlerlauf mit Exit 1 — verifiziert:
  - Erfolgslauf (echte Migrationen 001–005 gegen vollständiges Schema):
    `MIGRATE OK: 5 angewendet, 0 übersprungen`, Exit 0; alle 5 Versionen
    korrekt mit (mojibake-behafteter, aber inhaltlich korrekter UTF-8-)
    Description in `dmt_schema_migrations` eingetragen.
  - Idempotenz-Lauf (zweiter Aufruf, unverändert): `MIGRATE OK: 0
    angewendet, 5 übersprungen`, Exit 0.
  - Fehlerlauf (isoliertes Migrations-Verzeichnis mit echten Migrationen
    001/002 + einer absichtlich kaputten Zusatzmigration `999_broken.sql`,
    die auf eine nicht existierende Tabelle inserted): 001 und 002 werden
    erfolgreich angewendet und geloggt, bei 999 erscheint
    `MIGRATE FAIL bei 999: Table '...' doesn't exist`, Exit 1;
    `dmt2_schema_migrations` enthält danach **nur** `001` und `002`, **kein**
    Eintrag für `999` — bestätigt "Fehlerabbruch ohne Fortschrittseintrag"
    und "frühere Migrationen bleiben verbucht" (Spec-Requirements, nicht Teil
    der T2.1-Akzeptanzliste selbst, aber Vorbedingung für "Fehlerlauf mit
    Exit 1").
  - Diese isolierte Fehlerlauf-Umgebung enthielt zusätzlich **kein**
    `wizard/`-Verzeichnis — bestätigt nebenbei das Spec-Scenario
    "wizard-Verzeichnis fehlt" (`migrate.php` lief dennoch fehlerfrei bis
    zum erwarteten Fehlerabbruch der kaputten Testmigration).

Alle vier Akzeptanzkriterien aus `tasks.md` Task 2.1 sind erfüllt und wurden
unabhängig reproduziert.

## Ausführungs-Ergebnis

```
$ php -l scripts/migrate.php
No syntax errors detected in scripts/migrate.php
exit=0

$ php scripts/migrate.php <nicht-existierender-pfad>/config.local.php
MIGRATE FAIL: Konfigurationsdatei nicht gefunden: <pfad>
exit=1

$ php scripts/migrate.php <config ohne DB_USER/DB_NAME/DB_PREFIX>
MIGRATE FAIL: Fehlende DB-Konstanten in <pfad>: DB_USER, DB_NAME, DB_PREFIX
exit=1

--- Dry-Run auf DB OHNE Tracking-Tabelle (DROP TABLE vorher ausgeführt) ---
Snapshot information_schema.TABLES VOR Dry-Run: 11 Tabellen (keine dmt_schema_migrations)
$ php scripts/migrate.php --dry-run <config>
Ausstehende Migrationen: 001, 002, 003, 004, 005
exit=0
Snapshot information_schema.TABLES NACH Dry-Run: identisch (diff leer)
-> KEINE AENDERUNG

--- Dry-Run auf DB MIT vorhandener, leerer Tracking-Tabelle ---
$ php scripts/migrate.php --dry-run <config>
Ausstehende Migrationen: 001, 002, 003, 004, 005
exit=0
Tabellen-Snapshot vor/nach: identisch; dmt_schema_migrations weiterhin 0 Zeilen

--- Erfolgslauf (echte Migrationen 001-005) ---
$ php scripts/migrate.php <config>
✅ ... (SET/PREPARE/EXECUTE/DEALLOCATE-Zeilen für 001-003)
✅ CREATE TABLE IF NOT EXISTS dmt_ai_rate_limits (
✅ CREATE TABLE IF NOT EXISTS dmt_auth_password_resets (
✅ ALTER TABLE dmt_auth_logs
MIGRATE OK: 5 angewendet, 0 übersprungen
exit=0

--- Idempotenz-Lauf (zweiter Aufruf) ---
$ php scripts/migrate.php <config>
MIGRATE OK: 0 angewendet, 5 übersprungen
exit=0

--- Finaler Dry-Run ---
$ php scripts/migrate.php --dry-run <config>
Keine ausstehenden Migrationen.
exit=0

--- Fehlerlauf (isoliertes Verzeichnis, 001+002 echt, 999 kaputt, KEIN wizard/-Verzeichnis vorhanden) ---
$ php scripts/migrate.php <config>
✅ ... (001, 002 erfolgreich)
❌ FEHLER: Table 'dmt_test2.dmt2_nicht_vorhandene_tabelle_t21' doesn't exist | SQL: INSERT INTO dmt2_nicht_vorhandene_tabelle_t21 (id) VALUES (1);
MIGRATE FAIL bei 999: Table 'dmt_test2.dmt2_nicht_vorhandene_tabelle_t21' doesn't exist
exit=1
schema_migrations danach: nur 001, 002 (kein Eintrag für 999)

--- Regressionssuite (bestehend, Task T1.1) gegen denselben Container ---
$ MR_TEST_HOST=127.0.0.1 MR_TEST_PORT=33071 MR_TEST_USER=root MR_TEST_PASS=rootpass \
    php tests/migration-runner-test.php
...
Ergebnis: 50 bestanden, 0 fehlgeschlagen
```

## Fehler (falls vorhanden)

Keine. Alle geprüften Szenarien und Akzeptanzkriterien sind bestanden;
der vom Entwickler-Agenten berichtete funktionale Testlauf konnte
unabhängig reproduziert werden. Die dokumentierte bewusste Design-Entscheidung
(Dry-Run legt die Tracking-Tabelle **nicht** an) wurde per
`information_schema.TABLES`-Diff aktiv verifiziert, nicht nur aus den Notes
übernommen.

## Sonstige Beobachtungen (kein Fehler, nur Hinweis)

- Die in `dmt_schema_migrations.description` gespeicherten Texte enthalten
  bei Sonderzeichen (`ü`, `ß`) Mojibake in der `mysql`-CLI-Textausgabe
  (z. B. `f�r` statt `für`). Dies ist ein reines Terminal-/Client-
  Encoding-Artefakt der `mysql`-CLI-Ausgabe in diesem Testkontext
  (`SELECT ... ` ohne `SET NAMES utf8mb4` in der interaktiven Shell), nicht
  ein Fehler in `migrate.php`/`MigrationRunner.php` selbst — die Verbindung
  wird dort korrekt per `set_charset('utf8mb4')` konfiguriert. Kein
  Handlungsbedarf für T2.1.
- `database/schema-netbeat.sql` ist, wie oben beschrieben, ein
  **Alternativ**-Schema und nicht additiv zu `schema.sql` gedacht. Falls in
  einer künftigen Task (z. B. 3.1 Selbsttest) versehentlich alle drei
  Schema-Dateien sequenziell geladen werden, ist mit dem oben beschriebenen,
  harmlosen `Duplicate key name`-Abbruch bei Zeile 172 von
  `schema-netbeat.sql` zu rechnen. Empfehlung an nachfolgende Tasks: für
  Testaufbauten ausschließlich `schema.sql` + `schema-auth.sql` verwenden
  (deckt sich mit `wizard/install.php:81-84`).
