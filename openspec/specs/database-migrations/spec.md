# database-migrations Specification

## Purpose
TBD - created by archiving change add-db-migration-runner. Update Purpose after archive.
## Requirements
### Requirement: Nicht-interaktiver Migrations-CLI-Runner

Das Projekt SHALL ein CLI-Skript `scripts/migrate.php` bereitstellen, das
ausstehende Datenbankmigrationen ohne Benutzerinteraktion anwendet. Der Runner
SHALL die DB-Zugangsdaten (`DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`,
`DB_NAME`, `DB_PREFIX`) aus `api/config.local.php` beziehen und SHALL zur
Laufzeit **keine** Datei unter `wizard/` benötigen. Der Runner SHALL über
`ext-mysqli` mit der Datenbank kommunizieren und keine zusätzlichen
GitHub-Secrets oder Umgebungsvariablen voraussetzen.

#### Scenario: Aufruf ohne Argumente auf konfiguriertem Server

- **WHEN** `php scripts/migrate.php` im Projekt-Root eines installierten
  Systems ausgeführt wird
- **THEN** lädt der Runner die DB-Konstanten aus `api/config.local.php`
- **AND** stellt eine mysqli-Verbindung her
- **AND** wendet alle ausstehenden Migrationen an

#### Scenario: wizard-Verzeichnis fehlt

- **WHEN** `wizard/` auf dem Server nicht vorhanden ist
- **THEN** läuft `php scripts/migrate.php` dennoch fehlerfrei

#### Scenario: ext-mysqli nicht geladen

- **WHEN** die CLI-PHP-Umgebung `ext-mysqli` nicht geladen hat
- **THEN** bricht der Runner mit einer erklärenden Fehlermeldung ab
- **AND** der Exit-Code ist 1

#### Scenario: config.local.php fehlt oder ist unvollständig

- **WHEN** `api/config.local.php` fehlt oder eine der Konstanten `DB_HOST`,
  `DB_USER`, `DB_NAME`, `DB_PREFIX` nicht definiert ist
- **THEN** bricht der Runner mit erklärender Fehlermeldung und Exit-Code 1 ab
- **AND** es wird keine Migration ausgeführt

#### Scenario: alternativer Konfigurationspfad

- **WHEN** `php scripts/migrate.php pfad/zu/config.local.php` mit gültigem
  Pfad aufgerufen wird
- **THEN** verwendet der Runner die dort definierten DB-Konstanten

### Requirement: Idempotente Anwendung in numerischer Reihenfolge

Der Runner SHALL die Dateien in `database/migrations/*.sql` nach dem
führenden Zahlpräfix des Dateinamens (z. B. `004`) **numerisch aufsteigend**
sortieren und sequenziell anwenden. Eine Migration, deren Version bereits in
`<DB_PREFIX>schema_migrations` eingetragen ist, SHALL übersprungen werden. Ein
wiederholter Aufruf ohne neue Migrationsdateien SHALL nichts ausführen und mit
Exit-Code 0 enden.

#### Scenario: Frische Datenbank

- **WHEN** `<DB_PREFIX>schema_migrations` noch keine Einträge für die
  vorhandenen Migrationen hat
- **THEN** wendet der Runner alle Dateien in Reihenfolge 001, 002, 003, 004,
  005 an
- **AND** endet mit Exit-Code 0

#### Scenario: Wiederholter Lauf ist wirkungslos

- **WHEN** alle vorhandenen Migrationsversionen bereits in
  `<DB_PREFIX>schema_migrations` stehen
- **THEN** führt der Runner keine SQL-Migration aus
- **AND** gibt einen Hinweis „0 angewendet" aus
- **AND** endet mit Exit-Code 0

#### Scenario: Teilzustand

- **WHEN** die Versionen 001–004 eingetragen sind und 005 fehlt
- **THEN** wendet der Runner ausschließlich 005 an
- **AND** endet mit Exit-Code 0

#### Scenario: Sortierung ist numerisch, nicht lexikografisch

- **WHEN** eine Migration `010_*.sql` neben `002_*.sql` existiert
- **THEN** wendet der Runner `002` vor `010` an

