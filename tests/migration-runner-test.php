<?php
declare(strict_types=1);

/**
 * Test: openspec/changes/add-db-migration-runner (Task T1.1 -- scripts/MigrationRunner.php)
 *
 * Prueft die MigrationRunner-Klasse UNABHAENGIG von scripts/migrate.php (T2.1,
 * noch nicht implementiert) und UNABHAENGIG von scripts/migrate-selftest.php
 * (T3.1, noch nicht implementiert). Kein PHPUnit -- bewusster Non-Goal des
 * Change (design.md), analog zu tests/ci-workflow-smoke.sh (reine
 * Assertions-Skripte mit PASS/FAIL-Zaehler).
 *
 * Architektur: jede logische Testgruppe laeuft in einem eigenen PHP-
 * Subprozess (via exec()), weil PHP keine zwei "class MigrationRunner"-
 * Definitionen im selben Prozess zulaesst -- pro Gruppe wird aber je nach
 * Szenario entweder eine isolierte Kopie von MigrationRunner.php (mit
 * synthetischen Migrationsdateien, damit die echten database/migrations/*.sql
 * die Tests nicht verfaelschen) oder die ECHTE scripts/MigrationRunner.php
 * geladen. Der generische Subprozess-Runner
 * (tests/support/migration-runner-child.php) fuehrt eine Liste von
 * "Operationen" gegen die geladene Klasse aus und gibt das Ergebnis als
 * JSON zurueck; dieser Prozess selbst enthaelt keine Assertions.
 *
 * Voraussetzung: eine erreichbare, WEGWERFBARE MySQL/MariaDB-Instanz. Das
 * Skript legt ausschliesslich eigene Datenbanken (Praefix "mrtest_") an und
 * loescht sie danach wieder. Es veraendert KEINE Produktivdatenbank.
 *
 * Lokale MySQL 8 per Docker bereitstellen:
 *   docker run -d --name mrtest-mysql -e MYSQL_ROOT_PASSWORD=root \
 *     -p 33061:3306 mysql:8.0
 *   (warten bis "docker exec mrtest-mysql mysqladmin ping -uroot -proot"
 *   erfolgreich ist, dann:)
 *
 * Ausfuehren aus dem Projekt-Root:
 *   MR_TEST_HOST=127.0.0.1 MR_TEST_PORT=33061 MR_TEST_USER=root \
 *     MR_TEST_PASS=root php tests/migration-runner-test.php
 *
 * Danach Container wieder entfernen: docker rm -f mrtest-mysql
 *
 * Env-Variablen (alle optional, Default = lokaler Standard-MySQL):
 *   MR_TEST_HOST  (Default 127.0.0.1)
 *   MR_TEST_PORT  (Default 3306)
 *   MR_TEST_USER  (Default root)
 *   MR_TEST_PASS  (Default '')
 *
 * Exit-Code 0 nur wenn alle Assertions bestehen, sonst 1. Fehlt eine
 * MySQL-Verbindung, bricht das Skript mit Exit-Code 2 ab (Infrastruktur-
 * Fehler, keine Testaussage).
 */

$ROOT = dirname(__DIR__);
$REAL_CLASS_PATH = $ROOT . '/scripts/MigrationRunner.php';
$CHILD_RUNNER = __DIR__ . '/support/migration-runner-child.php';

