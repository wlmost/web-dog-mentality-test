<?php
declare(strict_types=1);

/**
 * Generischer Subprozess-Runner fuer tests/migration-runner-test.php.
 *
 * Wird NICHT direkt vom Menschen aufgerufen. Laedt genau EINE
 * MigrationRunner.php (echt oder isolierte Testkopie -- Pfad kommt aus der
 * Parameterdatei) und fuehrt eine Liste von Operationen dagegen aus. Jede
 * Testgruppe im Elternprozess bekommt ihren eigenen Subprozess-Aufruf, weil
 * PHP innerhalb eines Prozesses nicht zwei unterschiedliche Dateien mit
 * jeweils "class MigrationRunner" laden kann (Redeclare-Fehler).
 *
 * argv[1] = Pfad zu einer JSON-Datei mit:
 * {
 *   "classPath": "...",              // require-Pfad zu MigrationRunner.php
 *   "dbHost": "...", "dbPort": 3306, "dbUser": "...", "dbPass": "...",
 *   "dbName": "..."|null,             // null => keine DB-Verbindung noetig
 *   "prefix": "mrp_",
 *   "steps": [ {"op": "...", ...je nach op weitere Felder}, ... ]
 * }
 *
 * unterstuetzte "op"-Werte:
 *   getAvailableMigrations                              -> files[], versions[]
 *   getMigrationVersion       {file}                     -> result
 *   getMigrationDescription   {file}                     -> result
 *   ensureTrackingTable                                  -> ok
 *   getAppliedVersions                                   -> result
 *   runPending                                           -> result (Array aus MigrationRunner::runPending())
 *   executeSqlFile            {file}                      -> result (Array aus MigrationRunner::executeSqlFile())
 *   recordMigration           {version, description}      -> ok
 *   query                     {sql}                       -> rows[] (SELECT) oder affected (sonst)
 *
 * Gibt genau EINE Zeile JSON auf stdout aus: ein Array, ein Eintrag pro
 * Schritt, in derselben Reihenfolge wie "steps". Wirft ein Schritt eine
 * Exception, wird sie als {"exception": "Klasse: Meldung"} im jeweiligen
 * Eintrag festgehalten, die Verarbeitung der restlichen Schritte laeuft
 * weiter (damit z. B. runPending-Fehlerabbruch-Szenarien nicht den ganzen
 * Subprozess abbrechen).
 *
 * Enthaelt bewusst KEINE Assertions -- die Bewertung (PASS/FAIL) geschieht
 * ausschliesslich im Elternprozess (tests/migration-runner-test.php).
 */

$paramsFile = $argv[1] ?? null;
if ($paramsFile === null || !is_file($paramsFile)) {
    fwrite(STDERR, "Params-Datei fehlt oder nicht lesbar.\n");
    exit(2);
}

$params = json_decode((string)file_get_contents($paramsFile), true);
if (!is_array($params) || !isset($params['classPath'])) {
    fwrite(STDERR, "Params-Datei enthaelt kein gueltiges JSON.\n");
    exit(2);
}

require $params['classPath'];

$conn = null;
if (!empty($params['dbName'])) {
    $conn = new mysqli(
        (string)$params['dbHost'],
        (string)$params['dbUser'],
        (string)$params['dbPass'],
        (string)$params['dbName'],
        (int)$params['dbPort']
    );
    if ($conn->connect_errno) {
        fwrite(STDERR, 'DB-Verbindung fehlgeschlagen: ' . $conn->connect_error . "\n");
        exit(2);
    }
    $conn->set_charset('utf8mb4');
}

$prefix = (string)($params['prefix'] ?? '');
$results = [];

foreach ((array)$params['steps'] as $step) {
    $op = $step['op'] ?? '';
    $entry = ['op' => $op];

    try {
        switch ($op) {
            case 'getAvailableMigrations':
                $files = MigrationRunner::getAvailableMigrations();
                $entry['files'] = array_map('basename', $files);
                $entry['versions'] = array_map(
                    static fn(string $f): string => MigrationRunner::getMigrationVersion($f),
                    $files
                );
                break;

            case 'getMigrationVersion':
                $entry['result'] = MigrationRunner::getMigrationVersion((string)$step['file']);
                break;

            case 'getMigrationDescription':
                $entry['result'] = MigrationRunner::getMigrationDescription((string)$step['file']);
                break;

            case 'ensureTrackingTable':
                MigrationRunner::ensureTrackingTable($conn, $prefix);
                $entry['ok'] = true;
                break;

            case 'getAppliedVersions':
                $entry['result'] = MigrationRunner::getAppliedVersions($conn, $prefix);
                break;

            case 'runPending':
                $entry['result'] = MigrationRunner::runPending($conn, $prefix);
                break;

            case 'executeSqlFile':
                $entry['result'] = MigrationRunner::executeSqlFile($conn, (string)$step['file'], $prefix);
                break;

            case 'recordMigration':
                MigrationRunner::recordMigration($conn, $prefix, (string)$step['version'], (string)($step['description'] ?? ''));
                $entry['ok'] = true;
                break;

            case 'query':
                $res = $conn->query((string)$step['sql']);
                if ($res instanceof mysqli_result) {
                    $entry['rows'] = $res->fetch_all(MYSQLI_ASSOC);
                } else {
                    $entry['affected'] = $conn->affected_rows;
                }
                break;

            default:
                $entry['error'] = "Unbekannte Operation: $op";
        }
    } catch (Throwable $e) {
        $entry['exception'] = get_class($e) . ': ' . $e->getMessage();
    }

    $results[] = $entry;
}

if ($conn instanceof mysqli) {
    $conn->close();
}

echo json_encode($results);
