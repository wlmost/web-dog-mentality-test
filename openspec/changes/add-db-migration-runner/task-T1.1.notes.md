# Task 1.1 — `scripts/MigrationRunner.php` implementieren

## Zusammenfassung

Neue Datei `scripts/MigrationRunner.php`: reine PHP-Klasse (`declare(strict_types=1)`,
kein Namespace — konsistent mit `wizard/WizardHelper.php`), rein statisch, mysqli-basiert,
**ohne** `require`/Abhängigkeit auf `wizard/`. Implementiert exakt die in der Task
geforderten Methoden:

- `getAvailableMigrations(): array` — `glob(__DIR__ . '/../database/migrations/*.sql')`,
  numerisch aufsteigend sortiert via `usort()` mit `(int)getMigrationVersion()`.
- `getMigrationVersion(string $filePath): string` — führender `\d+`-Teil des Dateinamens
  (`preg_match('/^(\d+)/', ...)`), Fallback auf den vollen Basisnamen.
- `getMigrationDescription(string $filePath): string` — Zeile `-- Description: …`,
  Fallback `basename($filePath)`.
- `ensureTrackingTable(mysqli $conn, string $prefix): void` — `CREATE TABLE IF NOT EXISTS
  <prefix>schema_migrations` mit der DDL 1:1 aus `database/schema.sql:208-213`.
- `getAppliedVersions(mysqli $conn, string $prefix): array`.
- `executeSqlFile(mysqli $conn, string $filePath, string $prefix): array` — Semantik 1:1
  aus `WizardHelper::executeSqlFile()` + `splitSqlStatements()`: `{{PREFIX}}` per
  `str_replace`, Kommentarzeilen (`--`/`#`) verwerfen, an Zeilenende-`;` splitten,
  Statements einzeln über **eine** Verbindung ausführen (Session-Variablen/PREPARE-
  EXECUTE-Ketten bleiben erhalten). Rückgabe um `error`/`failedStatement` erweitert
  (strukturierte Felder statt nur Log-Strings), damit `scripts/migrate.php` (T2.1) die
  geforderte Fehlermeldung „Dateiname, mysqli-Fehlertext, gekürztes Statement" bauen kann.
- `recordMigration(mysqli $conn, string $prefix, string $version, string $description): void`
  — Prepared `INSERT INTO <prefix>schema_migrations (version, description) VALUES (?, ?)`.
- `runPending(mysqli $conn, string $prefix): array` — liest bereits angewendete Versionen,
  iteriert `getAvailableMigrations()` in numerischer Reihenfolge, überspringt bereits
  eingetragene Versionen, führt ausstehende aus und trägt sie bei Erfolg ein. Bricht beim
  ersten Fehler **sofort** ab, trägt die fehlgeschlagene Version **nicht** ein und gibt
  `failedVersion`/`failedFile`/`failedError`/`failedStatement` zurück; vorher erfolgreich
  angewendete Migrationen bleiben im `applied`-Rückgabewert und in der DB verbucht.

Kopf-Kommentar der Datei verweist explizit auf `wizard/WizardHelper.php` als Quelle der
duplizierten Logik und auf die in `design.md` (Open Questions) vorgesehene Folge-Aufgabe
zur Zusammenführung.

## Wichtiger technischer Fund (dokumentiert, nicht behoben — außerhalb des Task-Scopes)

Seit PHP 8.1 ist der mysqli-Default-Report-Modus `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`:
`mysqli::query()`/`prepare()` **werfen** bei Fehlern eine `mysqli_sql_exception`, statt
`false` zurückzugeben. Der 1:1 aus `WizardHelper` übernommene Code-Stil
(`if (!$conn->query($statement))`) würde unter PHP ≥ 8.1 ohne Weiteres zu einem
**uncaught Fatal Error** statt zu einem kontrollierten Abbruch führen — das hätte die
Akzeptanzkriterien „stoppt sofort ohne Eintrag" zwar noch erfüllt (es wird nichts
eingetragen), aber nicht die geforderte saubere Fehlermeldung/den definierten Exit-Code
für den CLI-Runner (T2.1) ermöglicht.