if (!is_file($REAL_CLASS_PATH)) {
    fwrite(STDERR, "scripts/MigrationRunner.php nicht gefunden -- bitte aus dem Projekt-Root ausfuehren.\n");
    exit(2);
}
if (!is_file($CHILD_RUNNER)) {
    fwrite(STDERR, "tests/support/migration-runner-child.php nicht gefunden.\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Test-Harness (PASS/FAIL, analog tests/ci-workflow-smoke.sh)
// ---------------------------------------------------------------------------

$GLOBALS['__PASS'] = 0;
$GLOBALS['__FAIL'] = 0;
$GLOBALS['__FAILED_NAMES'] = [];

function pass(string $name): void
{
    $GLOBALS['__PASS']++;
    echo "  \033[32m\xE2\x9C\x93 $name\033[0m\n";
}

function fail(string $name, string $detail = ''): void
{
    $GLOBALS['__FAIL']++;
    $GLOBALS['__FAILED_NAMES'][] = $name . ($detail !== '' ? " -- $detail" : '');
    echo "  \033[31m\xE2\x9C\x97 $name" . ($detail !== '' ? " -- $detail" : '') . "\033[0m\n";
}

function assertTrue(bool $cond, string $name, string $detail = ''): void
{
    $cond ? pass($name) : fail($name, $detail);
}

function assertSame($expected, $actual, string $name): void
{
    if ($expected === $actual) {
        pass($name);
    } else {
        fail($name, 'erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true));
    }
}

// ---------------------------------------------------------------------------
// Temp-Verzeichnisse / Aufraeumen
// ---------------------------------------------------------------------------

$GLOBALS['__TMP_DIRS'] = [];

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

register_shutdown_function(static function () {
    foreach ($GLOBALS['__TMP_DIRS'] as $dir) {
        rrmdir($dir);
    }
});

/**
 * Legt eine isolierte Kopie von MigrationRunner.php in einem temporaeren
 * Verzeichnis ab, damit getAvailableMigrations() (glob auf
 * __DIR__ . '/../database/migrations/*.sql', hartkodiert) NICHT die echten
 * Dateien unter database/migrations/ (001-005) mit-einliest, sondern nur die
 * hier uebergebenen synthetischen Migrationen sieht. Es wird ausschliesslich
 * eine unveraenderte Kopie der Produktivdatei an einen anderen Ort gelegt --
 * MigrationRunner.php selbst wird nicht angefasst.
 *
 * @param array<string,string> $migrationFiles Dateiname => Inhalt
 * @return string Pfad zur kopierten scripts/MigrationRunner.php
 */
function isolatedRunner(string $realClassPath, array $migrationFiles): string
{
    $tmp = sys_get_temp_dir() . '/mr-isolated-' . bin2hex(random_bytes(6));
    mkdir($tmp . '/scripts', 0777, true);
    mkdir($tmp . '/database/migrations', 0777, true);
    copy($realClassPath, $tmp . '/scripts/MigrationRunner.php');
    foreach ($migrationFiles as $filename => $content) {
        file_put_contents($tmp . '/database/migrations/' . $filename, $content);
    }
    $GLOBALS['__TMP_DIRS'][] = $tmp;
    return $tmp . '/scripts/MigrationRunner.php';
}

/**
 * Fuehrt eine Liste von Operationen gegen eine (isolierte oder echte)
 * MigrationRunner.php in einem eigenen PHP-Subprozess aus (Begruendung
 * siehe Datei-Kopfkommentar) und liefert die dekodierte JSON-Antwort.
 *
 * @param array<int,array<string,mixed>> $steps
 * @return array<int,array<string,mixed>>
 */
function runChildSteps(string $childRunner, string $classPath, ?string $dbName, string $prefix, array $steps): array
{
    global $DB_HOST, $DB_PORT, $DB_USER, $DB_PASS;

    $params = [
        'classPath' => $classPath,
        'dbHost' => $DB_HOST,
        'dbPort' => $DB_PORT,
        'dbUser' => $DB_USER,
        'dbPass' => $DB_PASS,
        'dbName' => $dbName,
        'prefix' => $prefix,
        'steps' => $steps,
    ];

    $paramsFile = sys_get_temp_dir() . '/mr-params-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($paramsFile, json_encode($params));
    $GLOBALS['__TMP_DIRS'][] = $paramsFile; // rrmdir() ueberspringt Dateien via is_dir()-Check nicht -> separat loeschen
    register_shutdown_function(static function () use ($paramsFile) {
        if (is_file($paramsFile)) {
            unlink($paramsFile);
        }
    });

    $cmd = 'php ' . escapeshellarg($childRunner) . ' ' . escapeshellarg($paramsFile) . ' 2>&1';
    exec($cmd, $output, $code);

    if ($code !== 0) {
        throw new RuntimeException("Subprozess-Fehler (exit $code): " . implode("\n", $output));
    }

    $json = implode("\n", $output);
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Subprozess lieferte kein gueltiges JSON: " . $json);
    }

    return $decoded;
}

/** Findet das erste Ergebnis eines Schritts mit gegebener op in der Subprozess-Antwort. */
function stepResult(array $results, int $index): array
{
    return $results[$index] ?? [];
}

// =============================================================================
echo "\n== php -l scripts/MigrationRunner.php (Akzeptanzkriterium T1.1) ==\n";
// =============================================================================

exec('php -l ' . escapeshellarg($REAL_CLASS_PATH) . ' 2>&1', $lintOut, $lintCode);
assertTrue($lintCode === 0, 'php -l scripts/MigrationRunner.php ist fehlerfrei', implode(' | ', $lintOut));

echo "\n== Keine Abhaengigkeit auf wizard/ (Akzeptanzkriterium T1.1) ==\n";
// Kommentare (Doc-Blocks) duerfen "wizard/WizardHelper.php" als Prosa-Verweis
// erwaehnen (das tut die Datei bewusst, siehe Kopfkommentar) -- geprueft wird
// nur der tatsaechliche CODE (Tokens ausserhalb von Kommentaren).
$tokens = token_get_all((string)file_get_contents($REAL_CLASS_PATH));
$codeOnly = '';
foreach ($tokens as $token) {
    if (is_array($token)) {
        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
            continue;
        }
        $codeOnly .= $token[1];
    } else {
        $codeOnly .= $token;
    }
}
$hasWizardDependency = stripos($codeOnly, 'wizard') !== false;
assertTrue(!$hasWizardDependency, 'Code (ausserhalb von Kommentaren) referenziert nirgends "wizard"');

// =============================================================================
echo "\n== Requirement: Versionssortierung ist numerisch (Spec: 'Sortierung ist numerisch, nicht lexikografisch') ==\n";
// =============================================================================

$sortClassPath = isolatedRunner($REAL_CLASS_PATH, [
    '002_two.sql' => "-- Description: zwei\nSELECT 1;\n",
    '010_ten.sql' => "-- Description: zehn\nSELECT 1;\n",
    '001_one.sql' => "-- Description: eins\nSELECT 1;\n",
    '9_nine.sql' => "-- Description: neun ohne fuehrende Null\nSELECT 1;\n",
]);
$sortMigrationsDir = dirname($sortClassPath) . '/../database/migrations';

try {
    $steps = [
        ['op' => 'getAvailableMigrations'],
        ['op' => 'getMigrationVersion', 'file' => '/x/004_add_ai_rate_limits.sql'],
        ['op' => 'getMigrationVersion', 'file' => '/x/foo.sql'],
        ['op' => 'getMigrationDescription', 'file' => $sortMigrationsDir . '/002_two.sql'],
    ];

    $r = runChildSteps($CHILD_RUNNER, $sortClassPath, null, '', $steps);

    $avail = stepResult($r, 0);
    assertSame(['001_one.sql', '002_two.sql', '9_nine.sql', '010_ten.sql'], $avail['files'] ?? null, 'getAvailableMigrations() liefert 001,002,9,010 in numerischer Reihenfolge (002 vor 010, 9 vor 010)');
    assertSame(['001', '002', '9', '010'], $avail['versions'] ?? null, 'getMigrationVersion() bewahrt das Originalformat (fuehrende Nullen bleiben erhalten) UND die Sortierung war numerisch');

    assertSame('004', stepResult($r, 1)['result'] ?? null, 'getMigrationVersion("004_add_ai_rate_limits.sql") liefert "004" (Originalformat mit fuehrender Null)');
    assertSame('foo', stepResult($r, 2)['result'] ?? null, 'getMigrationVersion() faellt ohne Zahlpraefix auf den Basisnamen zurueck');
    assertSame('zwei', stepResult($r, 3)['result'] ?? null, 'getMigrationDescription() liest "-- Description: ..."-Zeile');
} catch (Throwable $e) {
    fail('Versionssortierung / Version-Parsing', $e->getMessage());
}

// Eigene isolierte Instanz fuer den Description-Fallback-Test, damit die
// zusaetzliche Datei die Sortier-Assertion oben nicht verfaelscht.
try {
    $descFallbackClassPath = isolatedRunner($REAL_CLASS_PATH, [
        'no_description_here.sql' => "SELECT 1;\n",
    ]);
    $descFallbackFile = dirname($descFallbackClassPath) . '/../database/migrations/no_description_here.sql';
    $r = runChildSteps($CHILD_RUNNER, $descFallbackClassPath, null, '', [
        ['op' => 'getMigrationDescription', 'file' => $descFallbackFile],
    ]);
    assertSame('no_description_here.sql', stepResult($r, 0)['result'] ?? null, 'getMigrationDescription() faellt ohne Description-Zeile auf basename() (inkl. .sql) zurueck');
} catch (Throwable $e) {
    fail('Description-Fallback-Test', $e->getMessage());
}

echo "\n== Edge Case: fehlendes database/migrations/-Verzeichnis ==\n";
try {
    $emptyClassPath = sys_get_temp_dir() . '/mr-empty-' . bin2hex(random_bytes(6));
    mkdir($emptyClassPath . '/scripts', 0777, true);
    copy($REAL_CLASS_PATH, $emptyClassPath . '/scripts/MigrationRunner.php');
    $GLOBALS['__TMP_DIRS'][] = $emptyClassPath;
    // database/migrations/ bewusst NICHT anlegen.

    $r = runChildSteps($CHILD_RUNNER, $emptyClassPath . '/scripts/MigrationRunner.php', null, '', [
        ['op' => 'getAvailableMigrations'],
    ]);
    assertSame([], stepResult($r, 0)['files'] ?? null, 'getAvailableMigrations() liefert ein leeres Array, wenn database/migrations/ nicht existiert');
} catch (Throwable $e) {
    fail('Edge Case: fehlendes database/migrations/-Verzeichnis', $e->getMessage());
}

// =============================================================================
echo "\n== Requirement: DB-Verbindung fuer Integrationstests ==\n";
// =============================================================================

$DB_HOST = getenv('MR_TEST_HOST') ?: '127.0.0.1';
$DB_PORT = (int)(getenv('MR_TEST_PORT') ?: 3306);
$DB_USER = getenv('MR_TEST_USER') ?: 'root';
$DB_PASS = (string)(getenv('MR_TEST_PASS') ?: '');

$admin = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, '', $DB_PORT);
if ($admin->connect_errno) {
    fwrite(STDERR, "Keine Verbindung zu MySQL ($DB_HOST:$DB_PORT): {$admin->connect_error}\n");
    fwrite(STDERR, "Infrastruktur nicht verfuegbar -- Test kann keine Aussage treffen.\n");
    exit(2);
}
pass("Verbindung zu Test-MySQL ($DB_HOST:$DB_PORT) hergestellt");

