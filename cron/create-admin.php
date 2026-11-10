<?php
/**
 * Notfall/Ersteinrichtung: Admin-Konto anlegen oder Passwort eines Admins neu setzen.
 * Nur per CLI (hPanel-Terminal):  php cron/create-admin.php <benutzername>
 * Das Passwort wird interaktiv abgefragt (nicht in der Shell-Historie).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/AccountService.php';
$user = $argv[1] ?? '';
if (!preg_match('/^[\p{L}0-9 ._-]{3,30}$/u', $user)) { fwrite(STDERR, "Aufruf: php cron/create-admin.php <benutzername>\n"); exit(1); }
fwrite(STDOUT, "Neues Passwort fuer $user: ");
$tty = function_exists('system'); // Hostinger sperrt system(): dann 'php -d disable_functions= ...' nutzen
if ($tty) system('stty -echo'); $pw = trim((string)fgets(STDIN)); if ($tty) system('stty echo'); fwrite(STDOUT, "\n");
if ($err = Security::validatePassword($pw)) { fwrite(STDERR, implode('. ', $err) . "\n"); exit(1); }
$pdo = LmoDatabase::getInstance(); new AccountService();
$st = $pdo->prepare("SELECT id FROM users WHERE username = ?"); $st->execute([$user]);
if ($id = $st->fetchColumn()) {
    $pdo->prepare("UPDATE users SET password_hash = ?, role = 'admin', active = 1, status = 'active' WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
    echo "Passwort fuer Admin '$user' gesetzt.\n";
} else {
    $pdo->prepare("INSERT INTO users (username, password_hash, display_name, role, active, status) VALUES (?, ?, ?, 'admin', 1, 'active')")->execute([$user, password_hash($pw, PASSWORD_DEFAULT), $user]);
    echo "Admin '$user' angelegt.\n";
}