### Requirement: Tracking-Tabelle mit Tabellenpräfix

Der Runner SHALL vor dem Abgleich die Tabelle
`<DB_PREFIX>schema_migrations` per `CREATE TABLE IF NOT EXISTS` mit der
Struktur aus `database/schema.sql` anlegen (Spalten `version VARCHAR(20)`
PRIMARY KEY, `applied_at DATETIME DEFAULT CURRENT_TIMESTAMP`,
`description VARCHAR(255) NOT NULL DEFAULT ''`). Nach erfolgreicher Anwendung
einer Migration SHALL der Runner einen Datensatz mit `version` (Zahlpräfix)
und `description` (Text aus der Kommentarzeile `-- Description:` der Datei, in
Prepared-Statement-Bindung) einfügen.

#### Scenario: Tracking-Tabelle fehlt

- **WHEN** `<DB_PREFIX>schema_migrations` in der Datenbank nicht existiert
- **THEN** legt der Runner sie an, bevor er Migrationen anwendet

#### Scenario: Tracking-Tabelle existiert bereits

- **WHEN** `<DB_PREFIX>schema_migrations` bereits existiert
- **THEN** verändert der Runner ihre Struktur nicht
- **AND** liest die bereits eingetragenen Versionen

#### Scenario: Eintrag nach Erfolg

- **WHEN** eine Migration `NNN` fehlerfrei angewendet wurde
- **THEN** enthält `<DB_PREFIX>schema_migrations` danach eine Zeile mit
  `version = 'NNN'` und der Description aus der Datei

#### Scenario: Präfix wird respektiert

- **WHEN** `DB_PREFIX` den Wert `dmt_` hat
- **THEN** verwendet der Runner die Tabelle `dmt_schema_migrations`
- **AND** ersetzt `{{PREFIX}}` in jeder Migrationsdatei durch `dmt_`

### Requirement: SQL-Ausführungssemantik identisch zum Wizard

Der Runner SHALL pro Migrationsdatei den Platzhalter `{{PREFIX}}` durch den
konfigurierten Präfix ersetzen, Kommentarzeilen (beginnend mit `--` oder `#`)
entfernen, den Rest an Statement-Grenzen (Zeilen, die auf `;` enden)
auftrennen und die Einzel-Statements über **dieselbe** mysqli-Verbindung
ausführen, sodass MySQL-Session-Variablen (`SET @var := …`) und
`PREPARE`/`EXECUTE`/`DEALLOCATE`-Folgen über Statement-Grenzen hinweg
funktionieren. Dies entspricht dem Verhalten von
`WizardHelper::executeSqlFile()`.

#### Scenario: Guard-Migration mit Session-Variablen

- **WHEN** der Runner `001_add_user_id_to_dogs.sql` anwendet (enthält
  `SET @col_exists = (...)`, `PREPARE stmt FROM @sql`, `EXECUTE stmt`,
  `DEALLOCATE PREPARE stmt`)
- **THEN** werden die Statements auf einer Verbindung ausgeführt
- **AND** die Migration ist erfolgreich, unabhängig davon, ob die Spalte
  `user_id` bereits existiert

#### Scenario: {{PREFIX}} auch in String-Literalen

- **WHEN** eine Migration `CONCAT('{{PREFIX}}', 'dogs')` enthält
- **THEN** ersetzt der Runner den Platzhalter auch innerhalb des Literals

### Requirement: Fehlerabbruch ohne Fortschrittseintrag

Beim ersten fehlschlagenden Statement SHALL der Runner sofort stoppen, die
fehlgeschlagene Migration **nicht** in `<DB_PREFIX>schema_migrations`
eintragen, eine Fehlermeldung mit Dateiname, mysqli-Fehlertext und gekürztem
Statement ausgeben und mit Exit-Code 1 enden. Bereits zuvor erfolgreich
angewendete Migrationen bleiben eingetragen.

#### Scenario: Migration schlägt fehl

- **WHEN** ein Statement in Migration `NNN` einen MySQL-Fehler auslöst
- **THEN** stoppt der Runner
- **AND** `<DB_PREFIX>schema_migrations` enthält **keinen** Eintrag für `NNN`
- **AND** der Exit-Code ist 1
- **AND** die Ausgabe nennt Datei, Fehlertext und das betroffene Statement

