# Notes — Task 3.1: `scripts/migrate-selftest.php`

## Umgesetzt

- Neue Datei `scripts/migrate-selftest.php`: Selbsttest ohne PHPUnit,
  gegen eine per Umgebungsvariablen konfigurierte Wegwerf-Datenbank.
- Env-Variablen (alle optional, mit sinnvollen lokalen Defaults, analog zu
  `tests/migration-runner-test.php`): `MIGRATE_TEST_HOST` (127.0.0.1),
  `MIGRATE_TEST_PORT` (3306), `MIGRATE_TEST_USER` (root), `MIGRATE_TEST_PASS`
  (''), `MIGRATE_TEST_NAME` (`migrate_selftest`), `MIGRATE_TEST_PREFIX`
  (`dmt_`).
- Ablauf:
  1. Admin-Verbindung (`mysqli`, ohne DB) herstellen; fehlt sie → `exit(2)`
     (Infrastruktur-Fehler, keine Testaussage — analog
     `tests/migration-runner-test.php`).
  2. Testdatenbank per `DROP DATABASE IF EXISTS` / `CREATE DATABASE` frisch
     anlegen und selektieren.
  3. Basis-Schema einspielen: `database/schema.sql` **und**
     `database/schema-auth.sql` (identische Reihenfolge wie
     `wizard/install.php:82-83`) über die bereits vorhandene
     `MigrationRunner::executeSqlFile()` — bewusst wiederverwendet statt
     eigener SQL-Split-Logik (DRY, exakt dieselbe Statement-Semantik).
     Ohne `schema-auth.sql` würde Migration 003
     (`ALTER TABLE {{PREFIX}}auth_users ADD COLUMN avatar`) an der
     fehlenden Tabelle `auth_users` scheitern — siehe „Design-Lücke"-Absatz
     in `task-T2.1.notes.md` sowie die neue Gegenprobe „Gruppe D" in
     `tests/migration-runner-test.php`, die genau das bestätigt. Das ist
     **kein** Defekt des Runners, sondern folgt dem realen Installer-Ablauf.
  4. Temporäre `config.local.php` in einem `sys_get_temp_dir()`-Unterordner
     schreiben (Format analog `WizardHelper::generateConfigContent()`, nur
     die für `migrate.php` nötigen `DB_*`-Konstanten).
  5. Vier Szenarien aus `design.md` D8 gegen `scripts/migrate.php` als
     eigenständigen Subprozess (`proc_open`, `PHP_BINARY`) prüfen:
     - **Szenario 1** (Fresh-Install): erster Lauf → Exit 0,
       `<prefix>schema_migrations` enthält 001–005, Ausgabe „5 angewendet,
       0 übersprungen".
     - **Szenario 2** (Idempotenz): zweiter Lauf → Exit 0, „0 angewendet,
       5 übersprungen".
     - **Szenario 3** (Teilzustand): nach
       `DELETE FROM schema_migrations WHERE version='005'` → dritter Lauf
       wendet ausschließlich 005 erneut an, Exit 0, „1 angewendet,
       4 übersprungen".
     - **Szenario 4** (Fehlerabbruch): isoliertes Test-Temp-Verzeichnis mit
       Kopien der fünf echten Migrationsdateien **plus** einer
       zusätzlichen, absichtlich kaputten `999_broken.sql`
       (`INSERT INTO {{PREFIX}}tabelle_die_es_nicht_gibt ...`) → Exit 1,
       Ausgabe nennt „MIGRATE FAIL bei 999", Version `999` wird **nicht**
       in `schema_migrations` eingetragen, 001–005 bleiben unverändert
       eingetragen. `database/migrations/` wird dabei **nicht** angefasst
       (siehe Design-Entscheidung unten).
  6. Aufräumen über `register_shutdown_function` (läuft auch bei Fatal
     Error/uncaught Exception): Testdatenbank per `DROP DATABASE IF EXISTS`
     entfernen, alle Temp-Verzeichnisse/-Dateien rekursiv löschen.
