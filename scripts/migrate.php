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
 * Ist zu diesem Zeitpunkt bereits eine DB-Verbindung aufgebaut, wird sie
 * vorher geschlossen (Parameter optional, da die meisten Aufrufstellen vor
 * dem Verbindungsaufbau liegen).
 *
 * Hinweis: Kein `never`-Rückgabetyp (PHP 8.1+), da `composer.json` PHP 8.0
 * als Ziel-Plattform vorgibt. Aus demselben Grund kein `finally`-Block für
 * das Verbindungs-Cleanup: `exit()` innerhalb eines try/catch-Blocks
 * überspringt einen zugehörigen `finally`-Block, daher wird die Verbindung
 * an jeder Austrittsstelle explizit über diese Funktion bzw. `migrateExit()`
 * geschlossen.
 */
function migrateFail(string $message, ?mysqli $conn = null): void
{
    fwrite(STDERR, $message . PHP_EOL);
    $conn?->close();
    exit(1);
}

/**
 * Schließt die DB-Verbindung und beendet den Prozess erfolgreich
 * (Exit-Code 0). Pendant zu migrateFail() für die Erfolgspfade.
 */
function migrateExit(mysqli $conn): void
{
    $conn->close();
    exit(0);
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

// Testinterner Override des Migrations-Verzeichnisses: ausschließlich für
// scripts/migrate-selftest.php (T3.1), das Szenario 4 (absichtlich kaputte
// Zusatzmigration) in einem isolierten Test-Temp-Verzeichnis prüft, ohne
// database/migrations/ anzufassen. Kein Teil der dokumentierten
// CLI-Schnittstelle (design.md D6 kennt nur --dry-run und den
// Config-Pfad-Parameter); bewusst nicht in --help/Fehlermeldungen erwähnt.
$migrationsDirOverride = getenv('MIGRATE_MIGRATIONS_DIR');
$migrationsDirOverride = $migrationsDirOverride !== false ? $migrationsDirOverride : null;

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
// Das @ ist trotz MYSQLI_REPORT_OFF weiterhin nötig: MYSQLI_REPORT_OFF
// unterdrückt zwar die seit PHP 8.1 standardmäßig geworfene
// mysqli_sql_exception, nicht aber die von real_connect() bei einem
// Verbindungsfehler zusätzlich ausgelöste PHP E_WARNING (empirisch
// verifiziert: `php -r 'mysqli_report(MYSQLI_REPORT_OFF); mysqli_init()
// ->real_connect("127.0.0.1", "x", "x", "x", 1);'` gibt ohne @ trotzdem
// eine Warnung aus). Ohne @ würde bei einem Verbindungsfehler also
// zusätzlich zur sauberen migrateFail()-Meldung eine PHP-Warnung auf
// STDERR erscheinen.
if ($conn === false || !@$conn->real_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort)) {
    $connectError = $conn instanceof mysqli ? $conn->connect_error : mysqli_connect_error();
    migrateFail('MIGRATE FAIL: Datenbankverbindung fehlgeschlagen: ' . $connectError);
}

if (!$conn->set_charset('utf8mb4')) {
    migrateFail('MIGRATE FAIL: Charset utf8mb4 konnte nicht gesetzt werden: ' . $conn->error, $conn);
}

try {
    if ($dryRun) {
        // Dry-Run darf keine DB-Änderung vornehmen – daher wird die
        // Tracking-Tabelle NICHT über ensureTrackingTable() angelegt, sondern
        // nur lesend geprüft, ob sie bereits existiert. Fehlt sie, gelten
        // alle Migrationen als ausstehend.
        //
        // Hinweis (DRY): Der Tabellenname wird hier bewusst separat gebaut
        // statt über eine gemeinsame MigrationRunner-Methode, da
        // MigrationRunner aktuell keine rein lesende
        // „existiert die Tracking-Tabelle bereits"-Abfrage anbietet
        // (ensureTrackingTable() legt sie ggf. an, was im Dry-Run verboten
        // ist). Eine schreibfreie MigrationRunner::trackingTableExists()
        // würde das konsolidieren, wäre aber eine Änderung an
        // scripts/MigrationRunner.php und damit außerhalb des Datei-Scopes
        // dieser Korrektur (siehe task-T2.1.notes.md).
        $trackingTable = $dbPrefix . 'schema_migrations';
        $tableCheck = $conn->query(
            "SHOW TABLES LIKE '" . $conn->real_escape_string($trackingTable) . "'"
        );

        if ($tableCheck === false) {
            migrateFail('MIGRATE FAIL: Prüfung auf Tracking-Tabelle fehlgeschlagen: ' . $conn->error, $conn);
        }

        $trackingTableExists = $tableCheck->num_rows > 0;

        $appliedVersions = $trackingTableExists
            ? array_flip(MigrationRunner::getAppliedVersions($conn, $dbPrefix))
            : [];
        $pendingVersions = [];

        foreach (MigrationRunner::getAvailableMigrations($migrationsDirOverride) as $file) {
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

        migrateExit($conn);
    }

    MigrationRunner::ensureTrackingTable($conn, $dbPrefix);
    $result = MigrationRunner::runPending($conn, $dbPrefix, $migrationsDirOverride);

    foreach ($result['log'] as $logLine) {
        echo $logLine . PHP_EOL;
    }

    if ($result['failedVersion'] !== null) {
        migrateFail(sprintf(
            'MIGRATE FAIL bei %s: %s',
            $result['failedVersion'],
            $result['failedError'] ?? 'unbekannter Fehler'
        ), $conn);
    }

    printf(
        'MIGRATE OK: %d angewendet, %d übersprungen%s',
        count($result['applied']),
        count($result['skipped']),
        PHP_EOL
    );
    migrateExit($conn);
} catch (Throwable $exception) {
    // Fängt u. a. RuntimeException aus MigrationRunner::recordMigration()
    // ab, falls das Eintragen einer erfolgreich angewendeten Migration
    // fehlschlägt (runPending() reicht diese ungefangen durch). Die Meldung
    // enthält die Versionsnummer meist im Fließtext
    // ("Migration NNN konnte nicht in ... eingetragen werden: ..."),
    // außer wenn bereits das Prepare fehlschlägt (dann lautet die Meldung
    // "Prepare fehlgeschlagen: ..." ohne Versionsnummer, empirisch gegen
    // Docker verifiziert). In beiden Fällen entspricht das Format nicht
    // exakt dem "MIGRATE FAIL bei NNN: <fehler>"-Schema von design.md D6.
    // Eine strukturelle Angleichung erfordert, dass runPending()
    // (scripts/MigrationRunner.php) diesen Fehlerfall selbst in die
    // failedVersion/failedError-Struktur abbildet — außerhalb des
    // Datei-Scopes dieser Korrektur (siehe task-T2.1.notes.md).
    migrateFail('MIGRATE FAIL: ' . $exception->getMessage(), $conn);
}
