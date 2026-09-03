#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Selbsttest fuer scripts/migrate.php -- OHNE PHPUnit (design.md D8,
 * openspec/changes/add-db-migration-runner).
 *
 * Prueft die vier in design.md D8 beschriebenen Szenarien gegen eine
 * Wegwerf-Datenbank, indem scripts/migrate.php mehrfach als eigenstaendiger
 * Subprozess (proc_open) aufgerufen wird -- exakt so, wie es spaeter per SSH
 * im Deploy laeuft:
 *
 *   1. Frische DB               -> Exit 0, schema_migrations enthaelt 001-005.
 *   2. Zweiter Lauf             -> Exit 0, "0 angewendet, 5 uebersprungen".
 *   3. Teilzustand nach DELETE  -> Exit 0, wendet ausschliesslich 005 erneut an.
 *   4. Kaputte Zusatzmigration  -> Exit 1, die kaputte Version wird NICHT
 *      (nur in einem isolierten     eingetragen.
 *      Test-Temp-Migrationspfad)
 *
 * Vor Szenario 1 wird die Testdatenbank mit database/schema.sql +
 * database/schema-auth.sql befuellt (identische Reihenfolge wie
 * wizard/install.php, siehe tests/migration-runner-test.php Gruppe C) --
 * ohne schema-auth.sql wuerde Migration 003 an der fehlenden Tabelle
 * {{PREFIX}}auth_users scheitern (verifiziert in tests/migration-runner-test.php
 * Gruppe D). Fuer Szenario 4 wird bewusst NICHT database/migrations/
 * veraendert, sondern eine isolierte Kopie der echten Migrationsdateien plus
 * einer zusaetzlichen kaputten Datei in einem Test-Temp-Verzeichnis abgelegt;
 * scripts/migrate.php erhaelt den Pfad dorthin ueber die testinterne
 * Umgebungsvariable MIGRATE_MIGRATIONS_DIR (siehe scripts/migrate.php,
 * kein Teil der oeffentlichen CLI-Schnittstelle).
 *
 * Env-Variablen (alle optional, Default = lokaler Standard-MySQL/Docker):
 *   MIGRATE_TEST_HOST    (Default 127.0.0.1)
 *   MIGRATE_TEST_PORT    (Default 3306)
 *   MIGRATE_TEST_USER    (Default root)
 *   MIGRATE_TEST_PASS    (Default '')
 *   MIGRATE_TEST_NAME    (Default migrate_selftest)
 *   MIGRATE_TEST_PREFIX  (Default dmt_)
 *
 * Lokale MySQL 8 per Docker bereitstellen:
 *   docker run -d --name migrate-selftest-mysql -e MYSQL_ROOT_PASSWORD=root \
 *     -p 33062:3306 mysql:8.0
 *   (warten bis "docker exec migrate-selftest-mysql mysqladmin ping -uroot -proot"
 *   erfolgreich ist, dann:)
 *
 * Ausfuehren aus dem Projekt-Root:
 *   MIGRATE_TEST_HOST=127.0.0.1 MIGRATE_TEST_PORT=33062 MIGRATE_TEST_USER=root \
 *     MIGRATE_TEST_PASS=root php scripts/migrate-selftest.php
 *
 * Danach Container wieder entfernen: docker rm -f migrate-selftest-mysql
 *
 * Exit-Code 0 nur wenn alle Assertions bestehen, sonst 1. Fehlt eine
 * MySQL-Verbindung, bricht das Skript mit Exit-Code 2 ab (Infrastruktur-
 * Fehler, keine Testaussage) -- analog zu tests/migration-runner-test.php.
 * Raeumt Testdatenbank und Testdateien in jedem Fall auf
 * (register_shutdown_function), auch bei Assertion-Fehlschlag oder
 * Fatal Error.
 *
 * Wird bewusst NICHT ins Deploy-Paket aufgenommen (build.sh, Task 4.1) --
 * reines Entwickler-/CI-Werkzeug.
 */

