<?php
declare(strict_types=1);

set_time_limit(300);

require_once __DIR__ . '/WizardHelper.php';

if (WizardHelper::isLocked()) {
    http_response_code(403);
    echo renderUpdatePage('Wizard gesperrt', '<p class="error">Der Wizard ist gesperrt. Bitte entfernen Sie das <code>/wizard/</code>-Verzeichnis per FTP.</p>');
    exit();
}

if (!WizardHelper::isConfigured()) {
    header('Location: install.php');
    exit();
}

// Konfiguration laden
require_once __DIR__ . '/../api/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error   = '';
$success = '';
$log     = [];

// Verfügbare und angewendete Migrationen ermitteln
$conn = null;
try {
    $conn = WizardHelper::testConnection(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (RuntimeException $e) {
    echo renderUpdatePage('Update-Fehler', '<div class="alert error">' . htmlspecialchars($e->getMessage()) . '</div>');
    exit();
}

$available = WizardHelper::getAvailableMigrations();
$applied   = WizardHelper::getAppliedMigrations($conn, DB_PREFIX);

$pending = array_filter($available, function (string $file) use ($applied): bool {
    return !in_array(WizardHelper::getMigrationVersion($file), $applied, true);
});
$pending = array_values($pending);

// POST: Migrationen ausführen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    WizardHelper::verifyCsrf();

    if (empty($pending)) {
        $success = 'Keine offenen Migrationen vorhanden.';
    } else {
        foreach ($pending as $file) {
            $version     = WizardHelper::getMigrationVersion($file);
            $description = WizardHelper::getMigrationDescription($file);

            $result = WizardHelper::executeSqlFile($conn, $file, DB_PREFIX);
            $log    = array_merge($log, $result['log']);

            if (!$result['success']) {
                $error = "Fehler bei Migration $version. Update wurde gestoppt.";
                break;
            }

            WizardHelper::recordMigration($conn, DB_PREFIX, $version, $description);
            $log[] = "✅ Migration $version abgeschlossen";
        }

        if (!$error) {
            $success = 'Alle Migrationen erfolgreich eingespielt.';
            $pending = []; // Liste leeren
        }
    }
}

$conn->close();

// Seite ausgeben
echo renderUpdatePage('Update-Wizard', renderUpdateContent($pending, $error, $success, $log));


// ---------------------------------------------------------------
// View-Funktionen
// ---------------------------------------------------------------

function renderUpdateContent(array $pending, string $error, string $success, array $log): string
{
    $csrf = WizardHelper::getCsrfToken();
    $html = '';

    if ($error !== '') {
        $html .= '<div class="alert error">' . htmlspecialchars($error) . '</div>';
    }
    if ($success !== '') {
        $html .= '<div class="alert success">' . htmlspecialchars($success) . '</div>';
    }
    if ($log) {
        $html .= WizardHelper::renderLog($log);
    }

    if (empty($pending)) {
        $html .= '<p class="success">✅ Die Datenbank ist auf dem aktuellen Stand.</p>';
        $html .= '<div class="alert warning"><strong>Sicherheitshinweis:</strong> Entfernen Sie das Verzeichnis <code>/wizard/</code> per FTP, sobald Sie fertig sind.</div>';
        return $html;
    }

    $html .= '<h2>Ausstehende Migrationen (' . count($pending) . ')</h2>';
    $html .= '<table>';
    $html .= '<tr><th>Version</th><th>Beschreibung</th></tr>';
    foreach ($pending as $file) {
        $v = htmlspecialchars(WizardHelper::getMigrationVersion($file));
        $d = htmlspecialchars(WizardHelper::getMigrationDescription($file));
        $html .= "<tr><td>$v</td><td>$d</td></tr>";
    }
    $html .= '</table>';

    $html .= <<<HTML
    <form method="post" style="margin-top:1.5rem">
        <input type="hidden" name="csrf_token" value="$csrf">
        <button type="submit" class="primary">Migrationen jetzt einspielen →</button>
    </form>
    HTML;

    return $html;
}

function renderUpdatePage(string $title, string $content): string
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
            table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; font-size: .9rem; }
            th, td { text-align: left; padding: .4rem .6rem; border-bottom: 1px solid #e0e0e0; }
            th { background: #f0f0f0; font-weight: 600; }
            button { padding: .6rem 1.4rem; background: #4a90d9; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
            button.primary { background: #27ae60; }
            button.primary:hover { background: #219150; }
            .alert { padding: .75rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; }
            .alert.error  { background: #fdecea; border-left: 4px solid #e74c3c; color: #c0392b; }
            .alert.success { background: #eafaf1; border-left: 4px solid #27ae60; color: #1e8449; }
            .alert.warning { background: #fef9e7; border-left: 4px solid #f39c12; color: #9a7d0a; }
            p.success { color: #27ae60; margin-bottom: 1rem; font-weight: 600; }
            ul.log { list-style: none; font-size: .8rem; max-height: 200px; overflow-y: auto; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: .5rem; margin-bottom: 1rem; }
            ul.log li.error { color: #c0392b; font-weight: 600; }
            code { background: #f0f0f0; padding: .1rem .3rem; border-radius: 3px; font-size: .9em; }
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
