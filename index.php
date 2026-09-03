<?php
// Wartungsmodus: wird per Deploy-Workflow durch Anlegen/Entfernen von
// .maintenance im Projekt-Root gesteuert. Muss vor jeder anderen Ausgabe
// geprüft werden.
if (is_file(__DIR__ . '/.maintenance')) {
    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/maintenance.html');
    exit;
}

// Weiterleitung zur Hauptanwendung
// Basispfad dynamisch ermitteln, damit die App in jedem Unterverzeichnis funktioniert
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
header('Location: ' . $base . '/frontend/index.html', true, 302);
exit();