$ROOT = dirname(__DIR__);
$MIGRATION_RUNNER_CLASS = $ROOT . '/scripts/MigrationRunner.php';
$MIGRATE_SCRIPT = $ROOT . '/scripts/migrate.php';

if (!is_file($MIGRATION_RUNNER_CLASS) || !is_file($MIGRATE_SCRIPT)) {
    fwrite(STDERR, "scripts/MigrationRunner.php bzw. scripts/migrate.php nicht gefunden -- bitte aus dem Projekt-Root ausfuehren.\n");
    exit(2);
}

require_once $MIGRATION_RUNNER_CLASS;

// -----------------------------------------------------------------------
// Test-Harness (PASS/FAIL, analog tests/migration-runner-test.php)
// -----------------------------------------------------------------------

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

function assertSame($expected, $actual, string $name, string $detail = ''): void
{
    if ($expected === $actual) {
        pass($name);
    } else {
        $message = 'erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true);
        fail($name, $detail !== '' ? $message . ' | ' . $detail : $message);
    }
}

function assertContains(string $needle, string $haystack, string $name): void
{
    if (str_contains($haystack, $needle)) {
        pass($name);
    } else {
        fail($name, "\"$needle\" nicht gefunden in: " . trim($haystack));
    }
}

// -----------------------------------------------------------------------
// Aufraeumen (Testdatenbank + Temp-Dateien), auch bei Fehlschlag/Fatal Error
// -----------------------------------------------------------------------

$GLOBALS['__TMP_PATHS'] = [];
$GLOBALS['__CLEANUP_DB'] = null;

function rrmdir(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        rrmdir($path . '/' . $item);
    }
    rmdir($path);
}

register_shutdown_function(static function () {
    foreach ($GLOBALS['__TMP_PATHS'] as $path) {
        rrmdir($path);
    }

    $cleanupDb = $GLOBALS['__CLEANUP_DB'];
    if ($cleanupDb !== null) {
        [$host, $port, $user, $pass, $dbName] = $cleanupDb;
        $admin = @new mysqli($host, $user, $pass, '', $port);
        if (!$admin->connect_errno) {
            $admin->query('DROP DATABASE IF EXISTS `' . $admin->real_escape_string($dbName) . '`');
            $admin->close();
        }
    }
});