#### Scenario: Frühere Migrationen bleiben verbucht

- **WHEN** 004 erfolgreich war und 005 fehlschlägt
- **THEN** bleibt der Eintrag für 004 in `<DB_PREFIX>schema_migrations`
  bestehen

### Requirement: Dry-Run-Modus

Der Runner SHALL bei Aufruf mit `--dry-run` die ausstehenden Versionen
auflisten, keine Datenbankänderung vornehmen (auch nicht das Anlegen der
Tracking-Tabelle, sofern vermeidbar — andernfalls nur lesend agieren) und
mit Exit-Code 0 enden.

#### Scenario: Dry-Run mit ausstehenden Migrationen

- **WHEN** `php scripts/migrate.php --dry-run` bei ausstehenden Migrationen
  aufgerufen wird
- **THEN** listet die Ausgabe die ausstehenden Versionen
- **AND** keine Migration wird angewendet
- **AND** der Exit-Code ist 0

### Requirement: Migrations-Konventionen für künftige Dateien

Migrationsdateien SHALL wiederholungssicher sein (z. B. über
`information_schema`-Guards oder `IF NOT EXISTS`), da MySQL-DDL nicht
transaktional zurückrollbar ist. Migrationsdateien SHALL **keine**
`DELIMITER`-Anweisungen, Trigger oder Stored Procedures verwenden (Shared
Hosting ohne SUPER-Privileg; der Statement-Splitter unterstützt sie zudem
nicht).

#### Scenario: Erneuter Lauf nach Fehlerbehebung

- **WHEN** eine fehlgeschlagene Migration korrigiert und der Runner erneut
  ausgeführt wird
- **THEN** verursachen die bereits teilweise angewendeten Anweisungen der
  Datei keinen Fehler (Guards / `IF NOT EXISTS`)

#### Scenario: Unzulässige Konstrukte

- **WHEN** eine Migrationsdatei `DELIMITER`, `CREATE TRIGGER` oder
  `CREATE PROCEDURE` enthält
- **THEN** gilt sie als spezifikationswidrig und wird im Review abgelehnt

### Requirement: Runner ist Teil des Deploy-Pakets

`build.sh` SHALL `scripts/MigrationRunner.php` und `scripts/migrate.php` nach
`dist/dog-mentality-test/scripts/` kopieren. `scripts/migrate-selftest.php`
SHALL **nicht** ins Paket aufgenommen werden.

#### Scenario: build.sh nimmt den Runner auf

- **WHEN** `bash build.sh` ausgeführt wird
- **THEN** existieren `dist/dog-mentality-test/scripts/MigrationRunner.php`
  und `dist/dog-mentality-test/scripts/migrate.php`
- **AND** `dist/dog-mentality-test/scripts/migrate-selftest.php` existiert
  nicht

### Requirement: Unabhängig vom Deploy testbarer Selbsttest

Das Projekt SHALL ein Skript `scripts/migrate-selftest.php` bereitstellen,
das ohne PHPUnit gegen eine per Umgebungsvariablen konfigurierte
Wegwerf-Datenbank Fresh-Install, Idempotenz, Teilzustand und Fehlerabbruch
des Runners prüft und bei einer verletzten Erwartung mit Exit-Code 1 endet.

#### Scenario: Selbsttest gegen leere Testdatenbank

- **WHEN** `php scripts/migrate-selftest.php` mit gesetzten
  `MIGRATE_TEST_*`-Umgebungsvariablen gegen eine leere Datenbank läuft
- **THEN** prüft es: (1) erster Lauf wendet alle Migrationen an und legt
  `schema_migrations` an, (2) zweiter Lauf wendet nichts an, (3) nach
  Entfernen der letzten Version wird nur diese erneut angewendet, (4) eine
  absichtlich fehlerhafte Migration führt zu Exit-Code 1 ohne
  Fortschrittseintrag
- **AND** endet bei Erfolg mit Exit-Code 0, sonst mit Exit-Code 1