- Eigene, kompakte Test-Harness (`pass()`/`fail()`/`assertTrue()`/
  `assertSame()`/`assertContains()`, PASS/FAIL-Zähler, farbige Ausgabe) —
  bewusst dupliziert statt aus `tests/migration-runner-test.php` importiert:
  `scripts/migrate-selftest.php` soll als eigenständiges Skript ohne
  Abhängigkeit auf `tests/` funktionieren (unterschiedlicher Ausführungsort/
  Zielgruppe — Entwickler-Tool direkt neben `migrate.php`, nicht Teil der
  `tests/`-Suite). Sehr kleiner Umfang (5 Hilfsfunktionen), daher kein
  DRY-Verstoß, der eine gemeinsame Bibliothek rechtfertigen würde (YAGNI).

## Design-Lücke: `getAvailableMigrations()` ohne Override-Möglichkeit

**Problem:** Für Szenario 4 wird ein isolierter Test-Migrationspfad
benötigt (`database/migrations/` darf nicht verändert werden), aber
`MigrationRunner::getAvailableMigrations()` hatte den Pfad
(`__DIR__ . '/../database/migrations/'`) hart kodiert, ohne Override.
`runPending()` ruft `getAvailableMigrations()` intern auf.

**Entscheidung: Option A — optionaler Parameter, 100 % abwärtskompatibel.**

- `scripts/MigrationRunner.php`:
  - `getAvailableMigrations(?string $migrationsDir = null): array` — bei
    `null` (Default) exakt das bisherige Verhalten (hart kodierter Pfad).
    Bei gesetztem Wert: `rtrim($migrationsDir, '/') . '/'` + `glob()`.
  - `runPending(mysqli $conn, string $prefix, ?string $migrationsDir = null): array`
    — reicht den Parameter unverändert an `getAvailableMigrations()` durch.
  - Beide Signatur-Erweiterungen sind rein additiv (neuer optionaler
    Parameter am Ende, Default `null`) — kein bestehender Aufrufer muss
    angepasst werden.
- `scripts/migrate.php`:
  - Liest eine **testinterne** Umgebungsvariable `MIGRATE_MIGRATIONS_DIR`
    (nur wirksam, wenn gesetzt; Default-Verhalten unverändert). Wird an
    beiden Aufrufstellen von `getAvailableMigrations()`/`runPending()`
    durchgereicht (Dry-Run-Zweig und Normalbetrieb).
  - Bewusst **nicht** Teil der dokumentierten CLI-Schnittstelle
    (`design.md` D6 kennt nur `--dry-run` und den Config-Pfad-Parameter):
    im Kopfkommentar von `migrate.php` und an der Leseposition inline
    kommentiert, dass die Variable ausschließlich für
    `scripts/migrate-selftest.php` (T3.1) gedacht ist.

**Begründung für Option A statt B (z. B. Konstruktor-Injection oder eigene
Runner-Konfigurationsklasse):** Der Änderungsbedarf ist rein testgetrieben
und minimal — ein optionaler, standardmäßig inaktiver Parameter erfüllt
YAGNI/KISS, ohne die öffentliche API/das Verhalten für den
Produktions-Pfad (`php scripts/migrate.php` ohne Env-Variable) in irgendeiner
Weise zu verändern. Eine Konfigurationsklasse wäre für einen einzelnen
Pfad-Override unverhältnismäßiger Mehraufwand (Over-Engineering) gewesen.

**Verifikation, dass Task 1.1/2.1 nicht regressiert sind:**
- `php -l scripts/MigrationRunner.php` und `php -l scripts/migrate.php` →
  fehlerfrei.
- `tests/migration-runner-test.php` (T1.1, bereits vorhanden) läuft
  gegen einen `mysql:8.0`-Docker-Container weiterhin **vollständig grün**:
  50 bestanden, 0 fehlgeschlagen (inkl. Gruppen A–D, die genau die
  Signaturen `getAvailableMigrations()`/`runPending()` ohne den neuen
  Parameter aufrufen — bestätigt Abwärtskompatibilität).