/**
 * Fuehrt scripts/migrate.php in einem eigenen Subprozess aus (proc_open) und
 * liefert Exit-Code sowie STDOUT/STDERR zurueck. $envOverrides wird ueber die
 * geerbte Umgebung gelegt (z. B. fuer die testinterne
 * MIGRATE_MIGRATIONS_DIR-Variable, siehe scripts/migrate.php).
 *
 * @param string[] $args
 * @param array<string,string> $envOverrides
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runMigrate(string $migrateScript, array $args, array $envOverrides = []): array
{
    $command = array_merge([PHP_BINARY, $migrateScript], $args);
    $env = array_merge(getenv() ?: [], $envOverrides);
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, null, $env);
    if (!is_resource($process)) {
        throw new RuntimeException("Konnte Subprozess nicht starten: $migrateScript");
    }

    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

// -----------------------------------------------------------------------
// Konfiguration aus der Umgebung
// -----------------------------------------------------------------------

$host = getenv('MIGRATE_TEST_HOST') ?: '127.0.0.1';
$port = (int)(getenv('MIGRATE_TEST_PORT') ?: 3306);
$user = getenv('MIGRATE_TEST_USER') ?: 'root';
$pass = (string)(getenv('MIGRATE_TEST_PASS') ?: '');
$dbName = getenv('MIGRATE_TEST_NAME') ?: 'migrate_selftest';
$prefix = getenv('MIGRATE_TEST_PREFIX') ?: 'dmt_';

echo "== Verbindung zur Test-Datenbank ($host:$port, DB \"$dbName\", Praefix \"$prefix\") ==\n";

$admin = @new mysqli($host, $user, $pass, '', $port);
if ($admin->connect_errno) {
    fwrite(STDERR, "Keine Verbindung zu MySQL ($host:$port): {$admin->connect_error}\n");
    fwrite(STDERR, "Infrastruktur nicht verfuegbar -- Test kann keine Aussage treffen.\n");
    exit(2);
}
pass("Verbindung zu Test-MySQL ($host:$port) hergestellt");

$GLOBALS['__CLEANUP_DB'] = [$host, $port, $user, $pass, $dbName];

$admin->query('DROP DATABASE IF EXISTS `' . $admin->real_escape_string($dbName) . '`');
if (!$admin->query('CREATE DATABASE `' . $admin->real_escape_string($dbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
    fwrite(STDERR, "Testdatenbank \"$dbName\" konnte nicht angelegt werden: {$admin->error}\n");
    exit(2);
}
if (!$admin->select_db($dbName)) {
    fwrite(STDERR, "Testdatenbank \"$dbName\" konnte nicht selektiert werden: {$admin->error}\n");
    exit(2);
}

// -----------------------------------------------------------------------
// Basis-Schema einspielen (schema.sql + schema-auth.sql, identische
// Reihenfolge wie wizard/install.php) -- ohne schema-auth.sql wuerde
// Migration 003 an der fehlenden Tabelle {{PREFIX}}auth_users scheitern.
// -----------------------------------------------------------------------

echo "\n== Basis-Schema einspielen (database/schema.sql + database/schema-auth.sql) ==\n";

foreach (['schema.sql', 'schema-auth.sql'] as $schemaFile) {
    $result = MigrationRunner::executeSqlFile($admin, $ROOT . '/database/' . $schemaFile, $prefix);
    assertTrue($result['success'], "database/$schemaFile wird fehlerfrei eingespielt (Vorbedingung)", (string)($result['error'] ?? ''));
    if (!$result['success']) {
        // Ohne Basis-Schema ergeben die nachfolgenden Szenarien keinen Sinn.
        echo "\nAbbruch: Basis-Schema konnte nicht eingespielt werden.\n";
        exit(1);
    }
}

// -----------------------------------------------------------------------
// Temporaere config.local.php fuer die Subprozess-Aufrufe von migrate.php
// -----------------------------------------------------------------------

$tmpConfigDir = sys_get_temp_dir() . '/migrate-selftest-' . bin2hex(random_bytes(6));
mkdir($tmpConfigDir, 0777, true);
$GLOBALS['__TMP_PATHS'][] = $tmpConfigDir;

$configPath = $tmpConfigDir . '/config.local.php';
$configContent = "<?php\n"
    . "declare(strict_types=1);\n"
    . "// Temporaere Testkonfiguration von scripts/migrate-selftest.php -- nicht einchecken.\n\n"
    . "define('DB_HOST',   " . var_export($host, true) . ");\n"
    . "define('DB_PORT',   $port);\n"
    . "define('DB_USER',   " . var_export($user, true) . ");\n"
    . "define('DB_PASS',   " . var_export($pass, true) . ");\n"
    . "define('DB_NAME',   " . var_export($dbName, true) . ");\n"
    . "define('DB_PREFIX', " . var_export($prefix, true) . ");\n";

file_put_contents($configPath, $configContent);

$applied = static fn(): array => MigrationRunner::getAppliedVersions($admin, $prefix);

// =============================================================================
echo "\n== Szenario 1: Frische Datenbank -> alle Migrationen werden angewendet ==\n";
// =============================================================================

$run1 = runMigrate($MIGRATE_SCRIPT, [$configPath]);
assertSame(0, $run1['exitCode'], 'Erster Lauf (frische DB) endet mit Exit-Code 0', $run1['stdout'] . $run1['stderr']);
assertSame(['001', '002', '003', '004', '005'], $applied(), 'schema_migrations enthaelt nach dem ersten Lauf die Versionen 001-005');
assertContains('MIGRATE OK: 5 angewendet, 0 übersprungen', $run1['stdout'], 'Ausgabe meldet "5 angewendet, 0 übersprungen"');

// =============================================================================
echo "\n== Szenario 2: Zweiter Lauf ist wirkungslos (Idempotenz) ==\n";
// =============================================================================

$run2 = runMigrate($MIGRATE_SCRIPT, [$configPath]);
assertSame(0, $run2['exitCode'], 'Zweiter Lauf endet mit Exit-Code 0', $run2['stdout'] . $run2['stderr']);
assertContains('MIGRATE OK: 0 angewendet, 5 übersprungen', $run2['stdout'], 'Ausgabe meldet "0 angewendet, 5 übersprungen"');
assertSame(['001', '002', '003', '004', '005'], $applied(), 'schema_migrations ist nach dem zweiten Lauf unveraendert (001-005)');

// =============================================================================
echo "\n== Szenario 3: Teilzustand nach DELETE der letzten Version ==\n";
// =============================================================================

if (!$admin->query("DELETE FROM `{$prefix}schema_migrations` WHERE version = '005'")) {
    fail('DELETE FROM schema_migrations WHERE version=005 (Vorbereitung Szenario 3)', $admin->error);
} else {
    pass('DELETE FROM schema_migrations WHERE version=005 (Vorbereitung Szenario 3)');
}
assertSame(['001', '002', '003', '004'], $applied(), 'Nach dem DELETE fehlt nur noch Version 005');

$run3 = runMigrate($MIGRATE_SCRIPT, [$configPath]);
assertSame(0, $run3['exitCode'], 'Dritter Lauf endet mit Exit-Code 0', $run3['stdout'] . $run3['stderr']);
assertContains('MIGRATE OK: 1 angewendet, 4 übersprungen', $run3['stdout'], 'Ausgabe meldet "1 angewendet, 4 übersprungen"');
assertSame(['001', '002', '003', '004', '005'], $applied(), 'schema_migrations enthaelt nach dem dritten Lauf wieder 001-005 (nur 005 wurde erneut angewendet)');

// =============================================================================
echo "\n== Szenario 4: Absichtlich kaputte Zusatzmigration (isolierter Test-Temp-Pfad) ==\n";
// =============================================================================

// database/migrations/ bleibt unveraendert: die echten Dateien werden in ein
// Test-Temp-Verzeichnis kopiert und dort um eine kaputte Zusatzdatei ergaenzt.
// scripts/migrate.php erhaelt den isolierten Pfad ueber die testinterne
// Umgebungsvariable MIGRATE_MIGRATIONS_DIR.
$isolatedMigrationsDir = sys_get_temp_dir() . '/migrate-selftest-broken-' . bin2hex(random_bytes(6));
mkdir($isolatedMigrationsDir, 0777, true);
$GLOBALS['__TMP_PATHS'][] = $isolatedMigrationsDir;

foreach (MigrationRunner::getAvailableMigrations() as $realFile) {
    copy($realFile, $isolatedMigrationsDir . '/' . basename($realFile));
}

$brokenVersion = '999';
file_put_contents(
    $isolatedMigrationsDir . "/{$brokenVersion}_broken.sql",
    "-- Description: absichtlich kaputt (Selbsttest Szenario 4)\n"
    . "INSERT INTO {{PREFIX}}tabelle_die_es_nicht_gibt (id) VALUES (1);\n"
);

$run4 = runMigrate($MIGRATE_SCRIPT, [$configPath], ['MIGRATE_MIGRATIONS_DIR' => $isolatedMigrationsDir]);
assertSame(1, $run4['exitCode'], 'Lauf mit kaputter Zusatzmigration endet mit Exit-Code 1', $run4['stdout'] . $run4['stderr']);
assertContains("MIGRATE FAIL bei $brokenVersion", $run4['stdout'] . $run4['stderr'], "Ausgabe nennt die fehlgeschlagene Version $brokenVersion");
assertTrue(!in_array($brokenVersion, $applied(), true), "Die kaputte Migration $brokenVersion wird NICHT in schema_migrations eingetragen");
assertSame(['001', '002', '003', '004', '005'], $applied(), 'Die zuvor erfolgreichen Migrationen 001-005 bleiben nach dem Fehlschlag unveraendert eingetragen');

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
