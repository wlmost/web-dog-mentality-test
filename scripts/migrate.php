#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI-Einstiegspunkt für den nicht-interaktiven Migrations-Runner.
 *
 * Aufruf:
 *   php scripts/migrate.php [--dry-run] [pfad/zu/config.local.php]
 *
 * Lädt die DB-Zugangsdaten aus api/config.local.php (oder dem übergebenen
 * alternativen Pfad), stellt eine mysqli-Verbindung her und wendet
 * ausstehende Migrationen aus database/migrations/*.sql über
 * MigrationRunner::runPending() an. Benötigt zur Laufzeit **kein**
 * wizard/-Verzeichnis (siehe openspec/changes/add-db-migration-runner/design.md).
 *
 * Exit-Codes: 0 = Erfolg (auch "nichts zu tun" oder --dry-run), 1 = Fehler.
 */

require_once __DIR__ . '/MigrationRunner.php';

/**
 * Beendet den Prozess mit einer Fehlermeldung auf STDERR und Exit-Code 1.
 *
 * Hinweis: Kein `never`-Rückgabetyp (PHP 8.1+), da `composer.json` PHP 8.0
 * als Ziel-Plattform vorgibt.
 */
function migrateFail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

if (!extension_loaded('mysqli')) {
    migrateFail('MIGRATE FAIL: PHP-Erweiterung „mysqli" ist nicht geladen.');
}

$dryRun = false;
$configPath = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }

    if ($configPath !== null) {
        migrateFail('MIGRATE FAIL: Unerwartetes zusätzliches Argument: ' . $arg);
    }

    $configPath = $arg;
}

if ($configPath === null) {
    $configPath = __DIR__ . '/../api/config.local.php';
}

if (!is_file($configPath)) {
    migrateFail("MIGRATE FAIL: Konfigurationsdatei nicht gefunden: $configPath");
}

require $configPath;

$requiredConstants = ['DB_HOST', 'DB_USER', 'DB_NAME', 'DB_PREFIX'];
$missingConstants = array_filter($requiredConstants, static fn(string $name): bool => !defined($name));

if ($missingConstants !== []) {
    migrateFail(
        'MIGRATE FAIL: Fehlende DB-Konstanten in ' . $configPath . ': '
        . implode(', ', $missingConstants)
    );
}

/** @var string $dbHost */
$dbHost = constant('DB_HOST');
/** @var string $dbUser */
$dbUser = constant('DB_USER');
/** @var string $dbName */
$dbName = constant('DB_NAME');
/** @var string $dbPrefix */
$dbPrefix = constant('DB_PREFIX');
$dbPass = defined('DB_PASS') ? (string)constant('DB_PASS') : '';
$dbPort = defined('DB_PORT') ? (int)constant('DB_PORT') : 3306;

mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_init();
if ($conn === false || !@$conn->real_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort)) {
    $connectError = $conn instanceof mysqli ? $conn->connect_error : mysqli_connect_error();
    migrateFail('MIGRATE FAIL: Datenbankverbindung fehlgeschlagen: ' . $connectError);
}

if (!$conn->set_charset('utf8mb4')) {
    migrateFail('MIGRATE FAIL: Charset utf8mb4 konnte nicht gesetzt werden: ' . $conn->error);
}

try {
    if ($dryRun) {
        // Dry-Run darf keine DB-Änderung vornehmen – daher wird die
        // Tracking-Tabelle NICHT über ensureTrackingTable() angelegt, sondern
        // nur lesend geprüft, ob sie bereits existiert. Fehlt sie, gelten
        // alle Migrationen als ausstehend.
        $trackingTable = $dbPrefix . 'schema_migrations';
        $tableCheck = $conn->query(
            "SHOW TABLES LIKE '" . $conn->real_escape_string($trackingTable) . "'"
        );
        $trackingTableExists = $tableCheck instanceof mysqli_result && $tableCheck->num_rows > 0;

        $appliedVersions = $trackingTableExists
            ? array_flip(MigrationRunner::getAppliedVersions($conn, $dbPrefix))
            : [];
        $pendingVersions = [];

        foreach (MigrationRunner::getAvailableMigrations() as $file) {
            $version = MigrationRunner::getMigrationVersion($file);
            if (!isset($appliedVersions[$version])) {
                $pendingVersions[] = $version;
            }
        }

        if ($pendingVersions === []) {
            echo 'Keine ausstehenden Migrationen.' . PHP_EOL;
        } else {
            echo 'Ausstehende Migrationen: ' . implode(', ', $pendingVersions) . PHP_EOL;
        }

        exit(0);
    }

    MigrationRunner::ensureTrackingTable($conn, $dbPrefix);
    $result = MigrationRunner::runPending($conn, $dbPrefix);

    foreach ($result['log'] as $logLine) {
        echo $logLine . PHP_EOL;
    }

    if ($result['failedVersion'] !== null) {
        migrateFail(sprintf(
            'MIGRATE FAIL bei %s: %s',
            $result['failedVersion'],
            $result['failedError'] ?? 'unbekannter Fehler'
        ));
    }

    printf(
        'MIGRATE OK: %d angewendet, %d übersprungen%s',
        count($result['applied']),
        count($result['skipped']),
        PHP_EOL
    );
    exit(0);
} catch (Throwable $exception) {
    migrateFail('MIGRATE FAIL: ' . $exception->getMessage());
} finally {
    $conn->close();
}
