<?php
declare(strict_types=1);

/**
 * WizardHelper – Gemeinsame Hilfsfunktionen für Install- und Update-Wizard
 */
class WizardHelper
{
    /** Prüft ob der Wizard gesperrt ist (nach erfolgreicher Installation) */
    public static function isLocked(): bool
    {
        $lockFile = __DIR__ . '/.lock';
        if (!file_exists($lockFile)) {
            return false;
        }
        // Placeholder-Inhalt = noch nicht gesperrt
        return trim((string)file_get_contents($lockFile)) !== 'inactive';
    }

    /**
     * Setzt Lock-File nach erfolgreicher Installation.
     * Schreibt Timestamp in die bereits per FTP/Git vorhandene Placeholder-Datei.
     * Das Überschreiben einer existierenden Datei erfordert nur Schreibrecht
     * auf die Datei selbst, nicht auf das Verzeichnis.
     */
    public static function lock(): void
    {
        $lockFile = __DIR__ . '/.lock';
        if (!file_exists($lockFile)) {
            throw new RuntimeException(
                'Die Datei wizard/.lock fehlt auf dem Server. '
                . 'Bitte laden Sie die Datei aus dem Repository per FTP in das wizard/-Verzeichnis hoch und versuchen Sie es erneut.'
            );
        }
        if (file_put_contents($lockFile, date('Y-m-d H:i:s')) === false) {
            throw new RuntimeException(
                'Wizard-Lock konnte nicht geschrieben werden. '
                . 'Bitte prüfen Sie die Schreibrechte der Datei wizard/.lock (mindestens 644 empfohlen).'
            );
        }
    }

    /** Testet DB-Verbindung und gibt mysqli-Objekt oder Exception zurück */
    public static function testConnection(string $host, string $user, string $pass, string $name): mysqli
    {
        $conn = new mysqli($host, $user, $pass, $name);
        if ($conn->connect_error) {
            throw new RuntimeException('Verbindungsfehler: ' . $conn->connect_error);
        }
        if (!$conn->set_charset('utf8mb4')) {
            throw new RuntimeException('Charset-Fehler: ' . $conn->error);
        }
        return $conn;
    }

    /**
     * Löscht alle Tabellen und Views mit dem angegebenen Präfix.
     * Deaktiviert FK-Checks temporär, damit die Reihenfolge keine Rolle spielt.
     * Gibt ein Log-Array zurück.
     */
    public static function dropAllTables(mysqli $conn, string $prefix, string $dbName): array
    {
        $log = [];
        $conn->query('SET FOREIGN_KEY_CHECKS = 0');

        $escapedDb     = $conn->real_escape_string($dbName);
        $likePattern   = $conn->real_escape_string($prefix) . '%';

        // Views löschen
        $result = $conn->query(
            "SELECT TABLE_NAME FROM information_schema.VIEWS
             WHERE TABLE_SCHEMA = '$escapedDb' AND TABLE_NAME LIKE '$likePattern'"
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $view = $row['TABLE_NAME'];
                $conn->query("DROP VIEW IF EXISTS `$view`");
                $log[] = "✅ View gelöscht: $view";
            }
        }