- Funktionaler Test von `scripts/migrate.php` (T2.1) gegen denselben
  Container manuell wiederholt (Dry-Run, Erfolgslauf, Idempotenz,
  Fehlerlauf) — unverändertes Verhalten, keine Regression durch die neuen
  optionalen Parameter.

## Verifikation (Task 3.1 selbst)

- `php -l scripts/migrate-selftest.php` → fehlerfrei.
- **Docker-Setup:**
  `docker run -d --name migrate-selftest-mysql -e MYSQL_ROOT_PASSWORD=root -p 33062:3306 mysql:8.0`,
  gewartet bis `mysqladmin ping` erfolgreich.
- **Grüner Lauf gegen leere DB:**
  `MIGRATE_TEST_HOST=127.0.0.1 MIGRATE_TEST_PORT=33062 MIGRATE_TEST_USER=root MIGRATE_TEST_PASS=root php scripts/migrate-selftest.php`
  → alle 18 Assertions bestanden, `EXIT: 0`.
- **Aufräumen verifiziert:** nach dem Lauf `SHOW DATABASES LIKE
  'migrate_selftest'` → leer; `ls /tmp | grep migrate-selftest` → keine
  Treffer (keine liegen gebliebenen Temp-Verzeichnisse/-Dateien).
- **Akzeptanzkriterium „bei absichtlich gebrochenem Runner meldet der
  Selbsttest Exit 1" gezielt reproduziert:** `scripts/migrate.php` temporär
  sabotiert (`$result['failedVersion'] = null;` direkt nach
  `runPending()`, simuliert einen Runner, der einen Fehler verschluckt),
  Selbsttest erneut ausgeführt → **korrekt** 2 von 18 Assertions
  fehlgeschlagen (Szenario 4: „endet mit Exit-Code 1" und „nennt Version
  999" schlagen fehl, weil der sabotierte Runner fälschlich `MIGRATE OK: 0
  angewendet, 5 übersprungen` mit Exit 0 meldet), Gesamt-`EXIT: 1`. Direkt
  danach die Sabotage aus `scripts/migrate.php` wieder entfernt (Diff auf
  den Stand meines legitimen Edits zurückgesetzt), `php -l` erneut
  fehlerfrei, Selbsttest erneut vollständig grün (18/18, `EXIT: 0`) und
  `tests/migration-runner-test.php` erneut vollständig grün (50/50)
  gegen denselben Container laufen lassen — bestätigt, dass keine
  Restspuren der Sabotage übrig geblieben sind.
- Docker-Container danach entfernt (`docker rm -f migrate-selftest-mysql`).

## Sonstige Design-Entscheidungen

- **`schema.sql` + `schema-auth.sql` als Vorbedingung, nicht Teil der
  vier D8-Szenarien selbst:** Die Spec (`Requirement: Unabhängig vom
  Deploy testbarer Selbsttest`) beschreibt die vier Migrations-Szenarien,
  setzt aber implizit eine korrekt installierte Basis voraus (genau wie
  der reale Deploy: Schema wird einmalig vom Wizard installiert, danach
  laufen nur noch Migrationen). Das Einspielen von `schema.sql`/
  `schema-auth.sql` wird daher als Vorbedingung mit eigenen Assertions
  geprüft (schlägt sie fehl, bricht das Skript mit `exit(1)` ab, bevor die
  vier eigentlichen Szenarien überhaupt versucht werden).
- **`proc_open` statt `exec()`:** gewählt, weil `proc_open` STDOUT und
  STDERR getrennt einsammelt (nötig, um `MIGRATE FAIL bei ...` — geht auf
  STDERR — von der Log-Ausgabe auf STDOUT zu unterscheiden) und die
  Kindprozess-Umgebung (`$env`-Parameter) explizit um
  `MIGRATE_MIGRATIONS_DIR` erweitern kann, ohne den Prozess der
  aufrufenden Shell dauerhaft zu verändern.
- **Isoliertes Migrationsverzeichnis für Szenario 4 wird aus den ECHTEN
  Migrationsdateien kopiert** (`MigrationRunner::getAvailableMigrations()`
  ohne Override, dann `copy()`), nicht aus synthetischen Test-Dateien:
  dadurch bleibt der Test robust gegenüber künftigen Änderungen an
  `database/migrations/` (z. B. einer neuen Migration 006) — es wird
  immer der tatsächlich aktuelle Satz plus eine zusätzliche kaputte Datei
  geprüft.
- **Version `999` für die kaputte Zusatzmigration:** hoch genug, um nicht
  mit realen/künftigen Versionsnummern zu kollidieren, numerisch nach
  allen echten Migrationen sortiert (wird also zuletzt versucht, nachdem
  001–005 bereits als „übersprungen" erkannt wurden).
- Fehlermeldungen/Ausgaben des Selbsttests selbst (PASS/FAIL) auf STDOUT
  mit ANSI-Farbcodes, analog `tests/migration-runner-test.php` (konsistenter
  Stil im Projekt für Nicht-PHPUnit-Testskripte).

## Review-Korrekturen (task-T3.1.review.md, Sollte-/Könnte-Befunde)

Nach dem Review (`task-T3.1.review.md`) wurden folgende Punkte korrigiert:

1. **[Sollte, Robustheit] `scripts/migrate.php`: Sichtbarer Hinweis bei
   `MIGRATE_MIGRATIONS_DIR`-Override.** Direkt nach dem Einlesen der
   testinternen Umgebungsvariable wird jetzt, falls sie gesetzt ist, eine
   Zeile auf STDERR ausgegeben:
   `HINWEIS: MIGRATE_MIGRATIONS_DIR aktiv -- Migrationsverzeichnis überschrieben: <pfad>`.
   Damit ist ein versehentlich auf dem Deploy-Host aktiver Override in
   jedem Deploy-Log sichtbar, bevor `MigrationRunner::runPending()`/
   `getAvailableMigrations()` überhaupt aufgerufen werden. Ohne gesetzte
   Variable erscheint keine zusätzliche Ausgabe (verifiziert: Lauf ohne und
   mit `MIGRATE_MIGRATIONS_DIR` gegenübergestellt, Hinweiszeile erscheint
   ausschließlich im zweiten Fall). Kein CLI-Flag eingeführt (bewusst kein
   Scope-Ausbau der dokumentierten CLI-Schnittstelle, wie im Review als
   Alternative vorgeschlagen).
2. **[Sollte, Sicherheit] `scripts/migrate-selftest.php`: `0700` statt
   `0777` für das Temp-Config-Verzeichnis.** `mkdir($tmpConfigDir, ...)`
   (enthält `config.local.php` mit `DB_PASS` im Klartext) verwendet jetzt
   `0700` statt `0777` — die Berechtigung hängt damit nicht mehr von der
   lokalen `umask`-Konfiguration ab. Verifiziert per Docker-Lauf mit
   `TMPDIR`-Override: `ls -ld` zeigt `drwx------` für das erzeugte
   Verzeichnis. Das zweite, im Review als "optional/unkritisch" markierte
   Verzeichnis (`$isolatedMigrationsDir`, Szenario 4, enthält nur
   SQL-Dateiinhalte ohne Zugangsdaten) bewusst unverändert bei `0777`
   belassen.
3. **[Könnte, Korrektheit] `scripts/migrate-selftest.php`: `real_escape_string()`
   auf einem Backtick-Identifier ersetzt.** Neue Hilfsfunktion
   `quoteIdentifier(string $identifier): string`, die den Bezeichner gegen
   das Whitelist-Pattern `^[A-Za-z0-9_]+$` validiert (bei Verstoß
   `InvalidArgumentException`) und Backtick-quotiert zurückgibt. Ersetzt
   alle drei Stellen, an denen zuvor `$admin->real_escape_string($dbName)`
   innerhalb von Backticks für `DROP DATABASE`/`CREATE DATABASE` verwendet
   wurde (Haupt-Ablauf sowie die identische Stelle in der
   `register_shutdown_function()`-Aufräumroutine) — `real_escape_string()`
   ist für String-Literal-Kontexte gedacht, nicht für Bezeichner-Kontexte.
   `$dbName` bleibt weiterhin ausschließlich entwicklerkontrolliert
   (`MIGRATE_TEST_NAME`), kein externer Eingabekanal.

**Verifikation der Korrekturen:**
- `php -l scripts/migrate.php`, `php -l scripts/migrate-selftest.php`,
  `php -l scripts/MigrationRunner.php` (unverändert, nur als Regressionscheck
  mitgeprüft) → alle drei fehlerfrei.
- Docker-Setup (`mysql:8.0`, Port 33063, danach entfernt):
  `scripts/migrate-selftest.php` läuft weiterhin vollständig grün
  (**18/18 bestanden**, `EXIT: 0`), inklusive der jetzt Backtick-sicher
  quotierten `DROP DATABASE`/`CREATE DATABASE`-Aufrufe.
- `tests/migration-runner-test.php` läuft gegen denselben Container
  weiterhin vollständig grün (**50/50 bestanden**, `EXIT: 0`) — keine
  Regression durch die Korrekturen.
- Aufräumen nach dem Selbsttest-Lauf erneut verifiziert: keine
  liegen gebliebene `migrate_selftest`-Datenbank, keine
  `migrate-selftest-*`-Temp-Verzeichnisse.
- Docker-Container danach entfernt (`docker rm -f`).

**Bewusst zurückgestellt (Könnte/Sollte-Punkt, nicht behoben):**
Der Sollte-Punkt zu fehlendem `SIGINT`/`SIGTERM`-Cleanup
(`scripts/migrate-selftest.php:145-159`, nur `register_shutdown_function`,
kein `pcntl_signal()`-Handler) wird **nicht** umgesetzt. Begründung:
`scripts/migrate-selftest.php` ist ein manuell/lokal ausgeführtes
Entwickler-Tool (siehe Kopfkommentar: "reines Entwickler-/CI-Werkzeug",
bewusst nicht Teil von `build.sh`/Deploy-Paket), kein Bestandteil der
automatisierten Deploy-Pipeline. Ein `pcntl`-Signal-Handler würde eine
zusätzliche, optionale PHP-Extension voraussetzen und zusätzliche
Komplexität (Signal-Handler-Registrierung, Race-Bedingungen zwischen
Handler und Shutdown-Function) für einen Fall einführen, der ausschließlich
bei manuellem Ctrl-C während eines lokalen/CI-Testlaufs auftritt — das wäre
Overengineering für diesen Scope (YAGNI). Ein harter Abbruch per Ctrl-C
während eines Selbsttest-Laufs bleibt ein bekannter, akzeptierter
Sonderfall: die Testdatenbank (`MIGRATE_TEST_NAME`, Default
`migrate_selftest`) und ggf. `migrate-selftest-*`-Temp-Verzeichnisse unter
`sys_get_temp_dir()` müssten dann manuell entfernt werden
(`DROP DATABASE IF EXISTS <name>;` bzw. `rm -rf`).

## Nicht angefasst

- `database/migrations/*.sql` — unverändert, wie gefordert.
- `build.sh` (Task 4.1) — `scripts/migrate-selftest.php` wird dort bewusst
  **nicht** aufgenommen (siehe `proposal.md`/`design.md` D7).
- `tests/migration-runner-test.php`, `tests/support/migration-runner-child.php`
  — nur zur Regressionsprüfung ausgeführt, nicht verändert.
- Task 3.2 (Dokumentation/Ausführungsanleitung) — eigene Task, nicht Teil
  dieser Notes.
