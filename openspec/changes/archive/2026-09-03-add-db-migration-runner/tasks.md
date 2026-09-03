## 1. MigrationRunner-Klasse

- [x] 1.1 `scripts/MigrationRunner.php` implementieren
  - Agent: Developer
  - Dateien: `scripts/MigrationRunner.php` (neu)
  - Abhängigkeiten: keine
  - Beschreibung: Reine PHP-Klasse (mysqli), **keine** `require` auf
    `wizard/`. Methoden analog `WizardHelper`, aber eigenständig:
    `getAvailableMigrations()` (glob `database/migrations/*.sql`),
    `getMigrationVersion()` (führender `\d+`-Teil des Dateinamens),
    `getMigrationDescription()` (Zeile `-- Description: …`),
    `ensureTrackingTable(mysqli, prefix)` (`CREATE TABLE IF NOT EXISTS
    <prefix>schema_migrations` mit DDL aus `database/schema.sql:208-213`),
    `getAppliedVersions(mysqli, prefix)`,
    `executeSqlFile(mysqli, file, prefix)` (Semantik 1:1 aus
    `WizardHelper::executeSqlFile` + `splitSqlStatements`: `{{PREFIX}}`
    ersetzen, `--`/`#`-Zeilen verwerfen, an `;`-Zeilenende splitten,
    Statements einzeln via `mysqli::query()` auf **einer** Verbindung),
    `recordMigration(mysqli, prefix, version, description)` (Prepared
    `INSERT`), `runPending(mysqli, prefix): array` (Reihenfolge numerisch,
    Stop bei erstem Fehler ohne Eintrag der Fehlmigration).
    Kopf-Kommentar mit Verweis auf `wizard/WizardHelper.php` (Duplikat,
    Folge-Change zur Zusammenführung notiert).
  - Akzeptanz:
    - [x] `php -l scripts/MigrationRunner.php` ist fehlerfrei
    - [x] Klasse hat keine Abhängigkeit auf `wizard/`
    - [x] Versionssortierung ist numerisch (`002` vor `010`)
    - [x] `runPending` trägt eine fehlgeschlagene Migration **nicht** ein
      und stoppt sofort

## 2. CLI-Einstiegspunkt

- [x] 2.1 `scripts/migrate.php` implementieren
  - Agent: Developer
  - Dateien: `scripts/migrate.php` (neu)
  - Abhängigkeiten: 1.1
  - Beschreibung: CLI-Skript. Argumente:
    `[--dry-run] [pfad/zu/config.local.php]`. Ablauf:
    `extension_loaded('mysqli')` prüfen (sonst Fehler + `exit(1)`);
    Konfigpfad bestimmen (Default `__DIR__.'/../api/config.local.php'`);
    Datei `require`-n; prüfen, dass `DB_HOST`, `DB_USER`, `DB_NAME`,
    `DB_PREFIX` definiert sind (sonst Fehler + `exit(1)`); mysqli-Verbindung
    (`DB_PORT` optional, Default 3306); `set_charset('utf8mb4')`;
    `MigrationRunner::ensureTrackingTable`; bei `--dry-run` ausstehende
    Versionen ausgeben und `exit(0)`; sonst `runPending` und Log ausgeben.
    Schlusszeile `MIGRATE OK: X angewendet, Y übersprungen` (`exit(0)`) bzw.
    `MIGRATE FAIL bei NNN: <fehler>` (`exit(1)`).
  - Akzeptanz:
    - [x] `php -l scripts/migrate.php` fehlerfrei
    - [x] Fehlende `config.local.php` → Exit 1 mit erklärender Meldung
    - [x] `--dry-run` ändert die DB nicht und endet mit Exit 0
    - [x] Erfolgslauf endet mit Exit 0, Fehlerlauf mit Exit 1

## 3. Selbsttest

- [x] 3.1 `scripts/migrate-selftest.php` implementieren
  - Agent: Developer
  - Dateien: `scripts/migrate-selftest.php` (neu)
  - Abhängigkeiten: 2.1
  - Beschreibung: Ohne PHPUnit. Liest `MIGRATE_TEST_HOST`, `_PORT`, `_USER`,
    `_PASS`, `_NAME`, `_PREFIX` aus der Umgebung; schreibt eine temporäre
    `config.local.php`; ruft `scripts/migrate.php` per `proc_open`/`exec`
    auf und prüft die vier Szenarien aus design.md D8 (Fresh-Install,
    Idempotenz, Teilzustand nach `DELETE`, Fehlerabbruch mit kaputter
    Zusatzmigration in einem Test-Temp-Pfad). Räumt Testtabellen am Ende auf.
    Exit 0 nur bei allen bestandenen Assertions, sonst Exit 1.
  - Akzeptanz:
    - [x] `php -l scripts/migrate-selftest.php` fehlerfrei
    - [x] Gegen eine leere lokale MySQL/MariaDB (Docker) läuft der Selbsttest
      mit Exit 0 durch
    - [x] Bei absichtlich gebrochenem Runner meldet der Selbsttest Exit 1

- [x] 3.2 Selbsttest gegen lokale MySQL ausführen und dokumentieren
  - Agent: Developer
  - Dateien: — (Verifikation), ggf. kurzer Abschnitt in `README.md` oder
    neuer `scripts/README.md`
  - Abhängigkeiten: 3.1
  - Beschreibung: Ausführungsanleitung festhalten (Docker-Einzeiler für
    MySQL 8, benötigte Env-Variablen). Ergebnis in
    `task-3.2.notes.md` protokollieren.
  - Akzeptanz:
    - [x] Dokumentierter Befehl reproduziert einen grünen Selbsttest-Lauf

## 4. build.sh

- [x] 4.1 `scripts/` ins Deploy-Paket aufnehmen
  - Agent: Developer
  - Dateien: `build.sh`
  - Abhängigkeiten: 1.1, 2.1
  - Beschreibung: Im Abschnitt „3/5" von `build.sh`
    `mkdir -p "$DIST/scripts"` und explizit
    `cp scripts/MigrationRunner.php scripts/migrate.php "$DIST/scripts/"`
    ergänzen (Allowlist — `migrate-selftest.php` bewusst ausgenommen).
  - Akzeptanz:
    - [x] Nach `bash build.sh` existieren
      `dist/dog-mentality-test/scripts/MigrationRunner.php` und
      `.../scripts/migrate.php`
    - [x] `dist/dog-mentality-test/scripts/migrate-selftest.php` existiert
      nicht
    - [x] `bash build.sh` endet mit Exit-Code 0