Da dieselbe Schwäche in `wizard/WizardHelper.php` unverändert vorhanden ist und `wizard/`
laut Task **nicht** angefasst werden darf, wurde **nur** `MigrationRunner.php` gehärtet:
ein privater Helfer `runQuery()` fängt `mysqli_sql_exception` ab und normalisiert das
Ergebnis auf das klassische `false`-Return-Verhalten (keine globale Änderung von
`mysqli_report()`, um Seiteneffekte auf die vom Aufrufer verwaltete Verbindung zu
vermeiden). `recordMigration()` fängt dieselbe Exception ebenfalls ab und wirft eine
`RuntimeException`. Dadurch verhält sich der Runner unabhängig von PHP-Version/
mysqli-Report-Modus deterministisch.

## Zweiter Fund (rein informativ, nicht Teil dieser Task)

Beim funktionalen Testlauf gegen ein aus `database/schema.sql` erzeugtes Schema bricht
`003_add_avatar_to_users.sql` real ab: die Migration referenziert die Tabelle
`{{PREFIX}}auth_users`, `database/schema.sql` definiert aber `{{PREFIX}}users`
(`database/schema.sql:113`). Dies ist ein Inhalts-Mismatch der Migrationsdatei bzw. des
Schemas — außerhalb des Scopes von T1.1 (keine Änderung an `database/migrations/` oder
`database/schema.sql` erlaubt) und wird hier nur dokumentiert, damit es in einem
Folge-Schritt (T2.1-Verifikation oder eigener Fix) berücksichtigt werden kann.

## Verifikation

- `php -l scripts/MigrationRunner.php` → fehlerfrei.
- `grep -n wizard scripts/MigrationRunner.php` → nur Kommentar-Erwähnungen, keine
  `require`/Klassenreferenz.
- Reine PHP-Prüfung (kein DB-Zugriff) mit temporären Migrationsdateien `002_two.sql` und
  `010_ten.sql`: `getAvailableMigrations()` liefert `002` vor `010` (numerisch, nicht
  lexikografisch).
- Funktionaler End-to-End-Test gegen einen kurzlebigen `mysql:8.0`-Docker-Container
  (`docker run --name mrtest-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=mrtest
  -p 33061:3306 mysql:8.0`, danach `docker rm -f mrtest-mysql` — kein Container hinterlassen):
  - Frischer Lauf wendet synthetische Migrationen `001`, `002` (mit
    `SET @var`/`PREPARE`/`EXECUTE`/`DEALLOCATE`-Guard, analog 001–003) und `010`
    (inkl. `{{PREFIX}}` in `CONCAT('{{PREFIX}}', 'seed')`) in numerischer Reihenfolge an,
    Tracking-Tabelle enthält danach `001, 002, 010`.
  - Wiederholter Lauf: 0 angewendet, alle drei übersprungen.
  - Teilzustand (`DELETE ... WHERE version = '010'`): nur `010` wird erneut angewendet.
  - Absichtlich kaputte Zusatzmigration `099_broken.sql` (`INSERT` in nicht existierende
    Tabelle): `runPending` liefert `failedVersion === '099'`, `099` wird **nicht**
    eingetragen, `001/002/010` bleiben eingetragen.
  - Zusätzlich gegen die realen Dateien `database/migrations/001-003` mit echtem, aus
    `database/schema.sql` geladenem Schema geprüft: `001`/`002` (Guard-Migrationen mit
    Session-Variablen) laufen fehlerfrei durch; `003` schlägt wegen des oben genannten
    Schema-Mismatches (`auth_users` vs. `users`) fehl — Fehlerbehandlung selbst
    funktioniert korrekt (kontrollierter Abbruch, kein Eintrag).

## Nicht Teil dieser Task (bewusst ausgelassen)

- `scripts/migrate.php` (T2.1), `scripts/migrate-selftest.php` (T3.1), `build.sh` (T4.1).
- Keine Änderung an `wizard/`, `database/migrations/`, `database/schema.sql`.
- Kein Commit (laut Vorgabe durch den aufrufenden Agenten).
