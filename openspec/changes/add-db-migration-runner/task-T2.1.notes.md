# Notes — Task 2.1: `scripts/migrate.php`

## Umgesetzt

- Neue Datei `scripts/migrate.php`: nicht-interaktiver CLI-Einstiegspunkt für
  `MigrationRunner` (aus Task 1.1, unverändert übernommen und genutzt).
- Argument-Parsing: `--dry-run` (Flag, beliebige Position) sowie ein
  optionaler Positionsparameter für einen alternativen Pfad zu
  `config.local.php`. Ohne Argument: `__DIR__ . '/../api/config.local.php'`.
- Ablauf wie in `tasks.md`/`design.md` (D6) beschrieben:
  1. `extension_loaded('mysqli')`-Check → `exit(1)` mit Meldung, falls fehlend.
  2. Konfigpfad bestimmen, `is_file()`-Check → `exit(1)`, falls nicht gefunden.
  3. `require` der Config-Datei, Prüfung auf `DB_HOST`, `DB_USER`, `DB_NAME`,
     `DB_PREFIX` via `defined()` → `exit(1)` mit Liste der fehlenden
     Konstanten, falls unvollständig.
  4. `mysqli_init()` + `real_connect()` mit `DB_PORT` (Default 3306) und
     `DB_PASS` (Default `''`, falls nicht definiert) — Verbindungsfehler
     → `exit(1)`.
  5. `set_charset('utf8mb4')`.
  6. Danach verzweigt der Ablauf in Dry-Run und Normalbetrieb.

## Abweichung von der wörtlichen Ablaufbeschreibung (bewusst, spezifikationskonform)

Die Aufgabenbeschreibung listet `MigrationRunner::ensureTrackingTable()` als
Schritt vor der `--dry-run`-Weiche. Das würde aber der Spec-Anforderung
„Dry-Run-Modus" (`specs/database-migrations/spec.md`) sowie der eigenen
Akzeptanzbedingung „`--dry-run` ändert die DB nicht" widersprechen, da
`CREATE TABLE IF NOT EXISTS` eine DDL-Änderung ist. Daher ruft
`migrate.php` `ensureTrackingTable()` **nur im Nicht-Dry-Run-Zweig** auf. Im
Dry-Run-Zweig wird stattdessen rein lesend per
`SHOW TABLES LIKE '<prefix>schema_migrations'` geprüft, ob die
Tracking-Tabelle existiert:

- existiert sie → `MigrationRunner::getAppliedVersions()` wird gelesen,
- existiert sie nicht → alle Migrationen gelten als ausstehend (keine
  Tabellenanlage, keine Query gegen eine nicht existierende Tabelle, kein
  Fehler).