        // Tabellen löschen
        $result = $conn->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = '$escapedDb'
               AND TABLE_TYPE = 'BASE TABLE'
               AND TABLE_NAME LIKE '$likePattern'"
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $table = $row['TABLE_NAME'];
                $conn->query("DROP TABLE IF EXISTS `$table`");
                $log[] = "✅ Tabelle gelöscht: $table";
            }
        }

        $conn->query('SET FOREIGN_KEY_CHECKS = 1');
        return $log;
    }

    /**
     * Führt eine SQL-Datei aus, ersetzt {{PREFIX}} durch den konfigurierten Präfix.
     * Gibt Array mit ['success' => bool, 'log' => string[]] zurück.
     */
    public static function executeSqlFile(mysqli $conn, string $filePath, string $prefix): array
    {
        if (!file_exists($filePath)) {
            return ['success' => false, 'log' => ["Datei nicht gefunden: $filePath"]];
        }

        $sql = file_get_contents($filePath);
        $sql = str_replace('{{PREFIX}}', $prefix, $sql);

        // Kommentarzeilen entfernen, Statements aufteilen
        $statements = self::splitSqlStatements($sql);
        $log = [];

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            if (!$conn->query($statement)) {
                $log[] = '❌ FEHLER: ' . $conn->error . ' | SQL: ' . self::safeSubstr($statement, 0, 100);
                return ['success' => false, 'log' => $log];
            }
            // Erste Zeile des Statements als Log-Eintrag
            $firstLine = trim(strtok($statement, "\n"));
            $log[] = '✅ ' . self::safeSubstr($firstLine, 0, 80);
        }

        return ['success' => true, 'log' => $log];
    }

    /**
     * Kürzt einen String sicher auf $length Zeichen.
     * Nutzt mb_substr (UTF-8-korrekt) wenn die mbstring-Extension verfügbar ist,
     * andernfalls substr() als Fallback (byte-basiert; Multibyte-Zeichen an der
     * Grenzposition können dadurch abgeschnitten werden).
     */
    private static function safeSubstr(string $str, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($str, $start, $length, 'UTF-8');
        }
        return substr($str, $start, $length);
    }

    /** Spaltet SQL-Text in einzelne Statements auf (delimiter-aware) */
    private static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $lines = explode("\n", $sql);

        foreach ($lines as $line) {
            // Kommentarzeilen überspringen
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

    /** Prüft ob config.local.php bereits existiert */
    public static function isConfigured(): bool
    {
        return file_exists(__DIR__ . '/../api/config.local.php');
    }

    /** Erzeugt den Inhalt der config.local.php als String */
    public static function generateConfigContent(string $host, string $user, string $pass, string $name, string $prefix): string
    {
        $host   = addslashes($host);
        $user   = addslashes($user);
        $pass   = addslashes($pass);
        $name   = addslashes($name);
        $prefix = addslashes($prefix);

        return "<?php\n"
            . "declare(strict_types=1);\n"
            . "// Automatisch generiert vom Installations-Wizard – " . date('Y-m-d H:i:s') . "\n"
            . "// Diese Datei nicht ins Repository einchecken!\n\n"
            . "define('DB_HOST',   '$host');\n"
            . "define('DB_USER',   '$user');\n"
            . "define('DB_PASS',   '$pass');\n"
            . "define('DB_NAME',   '$name');\n"
            . "define('DB_PREFIX', '$prefix');\n";
    }

    /**
     * Schreibt Konfiguration in api/config.local.php.example (muss 666 haben),
     * benennt sie dann in config.local.php um und setzt Rechte auf 600.
     */
    public static function writeConfig(string $host, string $user, string $pass, string $name, string $prefix): void
    {
        $examplePath = __DIR__ . '/../api/config.local.php.example';
        $targetPath  = __DIR__ . '/../api/config.local.php';

        if (!file_exists($examplePath)) {
            throw new RuntimeException(
                'Die Datei api/config.local.php.example fehlt auf dem Server. '
                . 'Bitte laden Sie sie aus dem Repository per FTP in das api/-Verzeichnis hoch (Rechte: 666).'
            );
        }

        $content = self::generateConfigContent($host, $user, $pass, $name, $prefix);

        // Schritt 1: Daten in die beschreibbare .example-Datei schreiben
        if (file_put_contents($examplePath, $content) === false) {
            throw new RuntimeException(
                'api/config.local.php.example konnte nicht beschrieben werden. '
                . 'Bitte prüfen Sie die Dateirechte (mindestens 666 empfohlen).'
            );
        }

        // Schritt 2: Umbennen in config.local.php
        // Existierende Zieldatei ggf. vorher entfernen
        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }
        if (!rename($examplePath, $targetPath)) {
            throw new RuntimeException(
                'api/config.local.php.example konnte nicht in config.local.php umbenannt werden. '
                . 'Bitte prüfen Sie die Schreibrechte auf dem api/-Verzeichnis.'
            );
        }

        // Schritt 3: Rechte absichern
        @chmod($targetPath, 0600);
    }

    /** Validiert den Tabellenpräfix: nur a-z, 0-9, _ ; max. 20 Zeichen */
    public static function validatePrefix(string $prefix): string
    {
        if ($prefix === '') {
            return '';
        }
        if (!preg_match('/^[a-z0-9_]{1,20}$/', $prefix)) {
            throw new InvalidArgumentException('Tabellenpräfix darf nur Kleinbuchstaben, Ziffern und _ enthalten (max. 20 Zeichen).');
        }
        return $prefix;
    }

    /** Validiert Passwortstärke: min. 10 Zeichen */
    public static function validatePassword(string $password): void
    {
        if (strlen($password) < 10) {
            throw new InvalidArgumentException('Passwort muss mindestens 10 Zeichen lang sein.');
        }
        if (!preg_match('/[A-Z]/', $password)) {
            throw new InvalidArgumentException('Passwort muss mindestens einen Großbuchstaben enthalten.');
        }
        if (!preg_match('/[a-z]/', $password)) {
            throw new InvalidArgumentException('Passwort muss mindestens einen Kleinbuchstaben enthalten.');
        }
        if (!preg_match('/[0-9]/', $password)) {
            throw new InvalidArgumentException('Passwort muss mindestens eine Ziffer enthalten.');
        }
    }

    /** Gibt alle verfügbaren Migrations-Dateien sortiert zurück */
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
        sort($files);
        return $files;
    }

    /**
     * Liest die Version aus dem Dateinamen: 001_foo.sql → '001'
     */
    public static function getMigrationVersion(string $filePath): string
    {
        $base = basename($filePath, '.sql');
        return explode('_', $base)[0];
    }

    /** Liest die Description aus dem SQL-Kommentar -- Description: ... */
    public static function getMigrationDescription(string $filePath): string
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^-- Description:\s*(.+)$/i', $line, $m)) {
                return trim($m[1]);
            }
        }
        return basename($filePath);
    }

    /** Gibt bereits eingespielten Migrations-Versionen aus DB zurück */
    public static function getAppliedMigrations(mysqli $conn, string $prefix): array
    {
        $table = $prefix . 'schema_migrations';
        $result = $conn->query("SELECT version FROM `$table` ORDER BY version");
        if (!$result) {
            return [];
        }
        $versions = [];
        while ($row = $result->fetch_assoc()) {
            $versions[] = $row['version'];
        }
        return $versions;
    }

    /** Trägt eine Migration als angewendet in die DB ein */
    public static function recordMigration(mysqli $conn, string $prefix, string $version, string $description): void
    {
        $table = $prefix . 'schema_migrations';
        $stmt = $conn->prepare("INSERT INTO `$table` (version, description) VALUES (?, ?)");
        if (!$stmt) {
            throw new RuntimeException('Prepare fehlgeschlagen: ' . $conn->error);
        }
        $stmt->bind_param('ss', $version, $description);
        $stmt->execute();
        $stmt->close();
    }

    /** Gibt ein formatiertes HTML-Log aus */
    public static function renderLog(array $log): string
    {
        $html = '<ul class="log">';
        foreach ($log as $entry) {
            $class = (strpos($entry, '❌') === 0) ? 'error' : 'ok';
            $html .= '<li class="' . $class . '">' . htmlspecialchars($entry) . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /** Gibt CSRF-Token aus Session zurück oder erstellt eines */
    public static function getCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['wizard_csrf'])) {
            $_SESSION['wizard_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['wizard_csrf'];
    }

    /** Prüft CSRF-Token aus POST */
    public static function verifyCsrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token        = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['wizard_csrf'] ?? '';
        // Explizit leere Tokens ablehnen – verhindert hash_equals('', '') === true
        if ($sessionToken === '' || $token === '' || !hash_equals($sessionToken, $token)) {
            http_response_code(403);
            die('Ungültige Anfrage (CSRF-Schutz).');
        }
    }
}
