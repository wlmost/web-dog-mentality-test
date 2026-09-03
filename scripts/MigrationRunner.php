<?php
declare(strict_types=1);

/**
 * MigrationRunner – nicht-interaktiver, idempotenter Datenbank-Migrations-Runner.
 *
 * Wird von scripts/migrate.php (CLI, per SSH im Deploy aufrufbar) verwendet.
 * Bewusst **ohne** Abhängigkeit auf wizard/WizardHelper.php: der Wizard wird
 * laut Deploy-Checkliste nach der Installation vom Server entfernt, der
 * Runner muss also zur Laufzeit ohne wizard/ funktionieren.
 *
 * Die SQL-Split-/Ausführungslogik (executeSqlFile/splitSqlStatements) sowie
 * die Helfer getAvailableMigrations/getMigrationVersion/
 * getMigrationDescription/getAppliedMigrations/recordMigration sind
 * bewusstes Duplikat der Semantik aus wizard/WizardHelper.php, damit das
 * Laufzeitverhalten (inkl. über Statement-Grenzen hinweg geteilter
 * MySQL-Session-Variablen bei den Guard-Migrationen 001–003) identisch
 * bleibt. Eine Zusammenführung beider Implementierungen in eine gemeinsame
 * Klasse ist als Folge-Change vorgesehen
 * (openspec/changes/add-db-migration-runner/design.md, Open Questions) und
 * bewusst nicht Teil dieses Changes.
 */
class MigrationRunner
{
    /**
     * Gibt alle verfügbaren Migrationsdateien zurück, numerisch aufsteigend
     * nach dem führenden Zahlpräfix des Dateinamens sortiert
     * (z. B. 002_*.sql vor 010_*.sql).
     *
     * @return string[] Absolute Pfade zu den Migrationsdateien
     */
    public static function getAvailableMigrations(): array
    {
        $dir = __DIR__ . '/../database/migrations/';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '*.sql');
        if ($files === false) {
            return [];
        }

        usort(
            $files,
            static fn(string $a, string $b): int =>
                (int)self::getMigrationVersion($a) <=> (int)self::getMigrationVersion($b)
        );

