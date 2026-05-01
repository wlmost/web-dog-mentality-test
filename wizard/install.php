<?php
declare(strict_types=1);

set_time_limit(300);

require_once __DIR__ . '/WizardHelper.php';

if (WizardHelper::isLocked()) {
    http_response_code(403);
    echo renderPage('Wizard gesperrt', '<p class="error">Der Wizard wurde nach erfolgreicher Installation gesperrt.</p><p>Bitte entfernen Sie das <code>/wizard/</code>-Verzeichnis per FTP.</p>');
    exit();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$step    = (int)($_SESSION['install_step'] ?? 1);
$error   = '';
$success = '';

// POST verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    WizardHelper::verifyCsrf();

    $action = $_POST['action'] ?? '';

    // Schritt 1: DB-Verbindung testen
    if ($action === 'test_connection') {
        $host = trim($_POST['db_host'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = $_POST['db_pass'] ?? '';
        $name = trim($_POST['db_name'] ?? '');

        try {
            $conn = WizardHelper::testConnection($host, $user, $pass, $name);
            $conn->close();
            $_SESSION['install_db'] = compact('host', 'user', 'pass', 'name');
            $step = 2;
            $_SESSION['install_step'] = $step;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }

    // Schritt 2: Präfix konfigurieren
    elseif ($action === 'set_prefix') {
        $prefix = trim($_POST['db_prefix'] ?? 'dmt_');
        try {
            $prefix = WizardHelper::validatePrefix($prefix);
            $_SESSION['install_prefix'] = $prefix;
            $step = 3;
            $_SESSION['install_step'] = $step;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
            $step  = 2;
        }
    }

    // Schritt 3: Schema einspielen
    elseif ($action === 'run_schema') {
        $db     = $_SESSION['install_db']    ?? [];
        $prefix = $_SESSION['install_prefix'] ?? '';

        try {
            $conn = WizardHelper::testConnection($db['host'], $db['user'], $db['pass'], $db['name']);
            $log  = [];

            $schemaFiles = [
                __DIR__ . '/../database/schema.sql',
                __DIR__ . '/../database/schema-auth.sql',
            ];

            foreach ($schemaFiles as $file) {
                $result = WizardHelper::executeSqlFile($conn, $file, $prefix);
                $log    = array_merge($log, $result['log']);
                if (!$result['success']) {
                    $conn->close();
                    $error = 'Schema-Fehler – bitte Log prüfen.';
                    $_SESSION['install_log'] = $log;
                    break;
                }
            }

            if (!$error) {
                // Alle Migrations als bereits angewendet markieren (Neuinstallation)
                $migrations = WizardHelper::getAvailableMigrations();
                foreach ($migrations as $file) {
                    $version     = WizardHelper::getMigrationVersion($file);
                    $description = WizardHelper::getMigrationDescription($file);
                    WizardHelper::recordMigration($conn, $prefix, $version, $description);
                    $log[] = "✅ Migration $version als angewendet markiert";
                }
                $conn->close();
                $_SESSION['install_log'] = $log;
                $step = 4;
                $_SESSION['install_step'] = $step;
            }
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }

    // Schritt 4: Admin-User anlegen
    elseif ($action === 'create_admin') {
        $db        = $_SESSION['install_db']    ?? [];
        $prefix    = $_SESSION['install_prefix'] ?? '';
        $adminUser = trim($_POST['admin_user'] ?? '');
        $adminMail = trim($_POST['admin_email'] ?? '');
        $adminPass = $_POST['admin_pass']  ?? '';
        $adminPass2 = $_POST['admin_pass2'] ?? '';

        try {
            if ($adminUser === '' || $adminMail === '') {
                throw new InvalidArgumentException('Benutzername und E-Mail dürfen nicht leer sein.');
            }
            if (!filter_var($adminMail, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Ungültige E-Mail-Adresse.');
            }
            if ($adminPass !== $adminPass2) {
                throw new InvalidArgumentException('Passwörter stimmen nicht überein.');
            }
            WizardHelper::validatePassword($adminPass);

            $conn = WizardHelper::testConnection($db['host'], $db['user'], $db['pass'], $db['name']);
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $table = $prefix . 'auth_users';
            $stmt = $conn->prepare(
                "INSERT INTO `$table` (username, password_hash, email, full_name, is_admin, is_active) VALUES (?, ?, ?, ?, TRUE, TRUE)"
            );
            if (!$stmt) {
                throw new RuntimeException('Datenbankfehler: ' . $conn->error);
            }
            $fullName = $adminUser;
            $stmt->bind_param('ssss', $adminUser, $hash, $adminMail, $fullName);
            $stmt->execute();
            $stmt->close();
            $conn->close();

            $step = 5;
            $_SESSION['install_step'] = $step;
        } catch (InvalidArgumentException | RuntimeException $e) {
            $error = $e->getMessage();
            $step  = 4;
        }
    }

    // Schritt 5: Konfiguration schreiben & abschließen
    elseif ($action === 'finalize') {
        $db     = $_SESSION['install_db']    ?? [];
        $prefix = $_SESSION['install_prefix'] ?? '';

        try {
            WizardHelper::writeConfig($db['host'], $db['user'], $db['pass'], $db['name'], $prefix);
            WizardHelper::lock();

            // Session bereinigen
            unset($_SESSION['install_step'], $_SESSION['install_db'], $_SESSION['install_prefix'], $_SESSION['install_log']);

            echo renderPage('Installation abgeschlossen', renderFinished());
            exit();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

// Seite ausgeben
$stepTitles = [
    1 => 'Schritt 1: Datenbankverbindung',
    2 => 'Schritt 2: Tabellenpräfix',
    3 => 'Schritt 3: Datenbanktabellen erstellen',
    4 => 'Schritt 4: Admin-Benutzer anlegen',
    5 => 'Schritt 5: Installation abschließen',
];

echo renderPage('Installation – ' . ($stepTitles[$step] ?? ''), renderStep($step, $error, $_SESSION['install_db'] ?? [], $_SESSION['install_prefix'] ?? 'dmt_', $_SESSION['install_log'] ?? []));


// ---------------------------------------------------------------
// View-Funktionen
// ---------------------------------------------------------------

function renderStep(int $step, string $error, array $db, string $prefix, array $log): string
{
    $csrf  = WizardHelper::getCsrfToken();
    $html  = '';

    if ($error !== '') {
        $html .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
    }

    switch ($step) {
        case 1:
            $html .= <<<HTML
            <form method="post">
                <input type="hidden" name="csrf_token" value="$csrf">
                <input type="hidden" name="action" value="test_connection">
                <div class="form-group">
                    <label>Datenbank-Host</label>
                    <input type="text" name="db_host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label>Datenbankname</label>
                    <input type="text" name="db_name" required>
                </div>
                <div class="form-group">
                    <label>Datenbankbenutzer</label>
                    <input type="text" name="db_user" required>
                </div>
                <div class="form-group">
                    <label>Passwort</label>
                    <input type="password" name="db_pass">
                </div>
                <button type="submit">Verbindung testen &amp; weiter →</button>
            </form>
            HTML;
            break;

        case 2:
            $html .= <<<HTML
            <p class="success">✅ Datenbankverbindung erfolgreich!</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="$csrf">
                <input type="hidden" name="action" value="set_prefix">
                <div class="form-group">
                    <label>Tabellenpräfix</label>
                    <input type="text" name="db_prefix" value="dmt_" pattern="[a-z0-9_]{0,20}"
                           placeholder="z.B. dmt_">
                    <small>Nur Kleinbuchstaben, Ziffern und _ erlaubt (max. 20 Zeichen). Leer lassen für keinen Präfix.</small>
                    <div class="alert warning">⚠️ Auf Shared Hosting empfehlen wir einen eindeutigen Präfix, um Konflikte mit anderen Anwendungen zu vermeiden.</div>
                </div>
                <button type="submit">Weiter →</button>
            </form>
            HTML;
            break;

        case 3:
            $prefixDisplay = htmlspecialchars($prefix);
            $html .= <<<HTML
            <p>Gewählter Präfix: <strong>$prefixDisplay</strong></p>
            <p>Die folgenden Schema-Dateien werden jetzt eingespielt:</p>
            <ul>
                <li>database/schema.sql</li>
                <li>database/schema-auth.sql</li>
            </ul>
            <form method="post">
                <input type="hidden" name="csrf_token" value="$csrf">
                <input type="hidden" name="action" value="run_schema">
                <button type="submit">Tabellen erstellen →</button>
            </form>
            HTML;
            break;

        case 4:
            $logHtml = $log ? WizardHelper::renderLog($log) : '';
            $html .= <<<HTML
            <p class="success">✅ Datenbanktabellen wurden erfolgreich erstellt.</p>
            $logHtml
            <hr>
            <form method="post">
                <input type="hidden" name="csrf_token" value="$csrf">
                <input type="hidden" name="action" value="create_admin">
                <div class="form-group">
                    <label>Benutzername</label>
                    <input type="text" name="admin_user" value="admin" required>
                </div>
                <div class="form-group">
                    <label>E-Mail</label>
                    <input type="email" name="admin_email" required>
                </div>
                <div class="form-group">
                    <label>Passwort</label>
                    <input type="password" name="admin_pass" required>
                    <small>Min. 10 Zeichen, Groß- und Kleinbuchstaben sowie Ziffern.</small>
                </div>
                <div class="form-group">
                    <label>Passwort bestätigen</label>
                    <input type="password" name="admin_pass2" required>
                </div>
                <button type="submit">Admin anlegen &amp; weiter →</button>
            </form>
            HTML;
            break;

        case 5:
            $html .= <<<HTML
            <p class="success">✅ Admin-Benutzer wurde angelegt.</p>
            <p>Klicken Sie auf <strong>„Installation abschließen"</strong>, um die Konfigurationsdatei zu schreiben und den Wizard zu sperren.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="$csrf">
                <input type="hidden" name="action" value="finalize">
                <button type="submit" class="primary">Installation abschließen ✓</button>
            </form>
            HTML;
            break;
    }

    return $html;
}

function renderFinished(): string
{
    return <<<HTML
    <div class="alert success">
        <h2>✅ Installation erfolgreich abgeschlossen!</h2>
        <p>Die Konfigurationsdatei <code>api/config.local.php</code> wurde geschrieben und der Wizard ist nun gesperrt.</p>
    </div>
    <div class="alert warning">
        <h3>⚠️ Wichtiger Sicherheitshinweis</h3>
        <p>Bitte entfernen Sie das Verzeichnis <code>/wizard/</code> <strong>sofort</strong> per FTP von Ihrem Server!</p>
    </div>
    <p><a href="../frontend/index.html" class="button">Zur Anwendung →</a></p>
    HTML;
}

function renderPage(string $title, string $content): string
{
    $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    return <<<HTML
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dog Mentality Test – $title</title>
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: system-ui, sans-serif; background: #f5f5f5; color: #333; padding: 2rem; }
            .container { max-width: 640px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 2rem; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
            h1 { font-size: 1.4rem; margin-bottom: 1.5rem; color: #1a1a2e; border-bottom: 2px solid #e0e0e0; padding-bottom: .75rem; }
            h2 { font-size: 1.1rem; margin: 1rem 0 .5rem; }
            .form-group { margin-bottom: 1rem; }
            label { display: block; font-weight: 600; margin-bottom: .25rem; font-size: .9rem; }
            input[type=text], input[type=email], input[type=password] { width: 100%; padding: .5rem .75rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; }
            input:focus { outline: none; border-color: #4a90d9; box-shadow: 0 0 0 2px rgba(74,144,217,.2); }
            small { display: block; margin-top: .25rem; color: #666; font-size: .8rem; }
            button { padding: .6rem 1.4rem; background: #4a90d9; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; margin-top: .5rem; }
            button:hover { background: #357abd; }
            button.primary { background: #27ae60; }
            button.primary:hover { background: #219150; }
            .alert { padding: .75rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; }
            .alert.error, div.alert.error { background: #fdecea; border-left: 4px solid #e74c3c; color: #c0392b; }
            .alert.success { background: #eafaf1; border-left: 4px solid #27ae60; color: #1e8449; }
            .alert.warning { background: #fef9e7; border-left: 4px solid #f39c12; color: #9a7d0a; }
            p.success { color: #27ae60; margin-bottom: 1rem; font-weight: 600; }
            p.error  { color: #e74c3c; }
            ul.log { list-style: none; font-size: .8rem; max-height: 200px; overflow-y: auto; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: .5rem; margin-bottom: 1rem; }
            ul.log li { padding: .15rem 0; }
            ul.log li.error { color: #c0392b; font-weight: 600; }
            code { background: #f0f0f0; padding: .1rem .3rem; border-radius: 3px; font-size: .9em; }
            hr { border: none; border-top: 1px solid #e0e0e0; margin: 1.5rem 0; }
            a.button { display: inline-block; padding: .6rem 1.4rem; background: #4a90d9; color: #fff; border-radius: 4px; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🐾 Dog Mentality Test – $title</h1>
            $content
        </div>
    </body>
    </html>
    HTML;
}