function freshDb(mysqli $admin, string $name): void
{
    $admin->query("DROP DATABASE IF EXISTS `$name`");
    $admin->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function dropDb(mysqli $admin, string $name): void
{
    $admin->query("DROP DATABASE IF EXISTS `$name`");
}

// -----------------------------------------------------------------------
// Gruppe A: Tracking-Tabelle, executeSqlFile-Semantik, runPending-Kernfluss
// -----------------------------------------------------------------------
try {
    $groupAClassPath = isolatedRunner($REAL_CLASS_PATH, [
        '001_first.sql' => <<<SQL
-- Description: Legt widgets-Tabelle an
CREATE TABLE IF NOT EXISTS {{PREFIX}}widgets (
  id INT PRIMARY KEY,
  name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
        ,
        '002_second.sql' => <<<SQL
-- Description: Fuegt color-Spalte per Guard hinzu (Session-Variablen-Test)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = CONCAT('{{PREFIX}}', 'widgets')
      AND COLUMN_NAME  = 'color');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE {{PREFIX}}widgets ADD COLUMN color VARCHAR(20) DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SQL
        ,
        '010_tenth.sql' => <<<SQL
# Hash-Kommentarzeile, muss verworfen werden
-- Description: Seedet einen widgets-Datensatz (PREFIX auch im String-Literal)
INSERT INTO {{PREFIX}}widgets (id, name) VALUES (1, CONCAT('{{PREFIX}}', 'seed'))
  ON DUPLICATE KEY UPDATE name = VALUES(name);
SQL
        ,
    ]);

    $prefix = 'mrp_';
    $dbName = 'mrtest_group_a';
    freshDb($admin, $dbName);

    echo "\n== Requirement: Tracking-Tabelle mit Tabellenpraefix ==\n";

    $r = runChildSteps($CHILD_RUNNER, $groupAClassPath, $dbName, $prefix, [
        ['op' => 'query', 'sql' => "SHOW TABLES LIKE '{$prefix}schema_migrations'"],
        ['op' => 'ensureTrackingTable'],
        ['op' => 'query', 'sql' => "SHOW TABLES LIKE '{$prefix}schema_migrations'"],
        ['op' => 'query', 'sql' => "SHOW COLUMNS FROM `{$prefix}schema_migrations`"],
        ['op' => 'ensureTrackingTable'],
        ['op' => 'query', 'sql' => "SHOW COLUMNS FROM `{$prefix}schema_migrations`"],
        ['op' => 'getAppliedVersions'],
        ['op' => 'runPending'],
        ['op' => 'getAppliedVersions'],
        ['op' => 'query', 'sql' => "SELECT description FROM `{$prefix}schema_migrations` WHERE version = '002'"],
        ['op' => 'query', 'sql' => "SHOW COLUMNS FROM `{$prefix}widgets` LIKE 'color'"],
        ['op' => 'query', 'sql' => "SELECT name FROM `{$prefix}widgets` WHERE id = 1"],
        ['op' => 'runPending'],
        ['op' => 'query', 'sql' => "DELETE FROM `{$prefix}schema_migrations` WHERE version = '010'"],
        ['op' => 'runPending'],
        ['op' => 'getAppliedVersions'],
        ['op' => 'executeSqlFile', 'file' => '/pfad/existiert/nicht.sql'],
        ['op' => 'recordMigration', 'version' => '001', 'description' => 'Doppelter Eintrag'],
        ['op' => 'query', 'sql' => "SELECT COUNT(*) AS c FROM `{$prefix}schema_migrations` WHERE version = '001'"],
    ]);

    assertTrue(count(stepResult($r, 0)['rows'] ?? ['x']) === 0, 'Tracking-Tabelle existiert vor ensureTrackingTable() noch nicht (SHOW TABLES liefert 0 Zeilen)');
    assertTrue((stepResult($r, 1)['ok'] ?? false) === true, 'ensureTrackingTable() (1. Aufruf) laeuft ohne Exception durch');
    assertTrue(count(stepResult($r, 2)['rows'] ?? []) === 1, 'ensureTrackingTable() legt <prefix>schema_migrations an');

    $colsBefore = array_column(stepResult($r, 3)['rows'] ?? [], 'Field');
    $colsAfter = array_column(stepResult($r, 5)['rows'] ?? [], 'Field');
    assertTrue((stepResult($r, 4)['ok'] ?? false) === true, 'ensureTrackingTable() (2. Aufruf) laeuft ohne Exception durch (idempotent)');
    assertSame($colsBefore, $colsAfter, 'ensureTrackingTable() ist idempotent (zweiter Aufruf aendert die Spaltenliste nicht)');
    assertSame(['version', 'applied_at', 'description'], $colsAfter, 'Tracking-Tabelle hat die Spalten version/applied_at/description');

    assertSame([], stepResult($r, 6)['result'] ?? null, 'getAppliedVersions() liefert leeres Array auf frischer Tracking-Tabelle');

    echo "\n== Requirement: Idempotente Anwendung in numerischer Reihenfolge (Frische Datenbank) ==\n";

    $freshRun = stepResult($r, 7)['result'] ?? [];
    assertSame(null, $freshRun['failedVersion'] ?? null, 'Fresh-Install: runPending() meldet keinen Fehler');
    assertSame(['001', '002', '010'], array_column($freshRun['applied'] ?? [], 'version'), 'Fresh-Install: 001, 002, 010 werden in numerischer Reihenfolge angewendet');
    assertSame([], $freshRun['skipped'] ?? null, 'Fresh-Install: nichts wird uebersprungen');

    assertSame(['001', '002', '010'], stepResult($r, 8)['result'] ?? null, 'Nach Fresh-Install stehen 001, 002, 010 in der Tracking-Tabelle');

    $descRows = stepResult($r, 9)['rows'] ?? [];
    assertSame('Fuegt color-Spalte per Guard hinzu (Session-Variablen-Test)', $descRows[0]['description'] ?? null, 'recordMigration() traegt die Description aus der Migrationsdatei ein');

    assertTrue(count(stepResult($r, 10)['rows'] ?? []) === 1, 'Guard-Migration 002 (SET/PREPARE/EXECUTE/DEALLOCATE ueber mehrere Statements) hat die color-Spalte angelegt -- Session-Variablen bleiben ueber Statement-Grenzen hinweg erhalten');

    $seedRows = stepResult($r, 11)['rows'] ?? [];
    assertSame($prefix . 'seed', $seedRows[0]['name'] ?? null, '{{PREFIX}} wird auch innerhalb eines String-Literals (CONCAT) ersetzt');

    echo "\n== Requirement: Idempotente Anwendung (wiederholter Lauf ist wirkungslos) ==\n";

    $secondRun = stepResult($r, 12)['result'] ?? [];
    assertSame([], $secondRun['applied'] ?? null, 'Zweiter Lauf: 0 Migrationen angewendet');
    assertSame(['001', '002', '010'], $secondRun['skipped'] ?? null, 'Zweiter Lauf: alle drei Versionen werden uebersprungen');
    assertSame(null, $secondRun['failedVersion'] ?? null, 'Zweiter Lauf: kein Fehler');

    echo "\n== Requirement: Idempotente Anwendung (Teilzustand) ==\n";

    $thirdRun = stepResult($r, 14)['result'] ?? [];
    assertSame(['010'], array_column($thirdRun['applied'] ?? [], 'version'), 'Teilzustand: nach Entfernen von Version 010 wendet der Runner ausschliesslich 010 erneut an');
    assertSame(['001', '002'], $thirdRun['skipped'] ?? null, 'Teilzustand: 001 und 002 bleiben uebersprungen');
    assertSame(['001', '002', '010'], stepResult($r, 15)['result'] ?? null, 'Teilzustand: nach dem dritten Lauf sind wieder alle drei Versionen eingetragen');

    echo "\n== Requirement: SQL-Ausfuehrungssemantik (executeSqlFile) ==\n";

    $fileResult = stepResult($r, 16)['result'] ?? [];
    assertSame(false, $fileResult['success'] ?? null, 'executeSqlFile() liefert success=false bei nicht existierender Datei');
    assertTrue(str_contains((string)($fileResult['error'] ?? ''), 'nicht.sql'), 'executeSqlFile()-Fehlermeldung nennt den Dateinamen bei fehlender Datei');

    echo "\n== Requirement: recordMigration() Fehlerverhalten ==\n";

    $recordStep = stepResult($r, 17);
    assertTrue(isset($recordStep['exception']) && str_contains($recordStep['exception'], 'RuntimeException'), 'recordMigration() wirft RuntimeException bei Duplicate-Key (Version bereits eingetragen)', $recordStep['exception'] ?? 'keine Exception geworfen');
    $dupCountRows = stepResult($r, 18)['rows'] ?? [];
    assertSame('1', $dupCountRows[0]['c'] ?? null, 'Fehlgeschlagener recordMigration()-Aufruf hinterlaesst keinen Duplicate-Eintrag');

    dropDb($admin, $dbName);
} catch (Throwable $e) {
    fail('Gruppe A (Tracking-Tabelle / executeSqlFile / runPending Kernfluss)', $e->getMessage());
}

// -----------------------------------------------------------------------
// Gruppe B: Fehlerabbruch ohne Fortschrittseintrag
// -----------------------------------------------------------------------
try {
    $groupBClassPath = isolatedRunner($REAL_CLASS_PATH, [
        '001_ok_one.sql' => "-- Description: erste ok\nCREATE TABLE IF NOT EXISTS {{PREFIX}}gadgets (id INT PRIMARY KEY) ENGINE=InnoDB;\n",
        '002_ok_two.sql' => "-- Description: zweite ok\nINSERT INTO {{PREFIX}}gadgets (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id;\n",
        '099_broken.sql' => "-- Description: absichtlich kaputt\nINSERT INTO {{PREFIX}}nicht_vorhandene_tabelle (id) VALUES (1);\n",
    ]);

    $prefix = 'mrp_';
    $dbName = 'mrtest_group_b';
    freshDb($admin, $dbName);

    $r = runChildSteps($CHILD_RUNNER, $groupBClassPath, $dbName, $prefix, [
        ['op' => 'ensureTrackingTable'],
        ['op' => 'runPending'],
        ['op' => 'getAppliedVersions'],
    ]);

    echo "\n== Requirement: Fehlerabbruch ohne Fortschrittseintrag ==\n";

    $failRun = stepResult($r, 1)['result'] ?? [];
    assertSame('099', $failRun['failedVersion'] ?? null, 'runPending() meldet die fehlgeschlagene Version 099 (Originalformat, inkl. fuehrender Nullen)');
    assertTrue(!empty($failRun['failedError'] ?? ''), 'runPending() liefert einen mysqli-Fehlertext');
    assertTrue(str_contains((string)($failRun['failedStatement'] ?? ''), 'nicht_vorhandene_tabelle'), 'runPending() liefert das fehlgeschlagene (gekuerzte) Statement');
    assertSame(['001', '002'], array_column($failRun['applied'] ?? [], 'version'), 'runPending() bricht erst NACH den zuvor erfolgreichen Migrationen 001 und 002 ab');

    $applied = stepResult($r, 2)['result'] ?? [];
    assertTrue(in_array('001', $applied, true) && in_array('002', $applied, true), 'Vorherige Migrationen (001, 002) bleiben in der Tracking-Tabelle eingetragen');
    assertTrue(!in_array('099', $applied, true), 'Die fehlgeschlagene Migration 099 wird NICHT in die Tracking-Tabelle eingetragen');

    dropDb($admin, $dbName);
} catch (Throwable $e) {
    fail('Gruppe B (Fehlerabbruch ohne Fortschrittseintrag)', $e->getMessage());
}

// -----------------------------------------------------------------------
// Gruppe C: reale Migrationen (001-005) gegen vollstaendiges Schema
// (schema.sql + schema-auth.sql, exakt in der Reihenfolge von
// wizard/install.php Zeile 81-84). Nutzt die ECHTE scripts/MigrationRunner.php
// UND die echten database/migrations/*.sql -- keine Kopien, keine
// Veraenderung an Produktivdateien.
// -----------------------------------------------------------------------
try {
    $prefix = 'dmt_';
    $dbName = 'mrtest_group_c_full_schema';
    freshDb($admin, $dbName);

    $r = runChildSteps($CHILD_RUNNER, $REAL_CLASS_PATH, $dbName, $prefix, [
        ['op' => 'executeSqlFile', 'file' => $ROOT . '/database/schema.sql'],
        ['op' => 'executeSqlFile', 'file' => $ROOT . '/database/schema-auth.sql'],
        ['op' => 'ensureTrackingTable'],
        ['op' => 'runPending'],
        ['op' => 'query', 'sql' => "SHOW COLUMNS FROM `{$prefix}auth_users` LIKE 'avatar'"],
    ]);

    echo "\n== Szenario: vollstaendiges Schema (schema.sql + schema-auth.sql) + reale Migrationen 001-005 ==\n";

    $schemaResult = stepResult($r, 0)['result'] ?? [];
    $authSchemaResult = stepResult($r, 1)['result'] ?? [];
    assertTrue(($schemaResult['success'] ?? false) === true, 'schema.sql wird ueber executeSqlFile() fehlerfrei eingespielt (Vorbedingung)', (string)($schemaResult['error'] ?? ''));
    assertTrue(($authSchemaResult['success'] ?? false) === true, 'schema-auth.sql wird ueber executeSqlFile() fehlerfrei eingespielt (Vorbedingung)', (string)($authSchemaResult['error'] ?? ''));

    $result = stepResult($r, 3)['result'] ?? [];
    assertSame(null, $result['failedVersion'] ?? null, 'Mit vollstaendigem Schema (inkl. schema-auth.sql) laeuft Migration 003 (ALTER TABLE {{PREFIX}}auth_users ADD COLUMN avatar) fehlerfrei durch', (string)($result['failedError'] ?? ''));
    assertSame(['001', '002', '003', '004', '005'], array_column($result['applied'] ?? [], 'version'), 'Alle fuenf realen Migrationen (001-005) werden in numerischer Reihenfolge angewendet');

    $avatarRows = stepResult($r, 4)['rows'] ?? [];
    assertTrue(count($avatarRows) === 1, 'Migration 003 hat die Spalte avatar auf {{PREFIX}}auth_users angelegt');

    dropDb($admin, $dbName);
} catch (Throwable $e) {
    fail('Gruppe C (reale Migrationen gegen vollstaendiges Schema)', $e->getMessage());
}

// -----------------------------------------------------------------------
// Gruppe D: Gegenprobe -- NUR schema.sql (ohne schema-auth.sql) geladen.
// Erwartung: Migration 003 schlaegt fehl, weil {{PREFIX}}auth_users in
// diesem (unvollstaendigen) Schema nicht existiert. Bestaetigt, dass ein im
// Entwickler-Testlauf beobachteter Fehlschlag von Migration 003 an einem
// unvollstaendig geladenen Schema lag, NICHT an MigrationRunner.php selbst.
// -----------------------------------------------------------------------
try {
    $prefix = 'dmt_';
    $dbName = 'mrtest_group_d_partial_schema';
    freshDb($admin, $dbName);

    $r = runChildSteps($CHILD_RUNNER, $REAL_CLASS_PATH, $dbName, $prefix, [
        ['op' => 'executeSqlFile', 'file' => $ROOT . '/database/schema.sql'],
        ['op' => 'ensureTrackingTable'],
        ['op' => 'runPending'],
        ['op' => 'getAppliedVersions'],
    ]);

    echo "\n== Gegenprobe: NUR schema.sql (ohne schema-auth.sql) -> Migration 003 muss fehlschlagen ==\n";

    $schemaResult = stepResult($r, 0)['result'] ?? [];
    assertTrue(($schemaResult['success'] ?? false) === true, 'schema.sql allein wird fehlerfrei eingespielt (Vorbedingung fuer die Gegenprobe)', (string)($schemaResult['error'] ?? ''));

    $result = stepResult($r, 2)['result'] ?? [];
    assertSame('003', $result['failedVersion'] ?? null, 'Ohne schema-auth.sql schlaegt Migration 003 fehl (fehlende Tabelle {{PREFIX}}auth_users) -- bestaetigt: Ursache ist unvollstaendiges Schema, kein MigrationRunner-Defekt');
    assertTrue(stripos((string)($result['failedError'] ?? ''), 'auth_users') !== false, 'Die mysqli-Fehlermeldung nennt die fehlende Tabelle auth_users', (string)($result['failedError'] ?? ''));
    assertSame(['001', '002'], array_column($result['applied'] ?? [], 'version'), 'Migrationen 001 und 002 (ohne auth_users-Abhaengigkeit) werden trotzdem erfolgreich angewendet');

    $applied = stepResult($r, 3)['result'] ?? [];
    assertTrue(!in_array('003', $applied, true), 'Die fehlgeschlagene Migration 003 wird nicht in die Tracking-Tabelle eingetragen');

    dropDb($admin, $dbName);
} catch (Throwable $e) {
    fail('Gruppe D (Gegenprobe: nur schema.sql)', $e->getMessage());
}

$admin->close();

// =============================================================================
echo "\n==============================================================\n";
echo "Ergebnis: {$GLOBALS['__PASS']} bestanden, {$GLOBALS['__FAIL']} fehlgeschlagen\n";
if ($GLOBALS['__FAIL'] > 0) {
    echo "Fehlgeschlagen:\n";
    foreach ($GLOBALS['__FAILED_NAMES'] as $n) {
        echo "  - $n\n";
    }
}
echo "==============================================================\n";

exit($GLOBALS['__FAIL'] === 0 ? 0 : 1);