Damit ist der Dry-Run auf einer komplett frischen Datenbank (Tracking-Tabelle
existiert noch nicht) benutzbar, ohne eine DB-Änderung vorzunehmen — exakt
das im Requirement geforderte Verhalten ("keine Datenbankänderung
vornehmen (auch nicht das Anlegen der Tracking-Tabelle, sofern
vermeidbar …)").

## Sonstige Design-Entscheidungen

- Kein `never`-Rückgabetyp für die Helper-Funktion `migrateFail()` (PHP 8.1+),
  da `composer.json` `platform.php: 8.0` als Ziel-Plattform vorgibt.
  Stattdessen `void` mit `exit(1)` im Funktionskörper.
- `mysqli_report(MYSQLI_REPORT_OFF)` vor `mysqli_init()`, damit
  Verbindungsfehler als klassischer `bool`/`connect_error` behandelt werden
  können, unabhängig vom global konfigurierten mysqli-Report-Modus (analog zur
  Absicherung in `MigrationRunner::runQuery()`/`runStatement()`).
- Verbindung wird in einem `finally`-Block geschlossen (`$conn->close()`),
  auch wenn `exit()` innerhalb des `try`-Blocks aufgerufen wird — PHP
  garantiert, dass `finally` vor der tatsächlichen Skript-Terminierung noch
  ausgeführt wird.
- Fehlermeldungen gehen auf STDERR (`fwrite(STDERR, …)`), Erfolgs-/Log-Zeilen
  auf STDOUT (`echo`/`printf`).
- Kein Zugriff/Änderung an `scripts/MigrationRunner.php` — nur dessen
  öffentliche API (`getAvailableMigrations`, `getMigrationVersion`,
  `getAppliedVersions`, `ensureTrackingTable`, `runPending`) wird verwendet.

## Verifikation

- `php -l scripts/migrate.php` → fehlerfrei.
- Fehlerpfade ohne DB (kein Docker nötig):
  - Nicht existierender Konfigpfad → `MIGRATE FAIL: Konfigurationsdatei
    nicht gefunden: …`, Exit 1.
  - Konfigdatei ohne `DB_USER`/`DB_NAME`/`DB_PREFIX` → `MIGRATE FAIL:
    Fehlende DB-Konstanten in …: DB_USER, DB_NAME, DB_PREFIX`, Exit 1.
- Funktionaler Test gegen einen kurzlebigen `mysql:8.0`-Docker-Container
  (`docker run --name migrate-test-mysql -e MYSQL_ROOT_PASSWORD=rootpass
  -e MYSQL_DATABASE=dmt_test -e MYSQL_USER=dmt -e MYSQL_PASSWORD=dmtpass
  -p 33061:3306 mysql:8.0`), Basis-Schema aus `database/schema.sql`
  (Präfix `dmt_`) vorab eingespielt, temporäre `config.local.php` mit den
  Testzugangsdaten:
  - **Dry-Run auf frischer DB ohne Tracking-Tabelle** → listet alle 5
    Versionen als ausstehend, Exit 0, `dmt_schema_migrations` wurde
    **nicht** angelegt (verifiziert per `SHOW TABLES LIKE`).
  - **Erster echter Lauf**: wendet 001/002 erfolgreich an, bricht bei 003
    mit einem *vorbestehenden, aufgabenfremden* Datenmodell-Mismatch der
    Migrationsdateien ab (003/005 referenzieren `auth_users`/`auth_logs`,
    die in `database/schema.sql` nicht in dieser Form existieren — nicht
    Gegenstand von Task 2.1/1.1, betrifft nur die Migrationsdateien selbst).
    Exit 1, Meldung `MIGRATE FAIL bei 003: …`, `dmt_schema_migrations`
    enthält nur 001/002 (kein Eintrag für die fehlgeschlagene 003) —
    bestätigt „Fehlerabbruch ohne Fortschrittseintrag" und „frühere
    Migrationen bleiben verbucht".
  - **Dry-Run danach**: listet korrekt nur die noch ausstehenden Versionen
    (003, 004, 005), Exit 0.
  - Nach Anlegen der fehlenden Test-Tabellen/Spalten (nur für die
    Verifikation, keine Repo-Änderung) liefen 003–005 erfolgreich durch:
    **Erfolgslauf** → `MIGRATE OK: 1 angewendet, 4 übersprungen`, Exit 0.
  - **Wiederholter Lauf** (volle Idempotenz) → `MIGRATE OK: 0 angewendet,
    5 übersprungen`, Exit 0.
  - **Dry-Run auf vollständig migrierter DB** → `Keine ausstehenden
    Migrationen.`, Exit 0.
  - Container und temporäre Testdateien danach entfernt
    (`docker rm -f migrate-test-mysql`, `rm -rf <tempdir>`).

## Hinweis für Folge-Tasks (3.1/3.2, außerhalb des Scopes dieser Task)

Beim Bau von `scripts/migrate-selftest.php` (Task 3.1) sollte berücksichtigt
werden, dass die Migrationsdateien 003 und 005 auf Tabellennamen/Spalten
(`auth_users`, `auth_logs.action`) verweisen, die von `database/schema.sql`
so nicht bereitgestellt werden. Für einen Selbsttest, der nur
`database/schema.sql` + `database/migrations/*.sql` gegen eine leere DB
fährt, müsste entweder das Test-Setup diese Tabellen zusätzlich anlegen,
oder es handelt sich um eine echte Inkonsistenz in den Migrationsdateien, die
außerhalb des Scopes von Task 2.1 liegt und ggf. dem Skeptiker/Architekten
gemeldet werden sollte.

## Nicht angefasst

- `scripts/MigrationRunner.php` (Task 1.1, nur genutzt, nicht verändert).
- `scripts/migrate-selftest.php` (Task 3.1).
- `build.sh` (Task 4.1).
