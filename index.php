<?php
// Weiterleitung zur Hauptanwendung
// Basispfad dynamisch ermitteln, damit die App in jedem Unterverzeichnis funktioniert
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
header('Location: ' . $base . '/frontend/index.html', true, 302);
exit();