        return $files;
    }

    /**
     * Liest die Version aus dem führenden \d+-Teil des Dateinamens:
     * "004_add_ai_rate_limits.sql" -> "004".
     */
    public static function getMigrationVersion(string $filePath): string
    {
        $base = basename($filePath, '.sql');
        if (preg_match('/^(\d+)/', $base, $matches) === 1) {
            return $matches[1];
        }

        return $base;
    }

    /**
     * Liest die Beschreibung aus der Kommentarzeile "-- Description: ...".
     * Fällt auf den Dateinamen zurück, falls keine solche Zeile existiert.
     */
    public static function getMigrationDescription(string $filePath): string
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^-- Description:\s*(.+)$/i', $line, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return basename($filePath);
    }

    /**
     * Legt die Tracking-Tabelle idempotent an (DDL identisch zu
     * database/schema.sql:208-213). {{PREFIX}} wird durch $prefix ersetzt.
     *
     * @throws RuntimeException wenn das CREATE TABLE fehlschlägt
     */
    public static function ensureTrackingTable(mysqli $conn, string $prefix): void
    {
        $table = $prefix . 'schema_migrations';
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (\n"
            . "  version     VARCHAR(20)  NOT NULL,\n"
            . "  applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  description VARCHAR(255) NOT NULL DEFAULT '',\n"
            . "  PRIMARY KEY (version)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        [$success, $error] = self::runQuery($conn, $sql);
        if (!$success) {
            throw new RuntimeException(
                "Tracking-Tabelle `$table` konnte nicht angelegt werden: " . $error
            );
        }
    }

    /**
     * Gibt die bereits eingetragenen Migrationsversionen zurück.
     *
     * @return string[]
     * @throws RuntimeException wenn die Abfrage fehlschlägt
     */
    public static function getAppliedVersions(mysqli $conn, string $prefix): array
    {
        $table = $prefix . 'schema_migrations';
        [$result, $error] = self::runQuery($conn, "SELECT version FROM `$table` ORDER BY version");
        if ($result === false) {
            throw new RuntimeException(
                "Angewendete Migrationen konnten nicht gelesen werden (`$table`): " . $error
            );
        }

        $versions = [];
        while ($row = $result->fetch_assoc()) {
            $versions[] = $row['version'];
        }

        return $versions;
    }

    /**
     * Führt eine Migrationsdatei aus: ersetzt {{PREFIX}}, verwirft
     * Kommentarzeilen (-- oder #), splittet an Zeilenenden auf ";" und
     * führt jedes Statement einzeln über dieselbe mysqli-Verbindung aus.
     * Damit bleiben MySQL-Session-Variablen (SET @var, PREPARE/EXECUTE/
     * DEALLOCATE) über Statement-Grenzen hinweg erhalten – identische
     * Semantik zu WizardHelper::executeSqlFile().
     *
     * @return array{
     *     success: bool,
     *     log: string[],
     *     error: string|null,
     *     failedStatement: string|null
     * }
     */
    public static function executeSqlFile(mysqli $conn, string $filePath, string $prefix): array
    {
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'log' => ["Datei nicht gefunden: $filePath"],
                'error' => "Datei nicht gefunden: $filePath",
                'failedStatement' => null,
            ];
        }

        $sql = (string)file_get_contents($filePath);
        $sql = str_replace('{{PREFIX}}', $prefix, $sql);

        $statements = self::splitSqlStatements($sql);
        $log = [];

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            [$queryResult, $error] = self::runQuery($conn, $statement);
            if ($queryResult === false) {
                $shortStatement = self::safeSubstr($statement, 0, 100);
                $log[] = '❌ FEHLER: ' . $error . ' | SQL: ' . $shortStatement;

                return [
                    'success' => false,
                    'log' => $log,
                    'error' => $error,
                    'failedStatement' => $shortStatement,
                ];
            }

            $firstLine = trim((string)strtok($statement, "\n"));
            $log[] = '✅ ' . self::safeSubstr($firstLine, 0, 80);
        }

        return [
            'success' => true,
            'log' => $log,
            'error' => null,
            'failedStatement' => null,
        ];
    }

    /**
     * Trägt eine erfolgreich angewendete Migration ein.
     *
     * @throws RuntimeException wenn das Prepared Statement fehlschlägt
     */
    public static function recordMigration(mysqli $conn, string $prefix, string $version, string $description): void
    {
        $table = $prefix . 'schema_migrations';

        try {
            $stmt = $conn->prepare("INSERT INTO `$table` (version, description) VALUES (?, ?)");
            if (!$stmt) {
                throw new RuntimeException('Prepare fehlgeschlagen: ' . $conn->error);
            }

            $stmt->bind_param('ss', $version, $description);
            $stmt->execute();
            $stmt->close();
        } catch (mysqli_sql_exception $exception) {
            throw new RuntimeException(
                "Migration $version konnte nicht in `$table` eingetragen werden: " . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * Führt eine Query aus und liefert das mysqli::query()-Ergebnis sowie
     * eine Fehlermeldung zurück, egal ob der mysqli-Treiber im
     * fehlerbasierten Report-Modus (Standard seit PHP 8.1: wirft
     * mysqli_sql_exception) oder im traditionellen Modus (gibt false
     * zurück) läuft. Dadurch verhält sich der Runner unabhängig von der
     * PHP-Version und dem konfigurierten mysqli_report()-Level identisch.
     *
     * @return array{0: mysqli_result|bool, 1: string|null}
     */
    private static function runQuery(mysqli $conn, string $sql): array
    {
        try {
            $result = $conn->query($sql);
            return [$result, $result === false ? $conn->error : null];
        } catch (mysqli_sql_exception $exception) {
            return [false, $exception->getMessage()];
        }
    }

    /**
     * Wendet alle ausstehenden Migrationen in numerischer Reihenfolge an.
     * Setzt voraus, dass die Tracking-Tabelle bereits existiert
     * (siehe ensureTrackingTable()).
     *
     * Stoppt beim ersten fehlschlagenden Statement sofort: die fehlgeschlagene
     * Migration wird **nicht** eingetragen, bereits zuvor erfolgreich
     * angewendete Migrationen bleiben eingetragen.
     *
     * @return array{
     *     applied: array<int, array{version: string, description: string}>,
     *     skipped: string[],
     *     log: string[],
     *     failedVersion: string|null,
     *     failedFile: string|null,
     *     failedError: string|null,
     *     failedStatement: string|null
     * }
     */
    public static function runPending(mysqli $conn, string $prefix): array
    {
        $appliedVersions = self::getAppliedVersions($conn, $prefix);
        $appliedLookup = array_flip($appliedVersions);

        $applied = [];
        $skipped = [];
        $log = [];

        foreach (self::getAvailableMigrations() as $file) {
            $version = self::getMigrationVersion($file);

            if (isset($appliedLookup[$version])) {
                $skipped[] = $version;
                continue;
            }

            $description = self::getMigrationDescription($file);
            $result = self::executeSqlFile($conn, $file, $prefix);
            $log = array_merge($log, $result['log']);

            if (!$result['success']) {
                return [
                    'applied' => $applied,
                    'skipped' => $skipped,
                    'log' => $log,
                    'failedVersion' => $version,
                    'failedFile' => $file,
                    'failedError' => $result['error'],
                    'failedStatement' => $result['failedStatement'],
                ];
            }

            self::recordMigration($conn, $prefix, $version, $description);
            $applied[] = ['version' => $version, 'description' => $description];
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            'log' => $log,
            'failedVersion' => null,
            'failedFile' => null,
            'failedError' => null,
            'failedStatement' => null,
        ];
    }

    /**
     * Spaltet SQL-Text in einzelne Statements auf: Kommentarzeilen (-- oder
     * #) werden verworfen, der Rest wird an Zeilen getrennt, die auf ";"
     * enden. Identische Semantik zu WizardHelper::splitSqlStatements().
     *
     * @return string[]
     */
    private static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $lines = explode("\n", $sql);

        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (strncmp($trimmed, '--', 2) === 0 || strncmp($trimmed, '#', 1) === 0) {
                continue;
            }

            $current .= $line . "\n";
            if (substr(rtrim($line), -1) === ';') {
                $statements[] = trim($current);
                $current = '';
            }
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /**
     * Kürzt einen String sicher auf $length Zeichen (mb_substr wenn
     * verfügbar, sonst byte-basiertes substr als Fallback).
     */
    private static function safeSubstr(string $str, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($str, $start, $length, 'UTF-8');
        }

        return substr($str, $start, $length);
    }
}
