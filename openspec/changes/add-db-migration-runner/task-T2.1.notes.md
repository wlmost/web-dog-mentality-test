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
- **Korrigiert (Review-Nacharbeit, siehe unten):** Die Verbindung wird
  **nicht** mehr über einen `finally`-Block geschlossen. Die ursprüngliche
  Begründung „PHP garantiert, dass `finally` vor der tatsächlichen
  Skript-Terminierung noch ausgeführt wird" war **falsch** — `exit()`
  innerhalb eines `try`- oder `catch`-Blocks überspringt den zugehörigen
  `finally`-Block (verifiziert: `php -r 'try { exit(3); } finally { echo
  "läuft"; }'` gibt „läuft" nicht aus). Der `finally { $conn->close(); }`
  war damit toter Code. Stattdessen schließen jetzt `migrateFail()`
  (optionaler `?mysqli $conn`-Parameter) und die neue `migrateExit(mysqli
  $conn): void` (Pendant für die Erfolgspfade, `exit(0)`) die Verbindung
  explizit an jeder Austrittsstelle innerhalb bzw. nach dem `try`-Block.
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

## Review-Nacharbeit (Sollte-Befunde aus `task-T2.1.review.md`)

Alle fünf „Sollte"-Befunde des Reviews wurden geprüft; drei sind im Code
behoben, zwei sind bewusst dokumentiert belassen (Begründung siehe unten).

1. **Toter `finally`-Block (behoben).** `finally { $conn->close(); }` wurde
   entfernt, da er wegen der `exit()`-Aufrufe in jedem `try`/`catch`-Pfad nie
   erreicht wurde (siehe Korrektur oben unter „Sonstige
   Design-Entscheidungen"). Ersetzt durch:
   - `migrateFail(string $message, ?mysqli $conn = null): void` — schließt
     `$conn`, falls übergeben, bevor `exit(1)`.
   - `migrateExit(mysqli $conn): void` — schließt `$conn` und `exit(0)`
     (Pendant für die beiden Erfolgspfade Dry-Run/`MIGRATE OK`).
   Alle Aufrufstellen innerhalb und nach dem `try`-Block wurden entsprechend
   angepasst (`exit(0)` → `migrateExit($conn)`, `migrateFail(...)` →
   `migrateFail(..., $conn)`, `catch (Throwable $exception)` →
   `migrateFail('MIGRATE FAIL: ' . $exception->getMessage(), $conn)`).

2. **Query-Fehler bei `SHOW TABLES LIKE` im Dry-Run (behoben).** Vor der
   `$tableCheck instanceof mysqli_result`-Prüfung wird jetzt explizit auf
   `$tableCheck === false` geprüft (echter Query-Fehler, z. B. fehlendes
   `SHOW`-Privileg) und in diesem Fall über `migrateFail('MIGRATE FAIL:
   Prüfung auf Tracking-Tabelle fehlgeschlagen: ' . $conn->error, $conn)`
   mit Exit 1 terminiert, statt den Fehler stillschweigend als „Tabelle
   existiert nicht" zu interpretieren. Verifiziert über eine isolierte
   `mysqli`-Subklasse, die `query()` gezielt für die `SHOW TABLES
   LIKE`-Anweisung `false` zurückgeben lässt (echte Docker-Verbindung im
   Übrigen) — bestätigt, dass der `=== false`-Zweig korrekt anspringt.

3. **DRY (`$dbPrefix . 'schema_migrations'` dupliziert `MigrationRunner`,
   bewusst belassen).** Eine Konsolidierung würde eine neue, rein lesende
   Methode in `scripts/MigrationRunner.php` erfordern (z. B.
   `trackingTableExists(mysqli, prefix): bool`), da `ensureTrackingTable()`
   die Tabelle ggf. anlegt und im Dry-Run nicht aufgerufen werden darf. Diese
   Korrekturaufgabe ist explizit auf die Datei `scripts/migrate.php`
   begrenzt; eine Änderung an `MigrationRunner.php` liegt außerhalb dieses
   Datei-Scopes. Als Nachtrag zu Task 1.1 oder eigene Folge-Task empfohlen
   (im Code an der betroffenen Stelle kommentiert).

4. **`recordMigration()`-Fehler landet im generischen `catch
   (Throwable)`-Zweig statt im `MIGRATE FAIL bei NNN: …`-Format (bewusst
   belassen, mit Klarstellung).** Geprüft und empirisch gegen Docker
   verifiziert: Schlägt `MigrationRunner::recordMigration()` fehl, reicht
   `runPending()` die `RuntimeException` ungefangen durch, und `migrate.php`
   fängt sie im generischen `catch (Throwable $exception)`-Zweig. Die
   Versionsnummer ist dabei **nicht garantiert** im Meldungstext enthalten:
   - Schlägt bereits `$conn->prepare()` fehl, lautet die Meldung „Prepare
     fehlgeschlagen: …" **ohne** Versionsnummer (verifiziert durch Entzug
     des `INSERT`-Rechts auf die Tracking-Tabelle in einem Test-Container:
     `MIGRATE FAIL: Prepare fehlgeschlagen: INSERT command denied to user
     'dmt'@'…' for table 'dmt_schema_migrations'`).
   - Schlägt erst `$stmt->execute()` fehl (z. B. durch einen simulierten
     `BEFORE INSERT`-Trigger im Test-Container), enthält die Meldung die
     Versionsnummer im Fließtext: `MIGRATE FAIL: Migration 004 konnte nicht
     in \`dmt_schema_migrations\` eingetragen werden: Simulierter
     Trigger-Fehler für Test`.
   In beiden Fällen: Exit-Code 1 korrekt, keine Falscheintragung, aber kein
   exaktes `MIGRATE FAIL bei NNN: <fehler>`-Format gemäß `design.md` D6. Eine
   strukturelle Angleichung müsste in `runPending()`
   (`scripts/MigrationRunner.php`) selbst erfolgen (dort müsste die
   `RuntimeException` aus `recordMigration()` abgefangen und auf
   `failedVersion`/`failedError` abgebildet werden) — außerhalb des
   Datei-Scopes dieser Korrektur. Im Code an der `catch`-Stelle kommentiert;
   als Nachtrag zu Task 1.1 empfohlen (Formulierung so auch im Review
   vorgeschlagen).

5. **`@`-Suppression bei `real_connect()` (geprüft, nicht entfernt —
   Review-Annahme widerlegt).** Die im Review geäußerte Vermutung, dass
   `mysqli_report(MYSQLI_REPORT_OFF)` die `@`-Unterdrückung überflüssig
   macht, wurde empirisch geprüft und ist **falsch**:
   `MYSQLI_REPORT_OFF` unterdrückt zwar die seit PHP 8.1 standardmäßig
   geworfene `mysqli_sql_exception`, nicht aber die von `real_connect()` bei
   einem Verbindungsfehler zusätzlich ausgelöste PHP-`E_WARNING`. Verifiziert
   mit `php -r 'mysqli_report(MYSQLI_REPORT_OFF); mysqli_init()
   ->real_connect("127.0.0.1", "nouser", "nopass", "nodb", 1);'` (PHP
   8.3.30, lokal) — es erscheint trotz `MYSQLI_REPORT_OFF` eine
   `PHP Warning: mysqli::real_connect(): (HY000/2002): Connection refused`.
   Das `@` bleibt daher bestehen, jetzt aber mit einem Inline-Kommentar direkt
   über der Zeile begründet (inkl. der Verifikationsanweisung), statt die
   Begründung nur in den Notes zu verstecken.

## Verifikation der Review-Nacharbeit

- `php -l scripts/migrate.php` → fehlerfrei.
- Isolierter PHP-Test (`mysqli`-Subklasse mit überschriebenem `query()`)
  gegen einen kurzlebigen `mysql:8.0`-Docker-Container: bestätigt, dass der
  `$tableCheck === false`-Zweig (Punkt 2) korrekt erkannt wird.
- Vollständiger funktionaler Lauf gegen denselben Docker-Container
  (`database/schema.sql` mit Präfix `dmt_` eingespielt, `DB_PREFIX=dmt_`):
  - Dry-Run vor jedem Lauf, echter Lauf, Dry-Run danach, Wiederholungslauf
    (Idempotenz) — alle wie erwartet (`MIGRATE OK: …`, `Keine ausstehenden
    Migrationen.`), keine Regression durch die Umstellung von `exit()`/
    `finally` auf `migrateFail()`/`migrateExit()`.
  - Fehlerlauf bei 003 (vorbestehender, aufgabenfremder
    `auth_users`/`auth_logs`-Datenmodell-Mismatch, siehe Hinweis für
    Folge-Tasks) weiterhin mit korrektem `MIGRATE FAIL bei 003: …`, Exit 1.
  - Punkt 4 gezielt reproduziert: einmal durch Entzug des `INSERT`-Rechts auf
    `dmt_schema_migrations` (Prepare schlägt fehl), einmal durch einen
    `BEFORE INSERT`-Trigger (Execute schlägt fehl) — beide Male korrekt als
    `MIGRATE FAIL: …` über den generischen `catch`-Zweig mit Exit 1 beendet,
    keine Fehleintragung in der Tracking-Tabelle, DDL-Teil der Migration
    (`CREATE TABLE IF NOT EXISTS`) blieb bestehen (idempotent beim nächsten
    Lauf erneut ausführbar).
  - Container (`migrate-fix-test-mysql`) und temporäre Testdateien danach
    entfernt.

## Nicht angefasst

- `scripts/MigrationRunner.php` (Task 1.1, nur genutzt, nicht verändert).
- `scripts/migrate-selftest.php` (Task 3.1).
- `build.sh` (Task 4.1).
