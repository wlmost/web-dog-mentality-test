## Why

Datenbank-Migrationen werden heute **ausschließlich** über den Web-Installer
eingespielt: `wizard/update.php` orchestriert `wizard/WizardHelper.php`
(`getAvailableMigrations`, `getMigrationVersion`, `getMigrationDescription`,
`getAppliedMigrations`, `recordMigration`, `executeSqlFile`) und trägt
angewendete Versionen in `<prefix>schema_migrations` ein
(`database/schema.sql:208`).

Für einen automatischen Produktions-Deploy (Folge-Change
`add-production-deploy-workflow`) wird ein **nicht-interaktiver
Migrations-Runner** benötigt, der per SSH auf dem Server läuft — ohne den
Wizard, der nach der Installation aus Sicherheitsgründen entfernt wird
(`build.sh` `DEPLOY_CHECKLIST.txt`, Schritt 4). Der Runner muss idempotent
sein und exakt die vorhandenen Konventionen einhalten (`{{PREFIX}}`-Ersetzung,
numerische Reihenfolge, Tracking-Tabelle).

Dieser Change liefert den Runner **eigenständig und unabhängig vom Deploy
testbar**.

## What Changes

- **Neu:** `scripts/MigrationRunner.php` — reine PHP-Klasse (mysqli-basiert,
  keine Abhängigkeit von `wizard/`), die:
  - `database/migrations/*.sql` in **numerischer** Reihenfolge einliest
    (Version = Zahlpräfix des Dateinamens, z. B. `004`),
  - die Tracking-Tabelle `<prefix>schema_migrations` idempotent anlegt
    (`CREATE TABLE IF NOT EXISTS`, DDL identisch zu `database/schema.sql`),
  - bereits eingetragene Versionen überspringt,
  - pro ausstehender Datei `{{PREFIX}}` durch den konfigurierten Präfix
    ersetzt, die Statements ausführt (gleiche Semantik wie
    `WizardHelper::executeSqlFile`: Kommentarzeilen entfernen, an `;`
    splitten, Statements einzeln über dieselbe Verbindung ausführen, damit
    Session-Variablen der `PREPARE`/`EXECUTE`-Migrationen 001–003 erhalten
    bleiben),
  - nach Erfolg `INSERT INTO <prefix>schema_migrations (version, description)`
    ausführt,
  - bei erstem Fehler **stoppt**, die fehlgeschlagene Migration **nicht**
    einträgt und einen nicht-null Fehlercode signalisiert.
- **Neu:** `scripts/migrate.php` — CLI-Einstiegspunkt: lädt die DB-Konstanten
  aus `api/config.local.php`, stellt die mysqli-Verbindung her, ruft den
  Runner auf, gibt ein menschenlesbares Log und eine Schlusszeile aus,
  `exit(0)` bei Erfolg (auch "nichts zu tun"), `exit(1)` bei Fehler.
  Flag `--dry-run` listet nur ausstehende Migrationen. Optionaler
  Positionsparameter: alternativer Pfad zu `config.local.php` (für Tests).
- **Neu:** `scripts/migrate-selftest.php` — leichtgewichtiges
  Assertions-Skript (kein PHPUnit) gegen eine Wegwerf-Datenbank
  (Zugangsdaten via Umgebungsvariablen), das Fresh-Install, Idempotenz,
  Teilzustand und Fehlerabbruch prüft.
- **Änderung:** `build.sh` — das Verzeichnis `scripts/` (nur `*.php`) in
  `dist/dog-mentality-test/scripts/` kopieren, damit der Runner Teil des
  Deploy-Pakets ist. `scripts/migrate-selftest.php` wird **nicht**
  ausgeliefert.

Bewusst **nicht**: `mysql`-CLI-Aufruf (siehe design.md, D2), separate
DB-Secrets, Einbindung in den CI-Workflow, PHPUnit, Änderungen an `wizard/`.

## Capabilities

### New Capabilities

- `database-migrations`: Nicht-interaktiver, idempotenter Migrations-Runner,
  der nummerierte SQL-Migrationsdateien in Reihenfolge anwendet, den Fortschritt
  in einer Tracking-Tabelle mit Tabellenpräfix verfolgt und für den Aufruf per
  SSH im Deploy geeignet ist.

### Modified Capabilities

_Keine — `openspec/specs/` ist leer; `wizard/` wird nicht geändert._

## Impact

- **Neu:** `scripts/MigrationRunner.php`, `scripts/migrate.php`,
  `scripts/migrate-selftest.php`
- **Geändert:** `build.sh` (Kopie von `scripts/*.php` ins dist-Paket)
- **Laufzeit-Voraussetzung:** PHP-CLI auf dem Zielserver mit `ext-mysqli`.
  Für die Produktion (alfahosting, SSH-fähiger Managed-Tarif) hat der User am
  2026-08-30 bestätigt, dass SSH inkl. php-CLI (mit `ext-mysqli`) und `rsync`
  serverseitig verfügbar sind. **Keine** zusätzlichen GitHub-Secrets.
- **Nachgelagert:** `add-production-deploy-workflow` ruft
  `php scripts/migrate.php` per SSH auf.
- **Bewusste Duplizierung:** Der Runner dupliziert einen Teil der
  Migrations-Logik aus `wizard/WizardHelper.php`, um den Wizard nicht
  anzufassen und die Server-Entkopplung (Wizard wird gelöscht) zu
  gewährleisten. Eine spätere Zusammenführung in eine gemeinsame Klasse ist
  als Folge-Aufgabe notiert (design.md, Open Questions).
- **Merge-Reihenfolge:** `build.sh` wird auch von `add-ci-workflow` angefasst
  — empfohlene Reihenfolge A → B → C.
