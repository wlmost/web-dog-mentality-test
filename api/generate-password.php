<?php
declare(strict_types=1);
/**
 * Passwort-Hash Generator
 * Verwendung: Öffne im Browser oder führe in CLI aus
 */

$password = 'admin123';
$hash = password_hash($password, PASSWORD_BCRYPT);

header('Content-Type: text/plain; charset=utf-8');

echo "Passwort: $password\n";
echo "Hash: $hash\n\n";

echo "SQL UPDATE Befehl:\n";
require_once __DIR__ . '/config.php';
echo "UPDATE " . tbl('auth_users') . " SET password_hash = '$hash' WHERE username = 'admin';\n";
